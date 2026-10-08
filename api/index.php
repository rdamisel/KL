<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/kl_helpers.php';

$token = '';
if (!empty($_GET['token'])) {
    $token = trim($_GET['token']);
    setcookie('kl_token', $token, time() + 60*60*24*30, '/');
} elseif (!empty($_COOKIE['kl_token'])) {
    $token = $_COOKIE['kl_token'];
}
$teacherId = $token ? kl_resolve_teacher_id($token) : '';

$date      = !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$schedules = [];
$error     = null;

// Determine attendance status from teacher_notes (authoritative) with fallback
// to the reservation's own status field. Note bodies are plain HTML fragments
// like "<p>Done</p>", "<p>Late</p>", "<p>Absent</p>".
function resolveAttendanceStatus($notes, string $fallback): string {
    if (empty($notes) || !is_array($notes)) return $fallback;
    usort($notes, fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
    $last = end($notes);
    $body = strtolower(trim(strip_tags($last['body'] ?? '')));
    if ($body === '') return $fallback;
    if (str_contains($body, 'late'))   return 'late';
    if (str_contains($body, 'absent')) return 'no show';
    if (str_contains($body, 'done'))   return 'attended';
    return $fallback;
}

if ($token && !$teacherId) {
    $error = "Couldn't detect your Teacher ID automatically. Open the Daily View and enter it in the Teacher ID box.";
} elseif ($token) {
    $url = "https://api.kredo-learning.com/v2/teachers/{$teacherId}/schedules?start_date={$date}&end_date={$date}";
    $ch  = curl_init($url);
    $insecureTest = (!empty($_GET['insecure']) && $_GET['insecure'] === '1');
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Accept: application/json","Authorization: Bearer {$token}"],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => !$insecureTest,
        CURLOPT_SSL_VERIFYHOST => $insecureTest ? 0 : 2,
    ];
    // The host's configured default CA bundle path (e.g. /etc/ssl/cacert.pem) may not
    // exist in this environment (curl error #77). If a cacert.pem is placed next to
    // this script, use it explicitly instead of relying on the broken host default.
    $caBundle = __DIR__ . '/cacert.pem';
    $caFound  = is_file($caBundle);
    if ($caFound && !$insecureTest) {
        $curlOpts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $curlOpts);
    $res      = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    if ($code === 200) {
        $body = json_decode($res, true);
        $raw  = $body['data'] ?? $body['schedules'] ?? $body ?? [];
        usort($raw, fn($a,$b) => strcmp($a['start'],$b['start']));
        foreach ($raw as $s) {
            $st = (new DateTime($s['start'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
            $en = (new DateTime($s['end'],   new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
            $r  = $s['reservation'] ?? null;
            $rv = $r['reservee'] ?? null;
            $fallbackStatus = $r['status'] ?? ($s['is_reserved'] ? 'reserved' : 'open');
            $status = $r ? resolveAttendanceStatus($r['teacher_notes'] ?? null, $fallbackStatus) : $fallbackStatus;
            if ($status === 'cancelled') continue;
            $schedules[] = [
                'time'      => $st->format('H:i').' – '.$en->format('H:i'),
                'timeStart' => $st->format('H:i'),
                'tsRaw'     => $st->getTimestamp(),
                'teRaw'     => $en->getTimestamp(),
                'hour'      => (int)$st->format('G'),
                'nameEn'    => $rv['name_en'] ?? null,
                'nameJa'    => $rv['name_ja'] ?? null,
                'email'     => $rv['email']   ?? '-',
                'status'    => $status,
                'isOpen'    => !$s['is_reserved'],
            ];
        }
    } elseif ($code === 0) {
        // curl never got a response at all — this is a connection-level failure,
        // NOT an expired token. Show the real curl error instead of guessing.
        if ($curlErrno === 77) {
            $diag = $caFound
                ? "cacert.pem was found at {$caBundle} but curl still rejected it — file may be corrupted/truncated, or this host's curl/OpenSSL build ignores CURLOPT_CAINFO."
                : "cacert.pem was NOT found at {$caBundle} — check the file was uploaded to this exact folder (script dir: " . __DIR__ . ").";
            $error = "SSL cert bundle missing on this host (curl #77). {$diag}";
        } else {
            $error = $curlErr
                ? "Connection failed (curl #{$curlErrno}): {$curlErr}"
                : "Connection failed — could not reach the Kredo API (no HTTP response received).";
        }
    } elseif ($code === 401 || $code === 403) {
        $error = "HTTP {$code} — token expired or invalid.";
    } else {
        $error = "HTTP {$code} — request failed.";
    }
}


$am    = array_values(array_filter($schedules, fn($s)=>$s['hour']>=6  && $s['hour']<=12));
$pm    = array_values(array_filter($schedules, fn($s)=>$s['hour']>=13 && $s['hour']<18));
$night = array_values(array_filter($schedules, fn($s)=>$s['hour']>=18));

// Date label WITHOUT day name (e.g. "June 1, 2025" not "Sunday, June 1, 2025")
$dateLabel = date('F j, Y', strtotime($date));
$dayName   = date('l', strtotime($date));
$isToday   = ($date === date('Y-m-d'));
$showSettings = (!$token || $error);
$nowTs     = time();

// Day of week: 0=Sun, 1=Mon ... 6=Sat
$dow = (int)date('w', strtotime($date));
$isWeekend  = ($dow === 0 || $dow === 6); // Sat or Sun
$isWeekday  = !$isWeekend;               // Mon–Fri

function getPayPeriod(string $d): array {
    $dt=(new DateTime($d)); $day=(int)$dt->format('j'); $y=(int)$dt->format('Y'); $m=(int)$dt->format('n');
    $mp=str_pad($m,2,'0',STR_PAD_LEFT);
    if ($day>=6&&$day<=20)  { $s=new DateTime("{$y}-{$mp}-06"); $e=new DateTime("{$y}-{$mp}-20"); $lbl='30th Cut-off'; }
    elseif ($day>=21)       { $s=new DateTime("{$y}-{$mp}-21"); $nx=(clone $s)->modify('first day of next month'); $e=new DateTime($nx->format('Y-m').'-05'); $lbl='15th Cut-off'; }
    else                    { $pv=(new DateTime("{$y}-{$m}-01"))->modify('-1 month'); $s=new DateTime($pv->format('Y-m').'-21'); $e=new DateTime("{$y}-{$mp}-05"); $lbl='15th Cut-off'; }
    return [$s,$e,$lbl];
}
[$ppS,$ppE,$ppLbl] = getPayPeriod($date);
$ppRange    = $ppS->format('M j').' – '.$ppE->format('M j');
$ppDaysLeft = max(0,(int)(new DateTime('today'))->diff($ppE)->format('%r%a'));
$ppTotalDays = max(1,(int)$ppS->diff($ppE)->format('%a'));
$ppElapsedPct = max(0, min(100, round((($ppTotalDays - $ppDaysLeft) / $ppTotalDays) * 100)));

$total     = count($schedules);
$attended  = count(array_filter($schedules, fn($s)=>$s['status']==='attended'));
$absent    = count(array_filter($schedules, fn($s)=>$s['status']==='no show'));
$late      = count(array_filter($schedules, fn($s)=>$s['status']==='late'));
$cancelled = 0;
$nobooking = count(array_filter($schedules, fn($s)=>$s['isOpen']));
$booked    = $total - $nobooking;

$bookedPct   = $total > 0 ? round(($booked / $total) * 100) : 0;
$attendedPct = $booked > 0 ? round(($attended / $booked) * 100) : 0;
$absentPct   = $booked > 0 ? round(($absent / $booked) * 100) : 0;
$openPct     = $total > 0 ? round(($nobooking / $total) * 100) : 0;

$notifications = [];
$inProgressTeRaw = null;

if ($token && !$error && $schedules) {
    if ($isToday) {
        foreach ($schedules as $s) {
            if (!$s['isOpen'] && $s['tsRaw'] <= $nowTs && $nowTs < $s['teRaw']) {
                $inProgressTeRaw = $s['teRaw'];
                $notifications[] = [
                    'icon'  => 'bi-play-circle-fill',
                    'color' => 'green',
                    'title' => 'Class In Progress',
                    'body'  => ($s['nameEn']??'Unknown').' · '.$s['time'],
                    'teRaw' => $s['teRaw'],
                ];
                break;
            }
        }
        foreach ($schedules as $s) {
            if (!$s['isOpen'] && $s['tsRaw'] > $nowTs) {
                $mins = (int)(($s['tsRaw']-$nowTs)/60);
                $hrs  = intdiv($mins,60); $rem=$mins%60;
                $cd   = $hrs>0?"{$hrs}h {$rem}m":"{$mins}m";
                $notifications[] = ['icon'=>'bi-alarm','color'=>'blue',
                    'title'=>'Next class in '.$cd,
                    'body' =>($s['nameEn']??'Unknown').' · '.$s['timeStart'],
                    'tsRaw'=> $s['tsRaw']];
                break;
            }
        }
    }
    foreach (array_filter($schedules, fn($s)=>$s['status']==='no show') as $s) {
        $notifications[] = ['icon'=>'bi-person-x-fill','color'=>'yellow',
            'title'=>'Absent',
            'body' =>($s['nameEn']??'Unknown').' · '.$s['timeStart']];
    }
    foreach (array_filter($schedules, fn($s)=>$s['status']==='late') as $s) {
        $notifications[] = ['icon'=>'bi-clock-fill','color'=>'orange',
            'title'=>'Late',
            'body' =>($s['nameEn']??'Unknown').' · '.$s['timeStart']];
    }
    foreach (array_filter($schedules, fn($s)=>!$s['isOpen']&&$s['status']==='reserved'&&(!$isToday||$s['tsRaw']>$nowTs)) as $s) {
        $notifications[] = ['icon'=>'bi-calendar-plus-fill','color'=>'teal',
            'title'=>'New Booking',
            'body' =>($s['nameEn']??'Unknown').' · '.$s['timeStart']];
    }
    if ($ppDaysLeft <= 3) {
        $notifications[] = ['icon'=>'bi-scissors','color'=>'purple',
            'title'=>'Cutoff in '.$ppDaysLeft.' day'.($ppDaysLeft===1?'':'s'),
            'body' =>$ppLbl.' · ends '.$ppE->format('M j')];
    }
}

// ── UPDATED: badge() now accepts tsRaw to detect past open slots ──
$AVATAR_COLORS = [
    ['bg'=>'#ffffff','fg'=>'#000000'],
];
function avatarFor(int $i): array {
    global $AVATAR_COLORS;
    $c = $AVATAR_COLORS[$i % count($AVATAR_COLORS)];
    return ['initial'=>(string)($i + 1), 'bg'=>$c['bg'], 'fg'=>$c['fg']];
}

function badge(string $status, bool $isOpen, int $tsRaw = 0): string {
    if ($isOpen) {
        $label = ($tsRaw > 0 && $tsRaw < time()) ? 'Not Reserved' : 'No Booking';
        return '<span class="badge open">' . $label . '</span>';
    }
    if ($status==='attended') return '<span class="badge att">Attended</span>';
    if ($status==='late')     return '<span class="badge late">Late</span>';
    if ($status==='no show')  return '<span class="badge abs">Absent</span>';
    return '<span class="badge res">Reserved</span>';
}

/**
 * Apply the same no-booking filter used by the slack text builder,
 * returned as plain slot data so the UI can render a real list (not just text).
 */
function slackSectionSlots(array $slots, bool $filterNoBooking = false): array {
    if ($filterNoBooking) {
        $slots = array_values(array_filter($slots, fn($s) => !$s['isOpen']));
    }
    return array_values($slots);
}

/**
 * Build slack text for a single section.
 * $filterNoBooking: if true, slots with isOpen=true are excluded (weekdays).
 */
function slackSection(string $label, array $slots, bool $filterNoBooking = false): string {
    $slots = slackSectionSlots($slots, $filterNoBooking);
    $out = "{$label}\n";
    if (!$slots) {
        $out .= "   (none)\n";
    } else {
        foreach ($slots as $i => $s) {
            $tag = $s['status']==='no show' ? ' [Absent]' : ($s['status']==='late' ? ' [Late]' : '');
            $nm  = $s['nameEn'] ?? 'NO BOOKING';
            $out .= '   '.($i+1).".   {$s['timeStart']} - {$nm}{$tag}\n";
        }
    }
    return $out;
}

/**
 * Build the per-clipboard slack blocks.
 * Returns array of ['label'=>string, 'text'=>string, 'sections'=>[['label'=>string,'slots'=>array], ...]]
 * 'text' is what gets copied to the clipboard; 'sections' is structured data for the on-screen list view.
 *
 * Mon–Fri: AM block + Night block (+ PM block only if $pm is non-empty)
 *          No Booking slots are excluded from the list.
 * Sat–Sun: single clipboard with AM + PM combined (Night hidden)
 *          All slots (including No Booking) are shown.
 */
function buildSlackBlocks(string $dl, array $am, array $pm, array $night, bool $isWeekday): array {
    $header = "DATE: {$dl}\n\n";
    $blocks = [];

    if ($isWeekday) {
        // Morning clipboard — exclude no-booking slots
        $blocks[] = [
            'label'    => 'Morning Class',
            'text'     => $header . slackSection('AM CLASS', $am, true),
            'sections' => [['label'=>'AM CLASS', 'slots'=>slackSectionSlots($am, true)]],
        ];
        // PM clipboard — only shown if there are booked PM slots
        $pmBooked = slackSectionSlots($pm, true);
        if (!empty($pmBooked)) {
            $blocks[] = [
                'label'    => 'PM Class',
                'text'     => $header . slackSection('PM CLASS', $pm, true),
                'sections' => [['label'=>'PM CLASS', 'slots'=>$pmBooked]],
            ];
        }
        // Night clipboard — exclude no-booking slots
        $blocks[] = [
            'label'    => 'Night Class',
            'text'     => $header . slackSection('NIGHT CLASS', $night, true),
            'sections' => [['label'=>'NIGHT CLASS', 'slots'=>slackSectionSlots($night, true)]],
        ];
    } else {
        // Weekend: single clipboard — AM + PM combined, all slots shown, Night hidden
        $blocks[] = [
            'label'    => 'Schedule',
            'text'     => $header . slackSection('AM CLASS', $am, false) . "\n" . slackSection('PM CLASS', $pm, false),
            'sections' => [
                ['label'=>'AM CLASS', 'slots'=>slackSectionSlots($am, false)],
                ['label'=>'PM CLASS', 'slots'=>slackSectionSlots($pm, false)],
            ],
        ];
    }

    return $blocks;
}

$slackBlocks = ($token && !$error)
    ? buildSlackBlocks($dateLabel, $am, $pm, $night, $isWeekday)
    : [];

$otUrl      = 'overtime.php?token='.urlencode($token).'&date='.urlencode($date);
$weeklyUrl  = 'weekly.php?token='.urlencode($token).'&date='.urlencode($date);
$monthlyUrl = 'monthly.php?token='.urlencode($token);
$notifUrl   = 'notifications.php'.($token ? '?token='.urlencode($token) : '');
$notifCount = count($notifications);

if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'stats'       => ['total'=>$total,'attended'=>$attended,'absent'=>$absent,'late'=>$late,'cancelled'=>0,'nobooking'=>$nobooking,'booked'=>$booked],
        'am'          => $am,
        'pm'          => $pm,
        'night'       => $night,
        'slackBlocks' => $slackBlocks,
        'notifs'      => $notifications,
        'notifCount'  => $notifCount,
        'error'       => $error,
        'ts'          => time(),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Schedule</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%236366f1'/%3E%3Crect x='7' y='5' width='18' height='22' rx='3' fill='%23fff'/%3E%3Crect x='10' y='3' width='3' height='5' rx='1.5' fill='%236366f1'/%3E%3Crect x='19' y='3' width='3' height='5' rx='1.5' fill='%236366f1'/%3E%3Crect x='10' y='13' width='12' height='1.5' rx='.75' fill='%236366f1'/%3E%3Crect x='10' y='17' width='8' height='1.5' rx='.75' fill='%236366f1'/%3E%3Crect x='10' y='21' width='5' height='1.5' rx='.75' fill='%236366f1'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#ffffff;
  --card:#ffffff;
  --ink:#1a1a1a;
  --ink-soft:#1a1a1a;
  --muted:#767676;
  --line:#e8e8e8;
  --accent:#12805c;
  --accent-soft:#e8f3ef;
  --accent-soft-hover:#d6f5e9;
  --c-blue:#2563eb;
  --c-green:#16a34a;
  --c-amber:#d97706;
  --c-orange:#ea580c;
  --c-red:#dc2626;
  --c-teal:#0d9488;
  --c-purple:#7c3aed;
  --c-gray:#6b7280;
  --radius-lg:0px;
  --radius-md:0px;
  --shadow-card:none;
}
html{overflow-x:hidden;width:100%}
body{
  font-family:'Inter',system-ui,-apple-system,sans-serif;
  background:var(--bg);
  color:var(--ink);
  font-size:14px;
  line-height:1.55;
  -webkit-font-smoothing:antialiased;
  letter-spacing:-.01em;
  overflow-x:hidden;
  max-width:100vw;
}

/* ══════════════ Topbar ══════════════ */
.topbar{
  background:#fff;
  border-bottom:1px solid var(--line);
  padding:.85rem 1.5rem;
  display:flex;
  align-items:center;
  gap:1rem;
  flex-wrap:wrap;
  position:sticky;
  top:0;
  z-index:50;
}
.brand{display:flex;align-items:center;gap:.55rem;flex-shrink:0}
.brand-mark{
  width:32px;height:32px;border-radius:8px;
  background:#fff;
  border:1px solid var(--line);
  display:flex;align-items:center;justify-content:center;
  position:relative;
  flex-shrink:0;
  color:var(--ink);
  font-size:1rem;
}
.brand-name{font-size:.92rem;font-weight:700;white-space:nowrap;letter-spacing:-.01em}
.brand-name b{font-weight:700}

.pillnav{
  display:flex;
  gap:.15rem;
  background:transparent;
  padding:0;
  border-radius:0;
  overflow-x:auto;
  scrollbar-width:none;
  flex-shrink:1;
  min-width:0;
  max-width:100%;
}
.pillnav::-webkit-scrollbar{display:none}
.pillnav a{
  padding:.42rem 1rem;
  border-radius:8px;
  font-size:.78rem;
  font-weight:600;
  color:var(--muted);
  text-decoration:none;
  white-space:nowrap;
  transition:color .15s,background .15s;
}
.pillnav a:hover{color:var(--ink);background:#f7f7f7}
.pillnav a.active{color:var(--accent);background:var(--accent-soft)}
.pillnav a.has-badge{position:relative}
.pill-badge{display:none;position:absolute;top:.05rem;right:0;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#dc2626;color:#fff;font-size:.62rem;font-weight:800;line-height:16px;text-align:center;box-shadow:0 0 0 2px #fff}

.topbar-right{display:flex;align-items:center;gap:.5rem;margin-left:auto;flex-wrap:wrap;justify-content:flex-end;max-width:100%}
.status-chip{
  display:flex;align-items:center;gap:.4rem;
  background:#fafafa;
  border-radius:999px;
  padding:.36rem .8rem .36rem .6rem;
  font-size:.68rem;
  font-weight:600;
  color:var(--muted);
  white-space:nowrap;
}
.status-dot{width:7px;height:7px;border-radius:50%;background:var(--muted);flex-shrink:0}
.status-chip.ok .status-dot{background:var(--accent)}
.status-chip.ok::after{content:' OK'}
.status-chip.err .status-dot{background:var(--ink)}
.status-chip.err::after{content:' Error'}
.live-clock{
  display:flex;
  align-items:baseline;
  gap:.25rem;
  background:#fafafa;
  border-radius:999px;
  padding:.34rem .85rem;
  line-height:1;
}
.lc-time{font-size:.82rem;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums;letter-spacing:-.01em}
.lc-ampm{font-size:.55rem;font-weight:700;color:var(--muted);letter-spacing:.06em;text-transform:uppercase}
.refresh-dot{width:7px;height:7px;border-radius:50%;background:#d4d4d4;display:inline-block;transition:background .3s}
.refresh-dot.spinning{background:var(--accent);animation:pulse 1s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.icon-btn{
  width:32px;height:32px;flex-shrink:0;
  border:none;
  border-radius:50%;
  background:#fafafa;
  color:var(--ink);
  font-size:.82rem;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;
  transition:background .15s,color .15s;
}
.icon-btn:hover{background:#eee}

/* ══════════════ Settings sheet ══════════════ */
.settings-accordion{
  max-height:0;
  overflow:hidden;
  background:#fff;
  border-bottom:1px solid var(--line);
  transition:max-height .3s ease;
}
.settings-accordion.open{max-height:230px}
.settings-accordion-inner{padding:1rem 1.5rem}

.sheet-backdrop{
  display:none;
  position:fixed;inset:0;
  background:rgba(0,0,0,.35);
  z-index:150;
  opacity:0;
  pointer-events:none;
  transition:opacity .25s;
}
.sheet-backdrop.show{opacity:1;pointer-events:auto}
.sheet-handle{display:none}
.sheet-header{display:none}
.sheet-close{
  background:#f5f5f5;border:none;width:30px;height:30px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;color:var(--ink);cursor:pointer;
}

/* ══════════════ Page shell ══════════════ */
.shell{max-width:1200px;margin:0 auto;padding:0 1.5rem;width:100%}

/* ── Hero row ── */
.hero-row{
  display:flex;
  gap:1.5rem;
  align-items:flex-start;
  padding:1.75rem 0 1.25rem;
  flex-wrap:wrap;
}
.hero-left{flex:1;min-width:280px;max-width:100%}
.eyebrow{
  font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;
  color:var(--muted);margin-bottom:.5rem;
}
.mega-title{
  font-size:clamp(1.9rem,4.4vw,2.9rem);
  font-weight:700;
  letter-spacing:-.02em;
  line-height:1.1;
  color:var(--ink);
  margin-bottom:1.1rem;
}
/* ── Date navigation (now lives in the right sidebar) ── */
.day-nav{display:flex;align-items:center;gap:.5rem;background:var(--card);border-radius:14px;border:1px solid var(--line);padding:.6rem .7rem}
.btn-nav{
  width:32px;height:32px;flex-shrink:0;border:none;border-radius:50%;
  background:#fafafa;color:var(--ink);font-size:.72rem;
  display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s;
}
.btn-nav:hover{background:#eee}
.day-chip{
  flex:1;text-align:center;
  font-size:.72rem;font-weight:600;color:var(--ink-soft);background:#fafafa;
  border-radius:999px;padding:.4rem .9rem;
}
.btn-today{
  font-size:.68rem;font-weight:700;color:var(--accent);background:var(--accent-soft);border:none;border-radius:999px;
  padding:.42rem .95rem;cursor:pointer;white-space:nowrap;transition:background .15s;
}
.btn-today:hover{background:var(--accent-soft-hover)}

.filter-pills{display:flex;gap:.4rem;flex-wrap:wrap;justify-content:center}
.filter-pill{
  font-size:.66rem;font-weight:600;color:var(--ink-soft);background:#fafafa;
  border-radius:999px;padding:.36rem .8rem;white-space:nowrap;
}

/* ══════════════ Layout ══════════════ */
.layout{display:flex;gap:1.6rem;align-items:flex-start;padding-bottom:2rem}
.col-main{flex:1;min-width:0}
.col-notif{width:300px;flex-shrink:0;position:sticky;top:4.6rem;display:flex;flex-direction:column;gap:.9rem;max-width:100%}

@media(min-width:741px){
  .stat-rich{transition:background .15s}
  .stat-rich:hover{background:#fafafa}
  .panel-card{transition:none}
  .slot:not(.open-slot):hover{background:#fafafa}
  .row-item:hover{background:#fafafa}
}

@media(max-width:900px){
  .col-notif{width:100%;position:static}
}
@media(max-width:740px){
  .shell{padding:0 .8rem}
  .layout{flex-direction:column;padding-bottom:5rem}
  .snap-toast{bottom:calc(4.6rem + env(safe-area-inset-bottom,0px))}

  .sheet-backdrop{display:block}
  .settings-accordion{
    position:fixed;left:0;right:0;bottom:0;top:auto;max-height:80vh;border-radius:18px 18px 0 0;
    border-bottom:none;box-shadow:0 -8px 24px rgba(0,0,0,.12);transform:translateY(100%);
    transition:transform .3s ease;padding-bottom:env(safe-area-inset-bottom,0px);z-index:200;
  }
  .settings-accordion.open{transform:translateY(0);overflow-y:auto}
  .settings-accordion-inner{padding:.3rem 1.1rem 1.1rem}
  .sheet-handle{display:block;width:36px;height:4px;border-radius:3px;background:#ddd;margin:.65rem auto .3rem}
  .sheet-header{display:flex;align-items:center;justify-content:space-between;padding:.1rem 1.1rem .6rem;font-size:.85rem;font-weight:700;color:var(--ink)}

  .tabbar{
    display:flex;position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--line);
    border-radius:18px 18px 0 0;box-shadow:0 -4px 14px rgba(0,0,0,.05);z-index:120;
    padding-bottom:env(safe-area-inset-bottom,0px);
  }
}
@media(min-width:741px){.tabbar{display:none}.sheet-backdrop{display:none!important}}

@media(max-width:740px){
  .stats{grid-template-columns:repeat(2,1fr)}
  .hero-row{padding-top:1.1rem}
  .copy-block-head{padding:.8rem .9rem}
  .copy-item{padding:.4rem .6rem;gap:.5rem}
  .copy-item .name{white-space:normal}

  .hero-left{text-align:center}
  .filter-pills{justify-content:center}

  .status-chip,.live-clock{display:none}
}

@media(max-width:600px){
  .topbar{padding:.55rem .7rem;gap:.4rem}
  .brand-name{display:none}
  .pillnav a{padding:.4rem .75rem;font-size:.72rem}
  .live-clock{padding:.28rem .65rem}
  .mega-title{font-size:1.9rem}
  .stat-rich{padding:.85rem}
}

@media(max-width:420px){
  .stats{grid-template-columns:1fr}
  .filter-pills{gap:.3rem}
  .filter-pill{padding:.3rem .6rem;font-size:.62rem}
  .copy-block-head{flex-direction:column;align-items:stretch}
  .copy-btn{justify-content:center}
  .copy-item .idx{display:none}
}

/* ══════════════ Stat cards ══════════════ */
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:.8rem;margin-bottom:.9rem;min-width:0}
.stat-rich{
  background:#fafafa;border-radius:14px;padding:1.05rem .8rem 1rem;
  min-width:0;text-align:center;
}
.sr-big{font-size:clamp(1.6rem,3.2vw,2rem);font-weight:700;letter-spacing:-.02em;margin-bottom:.3rem;color:var(--ink)}
.sr-big.blue{color:var(--c-blue)}.sr-big.green{color:var(--c-green)}.sr-big.yellow{color:var(--c-amber)}.sr-big.orange{color:var(--c-orange)}.sr-big.gray{color:var(--c-gray)}
.sr-lbl-main{font-size:.6rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}

/* ══════════════ Panels (AM/PM/Night) ══════════════ */
.panel-card{
  background:var(--card);border-radius:14px;box-shadow:none;border:1px solid var(--line);
  margin-bottom:.85rem;overflow:hidden;
}
.panel-head{
  display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;border-bottom:1px solid var(--line);
  gap:.5rem;flex-wrap:wrap;
}
.panel-title{display:flex;align-items:center;gap:.45rem;font-size:.8rem;font-weight:700;color:var(--ink);min-width:0}
.panel-title i{font-size:.85rem;color:var(--accent)}
.panel-count{
  font-size:.65rem;font-weight:600;color:var(--muted);background:#fafafa;border-radius:999px;padding:.24rem .65rem;
  white-space:nowrap;flex-shrink:0;
}

/* ── Slot row ── */
.slot{display:flex;align-items:center;gap:.65rem;padding:.7rem 1.1rem;border-bottom:1px solid #f2f2f2;transition:background .2s}
.slot:last-child{border-bottom:none}
.slot.open-slot{opacity:.5}
.slot.active-slot{background:var(--accent-soft);border-left:3px solid var(--accent);padding-left:calc(1.1rem - 3px)}
.slot-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;flex-shrink:0;background:#fafafa;color:var(--ink)}
.slot-time{font-variant-numeric:tabular-nums;font-weight:700;color:var(--accent);letter-spacing:-.01em}
.slot-info{flex:1;min-width:0}
.slot-name{font-weight:600;font-size:.87rem;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.slot-meta{font-size:.72rem;color:var(--muted);margin-top:.12rem;display:flex;align-items:center;gap:.32rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.slot-meta .dot{opacity:.5}
.slot-was{font-size:.63rem;color:var(--muted);font-style:italic;margin-top:.2rem;display:flex;align-items:center;gap:.25rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.slot-was i{flex-shrink:0}
.slot-countdown{
  display:inline-flex;align-items:center;gap:.28rem;font-size:.64rem;font-weight:700;color:var(--accent);background:var(--accent-soft);
  border-radius:999px;padding:.18rem .52rem;margin-top:.22rem;font-variant-numeric:tabular-nums;letter-spacing:.01em;width:fit-content;
}
.slot-countdown i{font-size:.62rem}

/* ── Badges ── */
.badge{font-size:.62rem;font-weight:700;padding:.2rem .6rem;border-radius:999px;white-space:nowrap;flex-shrink:0;letter-spacing:.01em}
.badge.att{background:#fff;color:var(--c-green);border:1px solid #e8e8e8}
.badge.abs{background:#fff;color:var(--c-red);border:1px solid #e8e8e8}
.badge.late{background:#fff;color:var(--c-amber);border:1px dashed #e0c090}
.badge.res{background:#fff;color:var(--c-blue);border:1px solid #e8e8e8}
.badge.open{background:#fff;color:var(--muted);border:1px solid #e8e8e8}
.empty-sec{padding:1.4rem .9rem;color:var(--muted);font-size:.8rem;text-align:center}

/* ══════════════ Slack quick-copy rows ══════════════ */
.copy-section{display:flex;flex-direction:column;gap:.85rem;margin-bottom:.85rem}
.copy-section-head{font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:.4rem;padding:0 .1rem}
.copy-section-head i{color:var(--accent);font-size:.8rem}

.copy-block{background:var(--card);border-radius:14px;box-shadow:none;border:1px solid var(--line);overflow:hidden}
.copy-block-head{display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.9rem 1.1rem;border-bottom:1px solid var(--line);flex-wrap:wrap}
.copy-block-label{display:flex;align-items:center;gap:.45rem;font-size:.82rem;font-weight:700;color:var(--ink)}
.copy-block-label i{color:var(--accent);font-size:.82rem}
.copy-btn{
  display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;color:var(--accent);background:none;
  border:none;border-radius:0;padding:0;cursor:pointer;flex-shrink:0;transition:opacity .15s;
}
.copy-btn:hover{opacity:.7;text-decoration:underline}
.copy-btn:active{opacity:.5}
.copy-btn.copied{color:var(--c-green)}

.copy-block-list{padding:.35rem .3rem .55rem}
.copy-sub-label{font-size:.6rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);padding:.55rem .85rem .25rem}
.copy-item{display:flex;align-items:center;gap:.6rem;padding:.42rem .85rem;font-size:.79rem;border-radius:9px}
.copy-item:hover{background:#fafafa}
.copy-item .idx{color:var(--muted);font-weight:600;font-size:.7rem;width:1.1rem;flex-shrink:0;text-align:right}
.copy-item .time{font-variant-numeric:tabular-nums;font-weight:700;color:var(--ink);flex-shrink:0;letter-spacing:-.01em}
.copy-item .name{flex:1;min-width:0;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.copy-item .tag{font-size:.68rem;font-weight:700;color:var(--c-red);background:none;border-radius:0;padding:0;flex-shrink:0}
.copy-item .tag.late{color:var(--c-amber);background:none;border:none}
.copy-empty{padding:.42rem .85rem;color:var(--muted);font-size:.78rem;font-style:italic}
.slack-textarea{display:none}

/* ══════════════ Alert ══════════════ */
.alert{
  padding:.85rem 1.1rem;border-radius:12px;font-size:.84rem;font-weight:500;color:var(--ink);
  background:#fafafa;border:1px solid var(--line);margin-bottom:.9rem;
}

/* ══════════════ Notification sidebar ══════════════ */
.notif-panel,.hist-panel{
  background:var(--card);border-radius:14px;box-shadow:none;border:1px solid var(--line);overflow:hidden;
}
.notif-head,.hist-head{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.1rem;border-bottom:1px solid var(--line)}
.notif-head h3,.hist-head h3{font-size:.82rem;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:.4rem}
.notif-count{background:var(--ink);color:#fff;font-size:.58rem;font-weight:700;padding:.1rem .45rem;border-radius:999px}
.notif-list{padding:.5rem .6rem}
.notif-empty{text-align:center;padding:1.6rem .5rem;color:var(--muted);font-size:.78rem}
.notif-empty i{display:block;font-size:1.5rem;margin-bottom:.4rem;color:var(--accent)}
.ni{display:flex;align-items:flex-start;gap:.6rem;padding:.6rem .5rem;border-radius:12px;margin-bottom:.15rem;position:relative}
.ni:hover{background:#fafafa}
.ni-icon{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;background:#fafafa;color:var(--ink)}
.ni-icon.blue{color:var(--c-blue)}
.ni-icon.green{color:var(--c-green)}
.ni-icon.yellow{color:var(--c-amber)}
.ni-icon.red{color:var(--c-red)}
.ni-icon.teal{color:var(--c-teal)}
.ni-icon.purple{color:var(--c-purple)}
.ni-icon.orange{color:var(--c-orange)}
.ni-body{flex:1;min-width:0}
.ni-title{font-size:.78rem;font-weight:700;color:var(--ink);line-height:1.25}
.ni-sub{font-size:.68rem;color:var(--muted);margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ni-countdown{
  font-size:.64rem;font-weight:700;color:var(--accent);background:var(--accent-soft);border-radius:999px;padding:.12rem .44rem;
  display:inline-block;margin-top:.25rem;font-variant-numeric:tabular-nums;letter-spacing:.02em;
}
.ni-group-label{font-size:.58rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#a0a0a0;padding:.55rem .5rem .2rem}

/* ── History panel ── */
.hist-list{padding:.5rem .6rem;max-height:260px;overflow-y:auto}
.hist-empty{text-align:center;padding:1.2rem .5rem;color:var(--muted);font-size:.75rem}
.hist-empty i{display:block;font-size:1.3rem;margin-bottom:.35rem;color:var(--muted)}
.hi{display:flex;align-items:flex-start;gap:.5rem;padding:.44rem .45rem;border-radius:9px}
.hi:hover{background:#fafafa}
.hi-time{font-variant-numeric:tabular-nums;font-size:.68rem;font-weight:700;color:var(--accent);white-space:nowrap;min-width:44px;padding-top:.05rem;flex-shrink:0}
.hi-body{flex:1;min-width:0}
.hi-name{font-size:.76rem;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hi-tag{font-size:.62rem;margin-top:.1rem;font-weight:600}
.hi-tag.cancelled{color:var(--ink);text-decoration:underline}
.hi-tag.replaced{color:var(--ink);font-style:italic}
.hi-tag.nobooking{color:var(--muted)}
.hi-date{display:inline-block;font-size:.58rem;font-weight:700;background:#fafafa;color:var(--ink-soft);border-radius:4px;padding:.08rem .3rem;margin-right:.25rem;letter-spacing:.02em}
#histMeta{font-size:.65rem;color:var(--muted);font-weight:600}

/* ══════════════ Bottom app tab bar (mobile) ══════════════ */
.tabbar{display:none}
.tab-btn{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.15rem;padding:.5rem 0 .4rem;background:none;border:none;color:#9ca3af;font-size:.62rem;font-weight:600;letter-spacing:.01em;cursor:pointer}
.tab-btn i{font-size:1.2rem}
.tab-btn.active{color:var(--ink)}
.tab-btn.active .tab-icon-wrap{background:var(--accent-soft);color:var(--accent)}
.tab-icon-wrap{position:relative;display:inline-flex;align-items:center;justify-content:center;width:34px;height:28px;border-radius:14px;transition:background .15s,color .15s}
.tab-badge{position:absolute;top:-4px;right:-9px;background:var(--ink);color:#fff;font-size:.55rem;font-weight:700;min-width:15px;height:15px;border-radius:50%;display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1}

/* ══════════════ Settings form ══════════════ */
.frow{display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end}
.field{flex:1;min-width:140px}
.field label{display:block;font-size:.65rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:.35rem}
.field input{
  width:100%;border:1px solid var(--line);border-radius:10px;padding:.44rem .75rem;font-size:.84rem;
  font-family:'Inter',sans-serif;color:var(--ink);outline:none;background:#fafafa;transition:border-color .15s,background .15s;
}
.field input:focus{border-color:var(--accent);background:#fff}
.btn{padding:.5rem 1.05rem;border-radius:999px;border:none;font-size:.82rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;white-space:nowrap;transition:background .15s,opacity .15s,transform .1s}
.btn:active{transform:scale(.97)}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{opacity:.9}
.btn-ghost{background:#f5f5f5;color:var(--ink)}
.btn-ghost:hover{background:#eee}
.btn-danger{background:#fafafa;color:var(--ink);border:1px solid var(--line)}
.btn-orange{background:#fafafa;color:var(--ink);border:1px dashed #ccc}
.btn-row{display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap}

@media(max-width:600px){
  .settings-accordion-inner{padding:.8rem .7rem}
  .frow{flex-direction:column;align-items:stretch;gap:.5rem}
  .field{min-width:0;width:100%;max-width:none!important}
  .btn-row{width:100%}
  .btn-row .btn{flex:1 1 calc(50% - .2rem);justify-content:center;display:inline-flex;align-items:center;gap:.3rem}
}

/* ══════════════ Toast ══════════════ */
.snap-toast{
  position:fixed;bottom:1.4rem;right:1.2rem;z-index:200;background:var(--ink);color:#fff;font-size:.73rem;font-weight:500;
  padding:.48rem .9rem;border-radius:9px;box-shadow:0 6px 18px rgba(0,0,0,.12);opacity:0;transform:translateY(6px);
  transition:opacity .25s,transform .25s;pointer-events:none;max-width:260px;
}
.snap-toast.show{opacity:1;transform:translateY(0)}
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">
    <span class="brand-mark"><i class="bi bi-calendar3"></i></span>
    <span class="brand-name">My <b>Schedule</b></span>
  </div>

  <?php if ($token && !$error): ?>
  <nav class="pillnav">
    <a href="?token=<?= urlencode($token) ?>&amp;date=<?= urlencode($date) ?>" class="active">Schedule</a>
    <a href="<?= htmlspecialchars($weeklyUrl) ?>">Weekly</a>
    <a href="<?= htmlspecialchars($monthlyUrl) ?>">Monthly</a>
    <a href="<?= htmlspecialchars($otUrl) ?>">Overtime</a>
    <a href="<?= htmlspecialchars($notifUrl) ?>" class="has-badge">Notifications<span class="pill-badge" id="pillNotifBadge"></span></a>
  </nav>
  <?php endif; ?>

  <div class="topbar-right">
    <?php if ($token): ?>
    <span class="status-chip <?= $error ? 'err' : 'ok' ?>">
      <span class="status-dot"></span><?= $error ? 'Token Error' : 'Connected' ?>
    </span>
    <span class="refresh-dot" id="rdot"></span>
    <?php endif; ?>
    <span class="live-clock" id="liveClock">
      <span class="lc-time" id="lcTime">00:00:00</span>
      <span class="lc-ampm" id="lcAmpm">AM</span>
    </span>
    <button type="button" class="icon-btn" id="settingsToggle" onclick="toggleSettings()"
            title="Token &amp; date settings" aria-label="Toggle settings"
            aria-expanded="<?= $showSettings ? 'true' : 'false' ?>">
      <i class="bi bi-sliders"></i>
    </button>
  </div>
</div>

<div class="sheet-backdrop<?= $showSettings ? ' show' : '' ?>" id="sheetBackdrop" onclick="toggleSettings()"></div>

<div class="settings-accordion<?= $showSettings ? ' open' : '' ?>" id="settingsAccordion">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <span>Token &amp; Date</span>
    <button type="button" class="sheet-close" onclick="toggleSettings()" aria-label="Close"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="settings-accordion-inner">
    <form method="get" id="mainForm">
      <div class="frow">
        <div class="field">
          <label>Token</label>
          <div style="display:flex;gap:.4rem;align-items:center">
            <input type="password" name="token" placeholder="Paste kl_access_token"
                   value="<?= htmlspecialchars($token) ?>" autocomplete="off" id="tokenInput" style="flex:1;min-width:0">
            <a class="btn btn-ghost" href="get-token.php" target="_blank" rel="noopener" title="How to get your token" style="text-decoration:none;display:inline-flex;align-items:center;gap:.3rem">
              <i class="bi bi-question-circle"></i> Get token
            </a>
          </div>
        </div>
        <?php if ($token && !$teacherId): ?>
        <div class="field">
          <label>Teacher ID</label>
          <input type="text" name="teacher_id" placeholder="Couldn't auto-detect. Paste it here" autocomplete="off">
        </div>
        <?php endif; ?>
        <div class="field" style="max-width:155px">
          <label>Date</label>
          <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" id="dateInput">
        </div>
        <div class="btn-row">
          <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-clockwise"></i> Load</button>
          <?php if ($token): ?>
          <button class="btn btn-orange" type="button" onclick="clearSnaps()" title="Clear today's name history">
            <i class="bi bi-eraser"></i> Clear History
          </button>
          <button class="btn btn-danger" type="button" onclick="clearToken()" title="Clear token &amp; cookie">
            <i class="bi bi-box-arrow-right"></i> Clear
          </button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="shell">

  <div class="hero-row">
    <div class="hero-left">
      <div class="eyebrow"><?= htmlspecialchars($dayName) ?> · <?= $token && !$error ? $total.' classes scheduled' : 'Schedule viewer' ?></div>
      <h1 class="mega-title"><?= htmlspecialchars($dateLabel) ?></h1>
    </div>
  </div>

  <?php if ($insecureTest ?? false): ?>
  <div class="alert">
    <i class="bi bi-shield-exclamation"></i> Diagnostic mode: SSL verification is OFF (?insecure=1). For testing only — remove this from the URL once you're done.
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
  <?php elseif ($token): ?>

  <div class="layout">
    <div class="col-main" id="colMain">

      <div class="stats">
        <div class="stat-rich">
          <div class="sr-big blue" id="sTotal"><?= $total ?></div>
          <div class="sr-lbl-main">Total</div>
        </div>

        <div class="stat-rich">
          <div class="sr-big green" id="sAtt"><?= $attended ?></div>
          <div class="sr-lbl-main">Attended</div>
        </div>

        <div class="stat-rich">
          <div class="sr-big yellow" id="sAbs"><?= $absent ?></div>
          <div class="sr-lbl-main">Absent</div>
        </div>

        <div class="stat-rich">
          <div class="sr-big orange" id="sLate"><?= $late ?></div>
          <div class="sr-lbl-main">Late</div>
        </div>

        <div class="stat-rich">
          <div class="sr-big gray" id="sNob"><?= $nobooking ?></div>
          <div class="sr-lbl-main">No Booking</div>
        </div>
      </div>

      <div class="panel-card">
        <div class="panel-head">
          <span class="panel-title"><i class="bi bi-sunrise"></i> AM Class</span>
          <span class="panel-count"><?= count(array_filter($am, fn($s)=>!$s['isOpen'])) ?> booked · <?= count($am) ?> slots</span>
        </div>
        <div id="amSlots"><?php renderSlots($am, $isToday, $nowTs); ?></div>
      </div>

      <div class="panel-card">
        <div class="panel-head">
          <span class="panel-title"><i class="bi bi-brightness-high"></i> PM Class</span>
          <span class="panel-count"><?= count(array_filter($pm, fn($s)=>!$s['isOpen'])) ?> booked · <?= count($pm) ?> slots</span>
        </div>
        <div id="pmSlots"><?php renderSlots($pm, $isToday, $nowTs); ?></div>
      </div>

      <div class="panel-card">
        <div class="panel-head">
          <span class="panel-title"><i class="bi bi-moon-stars"></i> Night Class</span>
          <span class="panel-count"><?= count(array_filter($night, fn($s)=>!$s['isOpen'])) ?> booked · <?= count($night) ?> slots</span>
        </div>
        <div id="nightSlots"><?php renderSlots($night, $isToday, $nowTs); ?></div>
      </div>

      <?php if ($slackBlocks): ?>
      <div class="copy-section" id="slackBlocksWrap">
        <div class="copy-section-head"><i class="bi bi-slack"></i> Quick Copy for Slack</div>
        <?php foreach ($slackBlocks as $idx => $block): renderSlackBlock($block, $idx); endforeach; ?>
      </div>
      <?php endif; ?>

    </div>

    <div class="col-notif" id="colNotif">

      <div class="day-nav">
        <button type="button" class="btn-nav" onclick="goToDay(-1)" title="Previous day" aria-label="Previous day"><i class="bi bi-chevron-left"></i></button>
        <span class="day-chip"><?= $isWeekday ? 'Weekday' : 'Weekend' ?></span>
        <button type="button" class="btn-nav" onclick="goToDay(1)" title="Next day" aria-label="Next day"><i class="bi bi-chevron-right"></i></button>
        <?php if (!$isToday): ?>
        <button type="button" class="btn-today" onclick="goToDay(0)" title="Jump to today">Today</button>
        <?php endif; ?>
      </div>

      <div class="notif-panel">
        <div class="notif-head">
          <h3><i class="bi bi-bell-fill"></i> Notifications
            <span class="notif-count" id="nCount" <?= !$notifCount?'style="display:none"':'' ?>><?= $notifCount ?></span>
          </h3>
        </div>
        <div class="notif-list" id="notifList">
          <?php renderNotifList($notifications); ?>
        </div>
      </div>

      <div class="hist-panel">
        <div class="hist-head">
          <h3><i class="bi bi-clock-history"></i> Name History</h3>
          <span id="histMeta">today</span>
        </div>
        <div class="hist-list" id="histList">
          <div class="hist-empty"><i class="bi bi-hourglass"></i>Tracking changes…</div>
        </div>
      </div>

    </div>
  </div>

  <?php endif; ?>
</div>

<?php if ($token && !$error): ?>
<nav class="tabbar" id="tabbar">
  <button type="button" class="tab-btn active" data-tab="schedule" onclick="switchTab('schedule')">
    <span class="tab-icon-wrap"><i class="bi bi-calendar3"></i></span>
    <span>Schedule</span>
  </button>
  <button type="button" class="tab-btn" data-tab="notif" onclick="switchTab('notif')">
    <span class="tab-icon-wrap">
      <i class="bi bi-bell"></i>
      <span class="tab-badge" id="tabBadge" <?= !$notifCount ? 'style="display:none"' : '' ?>><?= $notifCount ?></span>
    </span>
    <span>Alerts</span>
  </button>
</nav>
<?php endif; ?>

<div class="snap-toast" id="snapToast"></div>

<?php
// ── renderSlots() passes tsRaw to badge() ──
function renderSlots(array $slots, bool $isToday = false, int $nowTs = 0): void {
    if (!$slots) { echo '<div class="empty-sec">No classes</div>'; return; }
    foreach (array_values($slots) as $i => $s) {
        $isActive = $isToday && !$s['isOpen'] && $s['tsRaw'] <= $nowTs && $nowTs < $s['teRaw'];
        $cls  = $s['isOpen'] ? ' open-slot' : '';
        $cls .= $isActive   ? ' active-slot' : '';
        $isPastOpen = $s['tsRaw'] > 0 && $s['tsRaw'] < $nowTs;
        $slotLabel  = $s['nameEn'] ?? ($isPastOpen ? 'Not Reserved' : 'No Booking');
        $av = avatarFor($i);
        echo "<div class=\"slot{$cls}\" data-ts=\"{$s['tsRaw']}\" data-te=\"{$s['teRaw']}\">";
        echo '<div class="slot-avatar" style="background:'.$av['bg'].';color:'.$av['fg'].'">'.htmlspecialchars($av['initial']).'</div>';
        echo '<div class="slot-info">';
        echo '<div class="slot-name">'.htmlspecialchars($slotLabel).'</div>';
        echo '<div class="slot-meta"><span class="slot-time">'.$s['time'].'</span>';
        if (!empty($s['nameJa'])) echo '<span class="dot">·</span><span>'.htmlspecialchars($s['nameJa']).'</span>';
        elseif (!$s['isOpen'] && $s['email'] !== '-') echo '<span class="dot">·</span><span>'.htmlspecialchars($s['email']).'</span>';
        echo '</div>';
        if ($isActive) {
            echo '<div class="slot-countdown" id="slot-cd-'.$s['tsRaw'].'"><i class="bi bi-hourglass-split"></i> <span>…</span></div>';
        }
        echo '</div>';
        echo badge($s['status'], $s['isOpen'], $s['tsRaw']);
        echo '</div>';
    }
}

function renderNotifList(array $notifications): void {
    if (!$notifications) {
        echo '<div class="notif-empty"><i class="bi bi-check-circle"></i>All clear</div>';
        return;
    }
    $groups = [
        'ongoing' => ['label'=>'In Progress', 'items'=>[]],
        'next'    => ['label'=>'Upcoming',    'items'=>[]],
        'booking' => ['label'=>'New Bookings','items'=>[]],
        'absent'  => ['label'=>'Absent',      'items'=>[]],
        'late'    => ['label'=>'Late',        'items'=>[]],
        'cutoff'  => ['label'=>'Reminder',    'items'=>[]],
    ];
    $typeMap = [
        'bi-play-circle-fill'  => 'ongoing',
        'bi-alarm'             => 'next',
        'bi-calendar-plus-fill'=> 'booking',
        'bi-person-x-fill'     => 'absent',
        'bi-clock-fill'        => 'late',
        'bi-scissors'          => 'cutoff',
    ];
    foreach ($notifications as $n) {
        $t = $typeMap[$n['icon']] ?? 'booking';
        $groups[$t]['items'][] = $n;
    }
    foreach ($groups as $g) {
        if (!$g['items']) continue;
        echo '<div class="ni-group-label">'.$g['label'].'</div>';
        foreach ($g['items'] as $n) {
            $teRaw = $n['teRaw'] ?? null;
            $tsRaw = $n['tsRaw'] ?? null;
            $isAlarm = ($n['icon'] === 'bi-alarm' && $tsRaw);
            echo '<div class="ni">';
            echo '<div class="ni-icon '.$n['color'].'"><i class="bi '.$n['icon'].'"></i></div>';
            echo '<div class="ni-body">';
            if ($isAlarm) {
                echo '<div class="ni-title" id="next-cd-title-'.$tsRaw.'">Next class in ...</div>';
            } else {
                echo '<div class="ni-title">'.htmlspecialchars($n['title']).'</div>';
            }
            echo '<div class="ni-sub">'.htmlspecialchars($n['body']).'</div>';
            if ($teRaw) {
                echo '<div class="ni-countdown" id="notif-cd-'.$teRaw.'">...</div>';
            }
            echo '</div></div>';
        }
    }
}

/**
 * Render a single slack clipboard block as a real list (display only).
 * The full copy-ready text still lives in the hidden textarea for cpBlock() to read.
 */
function renderSlackBlock(array $block, int $idx): void {
    echo '<div class="copy-block">';
    echo '<div class="copy-block-head">';
    echo '<span class="copy-block-label"><i class="bi bi-slack"></i> '.htmlspecialchars($block['label']).'</span>';
    echo '<button type="button" class="copy-btn" onclick="cpBlock(this, '.$idx.')"><i class="bi bi-clipboard"></i> Copy</button>';
    echo '</div>';
    echo '<div class="copy-block-list">';
    foreach ($block['sections'] as $section) {
        if (count($block['sections']) > 1) {
            echo '<div class="copy-sub-label">'.htmlspecialchars($section['label']).'</div>';
        }
        if (!$section['slots']) {
            echo '<div class="copy-empty">(none)</div>';
            continue;
        }
        foreach ($section['slots'] as $i => $s) {
            $nm  = $s['nameEn'] ?? 'NO BOOKING';
            $isAbsent = $s['status'] === 'no show';
            $isLate   = $s['status'] === 'late';
            echo '<div class="copy-item">';
            echo '<span class="idx">'.($i+1).'</span>';
            echo '<span class="time">'.htmlspecialchars($s['timeStart']).'</span>';
            echo '<span class="name">'.htmlspecialchars($nm).'</span>';
            if ($isAbsent) echo '<span class="tag">Absent</span>';
            if ($isLate)   echo '<span class="tag late">Late</span>';
            echo '</div>';
        }
    }
    echo '</div>';
    echo '<textarea class="slack-textarea" id="slackBlock'.$idx.'" readonly>'.htmlspecialchars($block['text']).'</textarea>';
    echo '</div>';
}
?>

<script>
(function startClock() {
    const lcTime = document.getElementById('lcTime');
    const lcAmpm = document.getElementById('lcAmpm');
    function tick() {
        const now  = new Date();
        let   h    = now.getHours();
        const m    = now.getMinutes();
        const s    = now.getSeconds();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        if (lcTime) lcTime.textContent =
            String(h).padStart(2,'0') + ':' +
            String(m).padStart(2,'0') + ':' +
            String(s).padStart(2,'0');
        if (lcAmpm) lcAmpm.textContent = ampm;
    }
    tick();
    setInterval(tick, 1000);
})();

/* ── Settings accordion (Token / Date / Load) ── */
function toggleSettings() {
    const panel    = document.getElementById('settingsAccordion');
    const btn      = document.getElementById('settingsToggle');
    const backdrop = document.getElementById('sheetBackdrop');
    const isOpen   = panel.classList.toggle('open');
    if (btn)      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (backdrop) backdrop.classList.toggle('show', isOpen);
    if (window.innerWidth <= 740) {
        document.body.style.overflow = isOpen ? 'hidden' : '';
    }
}

/* ── Bottom tab bar (mobile app view): jump to section, don't hide content ── */
function switchTab(tab) {
    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    const target = tab === 'notif' ? document.getElementById('colNotif') : document.getElementById('colMain');
    if (target) {
        const y = target.getBoundingClientRect().top + window.scrollY - 68; // clear the sticky topbar
        window.scrollTo({ top: y, behavior: 'smooth' });
    }
}

/* Keep the tabbar's active state in sync while the user scrolls manually */
(function watchTabScroll() {
    const notifEl = document.getElementById('colNotif');
    if (!notifEl || !document.getElementById('tabbar')) return;
    let ticking = false;
    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            const passedNotif = notifEl.getBoundingClientRect().top < 120;
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.toggle('active', (b.dataset.tab === 'notif') === passedNotif);
            });
            ticking = false;
        });
    }, { passive: true });
})();

/* ── Prev/Next/Today day navigation ── */
function goToDay(delta) {
    const params = new URLSearchParams();
    if (token) params.set('token', token);
    if (delta === 0) {
        // "Today" button: just drop the date param so PHP defaults to today
    } else {
        const d = new Date(date + 'T00:00:00');
        d.setDate(d.getDate() + delta);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        params.set('date', `${y}-${m}-${day}`);
    }
    const qs = params.toString();
    if (window.klShowLoading) klShowLoading();
    window.location.href = qs ? `?${qs}` : window.location.pathname;
}

document.addEventListener('keydown', (e) => {
    const tag = (document.activeElement && document.activeElement.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (e.key === 'ArrowLeft')  goToDay(-1);
    if (e.key === 'ArrowRight') goToDay(1);
});

const REFRESH_MS = 30_000;
const token     = <?= json_encode($token) ?>;
const date      = <?= json_encode($date) ?>;
const isToday   = <?= json_encode($isToday) ?>;
const isWeekday = <?= json_encode($isWeekday) ?>;

const cdTargets = {};
const ncTargets = {};

function registerCountdown(teRaw, elId) {
    if (!cdTargets[teRaw]) cdTargets[teRaw] = [];
    if (!cdTargets[teRaw].includes(elId)) cdTargets[teRaw].push(elId);
}
function registerNextClassCountdown(tsRaw) {
    const elId = `next-cd-title-${tsRaw}`;
    if (!ncTargets[tsRaw]) ncTargets[tsRaw] = [];
    if (!ncTargets[tsRaw].includes(elId)) ncTargets[tsRaw].push(elId);
}
function fmtCountdown(secsLeft) {
    if (secsLeft <= 0) return 'Ending...';
    const h = Math.floor(secsLeft / 3600);
    const m = Math.floor((secsLeft % 3600) / 60);
    const s = secsLeft % 60;
    if (h > 0) return `${h}h ${String(m).padStart(2,'0')}m ${String(s).padStart(2,'0')}s left`;
    if (m > 0) return `${m}m ${String(s).padStart(2,'0')}s left`;
    return `${s}s left`;
}
function fmtNextClass(secsUntil) {
    if (secsUntil <= 0) return 'Starting now';
    const h = Math.floor(secsUntil / 3600);
    const m = Math.floor((secsUntil % 3600) / 60);
    const s = secsUntil % 60;
    if (h > 0) return `Next class in ${h}h ${String(m).padStart(2,'0')}m ${String(s).padStart(2,'0')}s`;
    if (m > 0) return `Next class in ${m}m ${String(s).padStart(2,'0')}s`;
    return `Next class in ${s}s`;
}
function tickCountdowns() {
    const nowSec = Math.floor(Date.now() / 1000);
    for (const [teRaw, ids] of Object.entries(cdTargets)) {
        const secsLeft = parseInt(teRaw) - nowSec;
        const label = fmtCountdown(secsLeft);
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            const span = el.querySelector('span');
            if (span) span.textContent = label;
            else el.textContent = label;
        });
    }
    for (const [tsRaw, ids] of Object.entries(ncTargets)) {
        const secsUntil = parseInt(tsRaw) - nowSec;
        const label = fmtNextClass(secsUntil);
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = label;
        });
    }
}
if (isToday) setInterval(tickCountdowns, 1000);

function scanAndRegisterCountdowns() {
    document.querySelectorAll('[id^="slot-cd-"]').forEach(el => {
        const tsRaw = el.id.replace('slot-cd-', '');
        const slot  = el.closest('.slot');
        const teRaw = slot ? slot.dataset.te : null;
        if (teRaw) registerCountdown(teRaw, el.id);
    });
    document.querySelectorAll('[id^="notif-cd-"]').forEach(el => {
        const teRaw = el.id.replace('notif-cd-', '');
        registerCountdown(teRaw, el.id);
    });
    document.querySelectorAll('[id^="next-cd-title-"]').forEach(el => {
        const tsRaw = el.id.replace('next-cd-title-', '');
        registerNextClassCountdown(tsRaw);
    });
    tickCountdowns();
}

/* ── Storage helpers ── */
function snapKey(t) { return `kl_snap_${date}_${t}`; }
function histKey()  { return `kl_hist_${date}`; }

function getSnap(timeStart) {
    try { const v = localStorage.getItem(snapKey(timeStart)); return v ? JSON.parse(v) : null; }
    catch { return null; }
}
function setSnap(timeStart, name) {
    try { localStorage.setItem(snapKey(timeStart), JSON.stringify({ name, savedAt: new Date().toISOString() })); }
    catch {}
}
function getHist() {
    try { const v = localStorage.getItem(histKey()); return v ? JSON.parse(v) : []; }
    catch { return []; }
}

function pushHist(entry) {
    try {
        const hist = getHist();
        const dupe = hist.find(h =>
            h.time     === entry.time &&
            h.type     === entry.type &&
            h.prevName === entry.prevName &&
            (h.newName ?? null) === (entry.newName ?? null)
        );
        if (dupe) return;
        hist.unshift({ ...entry, ts: Date.now() });
        localStorage.setItem(histKey(), JSON.stringify(hist.slice(0, 100)));
    } catch {}
}

function saveSnapshot(slots) {
    slots.forEach(s => {
        const prev = getSnap(s.timeStart);
        if (!s.isOpen && s.nameEn) {
            if (!prev) {
                setSnap(s.timeStart, s.nameEn);
            } else if (prev.name !== s.nameEn) {
                pushHist({ time: s.timeStart, prevName: prev.name, newName: s.nameEn, type: 'replaced' });
                showToast(`Replaced at ${s.timeStart}: ${prev.name} → ${s.nameEn}`);
                setSnap(s.timeStart, s.nameEn);
            }
        } else if (s.isOpen && prev) {
            pushHist({ time: s.timeStart, prevName: prev.name, newName: null, type: 'nobooking' });
            showToast(`Booking removed at ${s.timeStart}: was ${prev.name}`);
            try { localStorage.removeItem(snapKey(s.timeStart)); } catch {}
        }
    });
    renderHistPanel();
}

function renderHistPanel() {
    const list = document.getElementById('histList');
    const meta = document.getElementById('histMeta');
    if (!list) return;
    const hist = getHist();
    if (!hist.length) {
        list.innerHTML = '<div class="hist-empty"><i class="bi bi-hourglass"></i>No changes yet</div>';
        if (meta) meta.textContent = 'today';
        return;
    }
    if (meta) meta.textContent = `${hist.length} change${hist.length !== 1 ? 's' : ''}`;
    list.innerHTML = hist.map(h => {
        let tagText = '', tagClass = '';
        if (h.type === 'replaced')   { tagText = `→ ${esc(h.newName ?? '')}`;  tagClass = 'replaced'; }
        else if (h.type === 'nobooking') { tagText = 'slot became open';        tagClass = 'nobooking'; }
        else if (h.type === 'cancelled') { tagText = 'cancelled';               tagClass = 'cancelled'; }
        const dateChip = `<span class="hi-date">${fmtDate(h.ts)}</span>`;
        return `<div class="hi">
          <div class="hi-time">${esc(h.time)}</div>
          <div class="hi-body">
            <div class="hi-name">${esc(h.prevName ?? 'Unknown')}</div>
            <div class="hi-tag ${tagClass}">${dateChip}${tagText} · ${fmtAgo(h.ts)}</div>
          </div>
        </div>`;
    }).join('');
}

function fmtAgo(ts) {
    const s = Math.floor((Date.now() - ts) / 1000);
    if (s < 60)   return 'just now';
    if (s < 3600) return `${Math.floor(s/60)}m ago`;
    return `${Math.floor(s/3600)}h ago`;
}
function fmtDate(ts) {
    return new Date(ts).toLocaleDateString([], { month: 'short', day: 'numeric' });
}

let toastTimer;
function showToast(msg) {
    const el = document.getElementById('snapToast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 3500);
}

/* ── Clipboard: per-block copy ── */
function cpBlock(btnOrEl, idx) {
    const ta = document.getElementById('slackBlock' + idx);
    if (!ta) return;
    const block = btnOrEl.closest ? btnOrEl.closest('.copy-block') : null;
    const btn = block ? block.querySelector('.copy-btn') : (btnOrEl.classList?.contains('copy-btn') ? btnOrEl : null);
    navigator.clipboard.writeText(ta.value).then(() => {
        if (btn) {
            btn.classList.add('copied');
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied';
            setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy'; }, 2200);
        }
        showToast('Copied ' + (block?.querySelector('.copy-block-label')?.textContent?.trim() || 'block') + ' to clipboard');
    });
}

function clearToken() {
    document.cookie = 'kl_token=;expires=Thu,01 Jan 1970 00:00:00 GMT;path=/';
    document.cookie = 'kl_teacher=;expires=Thu,01 Jan 1970 00:00:00 GMT;path=/';
    document.cookie = 'kl_tfail=;expires=Thu,01 Jan 1970 00:00:00 GMT;path=/';
    const u = new URL(location.href); u.searchParams.delete('token');
    if (window.klShowLoading) klShowLoading();
    location.href = u.toString();
}
function clearSnaps() {
    const prefix = `kl_snap_${date}_`;
    Object.keys(localStorage)
        .filter(k => k.startsWith(prefix) || k === histKey())
        .forEach(k => localStorage.removeItem(k));
    showToast('History cleared for today.');
    renderHistPanel();
    updateSection('amSlots',    window._lastAm    || []);
    updateSection('pmSlots',    window._lastPm    || []);
    updateSection('nightSlots', window._lastNight || []);
}
function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function txt(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }
function html(id, v) { const el = document.getElementById(id); if (el) el.innerHTML = v; }
function setWidth(id, pct) { const el = document.getElementById(id); if (el) el.style.width = pct + '%'; }

/* ── badgeHtml() checks tsRaw vs current time for open slots ── */
function badgeHtml(status, isOpen, tsRaw) {
    if (isOpen) {
        const nowSec = Math.floor(Date.now() / 1000);
        const label = (tsRaw > 0 && tsRaw < nowSec) ? 'Not Reserved' : 'No Booking';
        return `<span class="badge open">${label}</span>`;
    }
    if (status === 'attended')  return '<span class="badge att">Attended</span>';
    if (status === 'late')      return '<span class="badge late">Late</span>';
    if (status === 'no show')   return '<span class="badge abs">Absent</span>';
    return '<span class="badge res">Reserved</span>';
}

const AVATAR_COLORS = [
    { bg: '#ffffff', fg: '#000000' },
];
function avatarFor(i) {
    const c = AVATAR_COLORS[i % AVATAR_COLORS.length];
    return { initial: String(i + 1), bg: c.bg, fg: c.fg };
}

function buildSlots(slots) {
    if (!slots.length) return '<div class="empty-sec">No classes</div>';
    const nowSec = Math.floor(Date.now() / 1000);
    return slots.map((s, i) => {
        const isActive = isToday && !s.isOpen && s.tsRaw <= nowSec && nowSec < s.teRaw;
        const oc  = s.isOpen  ? ' open-slot'  : '';
        const act = isActive  ? ' active-slot' : '';
        const prev = getSnap(s.timeStart);
        let wasHtml = '';
        if (prev) {
            if (s.isOpen && prev.name !== (s.nameEn ?? '')) {
                wasHtml = `<div class="slot-was"><i class="bi bi-arrow-return-right"></i> was: ${esc(prev.name)}</div>`;
            } else if (!s.isOpen && s.nameEn && prev.name !== s.nameEn) {
                wasHtml = `<div class="slot-was"><i class="bi bi-arrow-left-right"></i> replaced: ${esc(prev.name)}</div>`;
            }
        }
        let countdownHtml = '';
        if (isActive) {
            const cdId = `slot-cd-${s.tsRaw}`;
            countdownHtml = `<div class="slot-countdown" id="${cdId}"><i class="bi bi-hourglass-split"></i> <span>…</span></div>`;
        }
        const isPastOpen = s.tsRaw > 0 && s.tsRaw < nowSec;
        const slotLabel  = s.nameEn ?? (isPastOpen ? 'Not Reserved' : 'No Booking');
        const av = avatarFor(i);
        let meta = `<span class="slot-time">${esc(s.time)}</span>`;
        if (s.nameJa) meta += `<span class="dot">·</span><span>${esc(s.nameJa)}</span>`;
        else if (!s.isOpen && s.email && s.email !== '-') meta += `<span class="dot">·</span><span>${esc(s.email)}</span>`;
        const info = `<div class="slot-name">${esc(slotLabel)}</div><div class="slot-meta">${meta}</div>${countdownHtml}${wasHtml}`;
        return `<div class="slot${oc}${act}" data-ts="${s.tsRaw}" data-te="${s.teRaw}">
          <div class="slot-avatar" style="background:${av.bg};color:${av.fg}">${esc(av.initial)}</div>
          <div class="slot-info">${info}</div>
          ${badgeHtml(s.status, s.isOpen, s.tsRaw)}
        </div>`;
    }).join('');
}

function buildNotifs(notifs) {
    if (!notifs.length) return '<div class="notif-empty"><i class="bi bi-check-circle"></i>All clear</div>';
    const groups = {
        ongoing: { label: 'In Progress',  items: [] },
        next:    { label: 'Upcoming',     items: [] },
        booking: { label: 'New Bookings', items: [] },
        absent:  { label: 'Absent',       items: [] },
        late:    { label: 'Late',         items: [] },
        cutoff:  { label: 'Reminder',     items: [] },
    };
    const tm = {
        'bi-play-circle-fill'  : 'ongoing',
        'bi-alarm'             : 'next',
        'bi-calendar-plus-fill': 'booking',
        'bi-person-x-fill'     : 'absent',
        'bi-clock-fill'        : 'late',
        'bi-scissors'          : 'cutoff',
    };
    notifs.forEach(n => { const t = tm[n.icon] || 'booking'; groups[t].items.push(n); });
    let h = '';
    for (const g of Object.values(groups)) {
        if (!g.items.length) continue;
        h += `<div class="ni-group-label">${g.label}</div>`;
        g.items.forEach(n => {
            const teRaw = n.teRaw ?? null;
            const tsRaw = n.tsRaw ?? null;
            const cdHtml = teRaw
                ? `<div class="ni-countdown" id="notif-cd-${teRaw}">...</div>`
                : '';
            const isNextAlarm = n.icon === 'bi-alarm' && tsRaw;
            const titleHtml = isNextAlarm
                ? `<div class="ni-title" id="next-cd-title-${tsRaw}">Next class in ...</div>`
                : `<div class="ni-title">${esc(n.title)}</div>`;
            if (isNextAlarm) registerNextClassCountdown(tsRaw);
            h += `<div class="ni">
              <div class="ni-icon ${n.color}"><i class="bi ${n.icon}"></i></div>
              <div class="ni-body">
                ${titleHtml}
                <div class="ni-sub">${esc(n.body)}</div>
                ${cdHtml}
              </div>
            </div>`;
        });
    }
    return h;
}

/* ── JS: filter slots the same way slackSectionSlots() does in PHP ── */
function jsSlackSectionSlots(slots, filterNoBooking) {
    return filterNoBooking ? slots.filter(s => !s.isOpen) : slots;
}

/* ── JS: Build slack section text, mirroring PHP slackSection() logic ── */
function jsSlackSection(label, slots, filterNoBooking) {
    const filtered = jsSlackSectionSlots(slots, filterNoBooking);
    let out = label + '\n';
    if (!filtered.length) {
        out += '   (none)\n';
    } else {
        filtered.forEach((s, i) => {
            const tag = s.status === 'no show' ? ' [Absent]' : (s.status === 'late' ? ' [Late]' : '');
            const nm  = s.nameEn ?? 'NO BOOKING';
            out += '   ' + (i + 1) + '.   ' + s.timeStart + ' - ' + nm + tag + '\n';
        });
    }
    return out;
}

/* ── JS: Build slack blocks, mirroring PHP buildSlackBlocks() logic ── */
function jsBuildSlackBlocks(dl, am, pm, night) {
    const header = 'DATE: ' + dl + '\n\n';
    const blocks = [];

    if (isWeekday) {
        blocks.push({
            label: 'Morning Class',
            text:  header + jsSlackSection('AM CLASS', am, true),
            sections: [{ label: 'AM CLASS', slots: jsSlackSectionSlots(am, true) }],
        });
        const pmBooked = jsSlackSectionSlots(pm, true);
        if (pmBooked.length) {
            blocks.push({
                label: 'PM Class',
                text:  header + jsSlackSection('PM CLASS', pm, true),
                sections: [{ label: 'PM CLASS', slots: pmBooked }],
            });
        }
        blocks.push({
            label: 'Night Class',
            text:  header + jsSlackSection('NIGHT CLASS', night, true),
            sections: [{ label: 'NIGHT CLASS', slots: jsSlackSectionSlots(night, true) }],
        });
    } else {
        blocks.push({
            label: 'Schedule',
            text:  header + jsSlackSection('AM CLASS', am, false) + '\n' + jsSlackSection('PM CLASS', pm, false),
            sections: [
                { label: 'AM CLASS', slots: jsSlackSectionSlots(am, false) },
                { label: 'PM CLASS', slots: jsSlackSectionSlots(pm, false) },
            ],
        });
    }

    return blocks;
}

/* ── Render one clipboard block as a real list (display only) ── */
function buildCopyBlock(b, idx) {
    let listHtml = '';
    b.sections.forEach(section => {
        if (b.sections.length > 1) {
            listHtml += `<div class="copy-sub-label">${esc(section.label)}</div>`;
        }
        if (!section.slots.length) {
            listHtml += '<div class="copy-empty">(none)</div>';
            return;
        }
        section.slots.forEach((s, i) => {
            const nm = s.nameEn ?? 'NO BOOKING';
            const tagHtml = s.status === 'no show' ? '<span class="tag">Absent</span>'
                          : s.status === 'late'     ? '<span class="tag late">Late</span>'
                          : '';
            listHtml += `<div class="copy-item">
              <span class="idx">${i + 1}</span>
              <span class="time">${esc(s.timeStart)}</span>
              <span class="name">${esc(nm)}</span>
              ${tagHtml}
            </div>`;
        });
    });
    return `<div class="copy-block">
      <div class="copy-block-head">
        <span class="copy-block-label"><i class="bi bi-slack"></i> ${esc(b.label)}</span>
        <button type="button" class="copy-btn" onclick="cpBlock(this, ${idx})"><i class="bi bi-clipboard"></i> Copy</button>
      </div>
      <div class="copy-block-list">${listHtml}</div>
      <textarea class="slack-textarea" id="slackBlock${idx}" readonly>${esc(b.text)}</textarea>
    </div>`;
}

/* ── Rebuild slack quick-copy list after AJAX refresh ── */
function updateSlackBlocks(blocks) {
    const wrap = document.getElementById('slackBlocksWrap');
    if (!wrap) return;
    if (!blocks || !blocks.length) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = '<div class="copy-section-head"><i class="bi bi-slack"></i> Quick Copy for Slack</div>' +
      blocks.map((b, idx) => buildCopyBlock(b, idx)).join('');
}

function updateSection(elId, slots) {
    const wrap = document.getElementById(elId);
    if (!wrap) return;
    const newHtml = buildSlots(slots);
    if (wrap.innerHTML !== newHtml) {
        wrap.innerHTML = newHtml;
        scanAndRegisterCountdowns();
    }
}

function updateStatCards(stats) {
    txt('sTotal', stats.total);
    txt('sAtt', stats.attended);
    txt('sAbs', stats.absent);
    txt('sLate', stats.late);
    txt('sNob', stats.nobooking);
}

const rdot = document.getElementById('rdot');

async function doRefresh() {
    if (!token) return;
    if (rdot) rdot.classList.add('spinning');
    try {
        const r = await fetch(`?token=${encodeURIComponent(token)}&date=${encodeURIComponent(date)}&ajax=1`);
        if (!r.ok) return;
        const d = await r.json();

        const allSlots = [...d.am, ...d.pm, ...d.night];
        saveSnapshot(allSlots);

        window._lastAm    = d.am;
        window._lastPm    = d.pm;
        window._lastNight = d.night;

        updateStatCards(d.stats);

        updateSection('amSlots',    d.am);
        updateSection('pmSlots',    d.pm);
        updateSection('nightSlots', d.night);

        // Rebuild slack rows client-side so no-booking filter is always applied
        const dateLabel = <?= json_encode($dateLabel) ?>;
        updateSlackBlocks(jsBuildSlackBlocks(dateLabel, d.am, d.pm, d.night));

        html('notifList', buildNotifs(d.notifs));
        scanAndRegisterCountdowns();

        const nc = document.getElementById('nCount');
        if (nc) { nc.textContent = d.notifCount; nc.style.display = d.notifCount ? '' : 'none'; }

        const tb = document.getElementById('tabBadge');
        if (tb) { tb.textContent = d.notifCount; tb.style.display = d.notifCount ? '' : 'none'; }

    } catch(e) {
        console.error('Refresh error', e);
    } finally {
        if (rdot) rdot.classList.remove('spinning');
        setTimeout(doRefresh, REFRESH_MS);
    }
}

if (token) {
    const initialSlots = <?= json_encode(array_merge($am, $pm, $night)) ?>;
    window._lastAm     = <?= json_encode($am) ?>;
    window._lastPm     = <?= json_encode($pm) ?>;
    window._lastNight  = <?= json_encode($night) ?>;

    saveSnapshot(initialSlots);

    updateSection('amSlots',    window._lastAm);
    updateSection('pmSlots',    window._lastPm);
    updateSection('nightSlots', window._lastNight);

    scanAndRegisterCountdowns();
    renderHistPanel();

    setTimeout(doRefresh, REFRESH_MS);
    setInterval(renderHistPanel, 60_000);
}
</script>
<script>
(function(){
  const token = <?= json_encode($token) ?>;
  const badge = document.getElementById('pillNotifBadge');
  if (!token || !badge) return;
  async function pollNotifCount(){
    try {
      const r = await fetch('notif_count.php?token=' + encodeURIComponent(token), {cache:'no-store'});
      if (!r.ok) return;
      const d = await r.json();
      const c = d.count || 0;
      badge.textContent = c > 99 ? '99+' : c;
      badge.style.display = c > 0 ? 'block' : 'none';
    } catch(e) { /* silent — keep last known badge value on failure */ }
  }
  pollNotifCount();
  setInterval(pollNotifCount, 30000); // refresh in the background every 30s
})();
</script>
<?php include __DIR__ . '/loader.php'; ?>
</body>
</html>