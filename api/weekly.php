<?php
require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/kl_helpers.php';

// ── Token: cookie only, no visible form field ──
$token = '';
if (!empty($_GET['token'])) {
    $token = trim($_GET['token']);
    setcookie('kl_token', $token, time() + 60*60*24*30, '/');
} elseif (!empty($_COOKIE['kl_token'])) {
    $token = $_COOKIE['kl_token'];
}
$teacherId = $token ? kl_resolve_teacher_id($token) : '';

// ── Week navigation: offset from current week ──
$weekOffset = isset($_GET['w']) ? (int)$_GET['w'] : 0;
$today      = new DateTime('today', new DateTimeZone('Asia/Manila'));
$todayStr   = $today->format('Y-m-d');

// Anchor to Monday of current week, then shift by offset
$dow        = (int)$today->format('N'); // 1=Mon…7=Sun
$weekStart  = (clone $today)->modify('-'.($dow - 1).' days')->modify("{$weekOffset} weeks");
$weekEnd    = (clone $weekStart)->modify('+6 days');

$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr   = $weekEnd->format('Y-m-d');

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = (clone $weekStart)->modify("+{$i} days")->format('Y-m-d');
}

// ── Fetch ──
$allSlots   = [];
$fetchError = null;

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
    $fetchError = "Couldn't detect your Teacher ID automatically. Open the Daily View and enter it in the Teacher ID box.";
} elseif ($token) {
    $url = "https://api.kredo-learning.com/v2/teachers/{$teacherId}/schedules"
         . "?start_date={$weekStartStr}&end_date={$weekEndStr}";
    $ch  = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Accept: application/json", "Authorization: Bearer {$token}"],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    // The host's default CA bundle path may not exist in this environment (curl error #77).
    // If cacert.pem is placed next to this script, use it explicitly.
    $caBundle = __DIR__ . '/cacert.pem';
    $caFound  = is_file($caBundle);
    if ($caFound) {
        $curlOpts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $curlOpts);
    $res       = curl_exec($ch);
    $code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    if ($code === 200) {
        $body = json_decode($res, true);
        $raw  = $body['data'] ?? $body['schedules'] ?? $body ?? [];
        usort($raw, fn($a, $b) => strcmp($a['start'], $b['start']));

        foreach ($raw as $s) {
            $st     = (new DateTime($s['start'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
            $en     = (new DateTime($s['end'],   new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
            $dayKey = $st->format('Y-m-d');
            $r      = $s['reservation'] ?? null;
            $rv     = $r['reservee']    ?? null;
            $fallbackStatus = $r['status'] ?? ($s['is_reserved'] ? 'reserved' : 'open');
            $status = $r ? resolveAttendanceStatus($r['teacher_notes'] ?? null, $fallbackStatus) : $fallbackStatus;
            if ($status === 'cancelled') continue;

            $allSlots[$dayKey][] = [
                'timeStart' => $st->format('H:i'),
                'nameEn'    => $rv['name_en'] ?? null,
                'nameJa'    => $rv['name_ja'] ?? null,
                'email'     => $rv['email']   ?? '-',
                'status'    => $status,
                'isOpen'    => !$s['is_reserved'],
            ];
        }
    } elseif ($code === 0) {
        // curl never got a response — connection-level failure, NOT an expired token.
        if ($curlErrno === 77) {
            $diag = $caFound
                ? "cacert.pem was found at {$caBundle} but curl still rejected it — file may be corrupted/truncated, or this host's curl/OpenSSL build ignores CURLOPT_CAINFO."
                : "cacert.pem was NOT found at {$caBundle} — check the file was uploaded to this exact folder (script dir: " . __DIR__ . ").";
            $fetchError = "SSL cert bundle missing on this host (curl #77). {$diag}";
        } else {
            $fetchError = $curlErr
                ? "Connection failed (curl #{$curlErrno}): {$curlErr}"
                : "Connection failed — could not reach the Kredo API (no HTTP response received).";
        }
    } elseif ($code === 401 || $code === 403) {
        $fetchError = "HTTP {$code} — token expired or invalid.";
    } else {
        $fetchError = "HTTP {$code} — request failed.";
    }
}

// ── Aggregate per-student ──
$students    = [];
$totalBooked = 0;

foreach ($allSlots as $dayKey => $slots) {
    foreach ($slots as $s) {
        if ($s['isOpen']) continue;
        $key = $s['email'] !== '-' ? $s['email'] : ($s['nameEn'] ?? 'unknown');
        $totalBooked++;
        if (!isset($students[$key])) {
            $students[$key] = [
                'nameEn'   => $s['nameEn'] ?? 'Unknown',
                'nameJa'   => $s['nameJa'] ?? '',
                'email'    => $s['email'],
                'days'     => [],
                'count'    => 0,
                'attended' => 0,
                'late'     => 0,
                'absent'   => 0,
                'reserved' => 0,
            ];
        }
        $students[$key]['count']++;
        $students[$key]['days'][$dayKey][] = ['time' => $s['timeStart'], 'status' => $s['status']];
        if ($s['status'] === 'attended')      $students[$key]['attended']++;
        elseif ($s['status'] === 'late')      $students[$key]['late']++;
        elseif ($s['status'] === 'no show')   $students[$key]['absent']++;
        else                                   $students[$key]['reserved']++;
    }
}
uasort($students, fn($a, $b) => $b['count'] <=> $a['count']);

// ── Labels ──
$dayShort  = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$isThisWeek = ($weekOffset === 0);
$weekLabel  = $weekStart->format('M j') . ' – ' . $weekEnd->format('M j, Y');

$uniqueStudents = count($students);
$totalAttended  = array_sum(array_column($students, 'attended'));
$totalLate      = array_sum(array_column($students, 'late'));
$totalAbsent    = array_sum(array_column($students, 'absent'));
$totalReserved  = array_sum(array_column($students, 'reserved'));

// Per-day booking counts (for heatmap header)
$dayBookingCount = [];
foreach ($days as $d) {
    $dayBookingCount[$d] = 0;
    foreach ($students as $stu) {
        if (!empty($stu['days'][$d])) $dayBookingCount[$d] += count($stu['days'][$d]);
    }
}
$maxDayCount = max(1, max($dayBookingCount ?: [1]));

function statusIcon(string $status): string {
    return match($status) {
        'attended' => '<span class="si si-att" title="Attended">✓</span>',
        'no show'  => '<span class="si si-abs" title="Absent">✗</span>',
        default    => '<span class="si si-res" title="Reserved">·</span>',
    };
}

// Avatar initials color (deterministic from name)
function avatarColor(string $name): string {
    $colors = ['#6366f1','#8b5cf6','#0ea5e9','#10b981','#f59e0b','#ef4444','#ec4899','#14b8a6'];
    return $colors[abs(crc32($name)) % count($colors)];
}
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) return strtoupper(mb_substr($parts[0],0,1).mb_substr($parts[1],0,1));
    return strtoupper(mb_substr($name,0,2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weekly Students</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2312805c'/%3E%3Crect x='7' y='5' width='18' height='22' rx='3' fill='%23fff'/%3E%3Crect x='10' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='19' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='10' y='13' width='12' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='17' width='8' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='21' width='5' height='1.5' rx='.75' fill='%2312805c'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
/* ── Reset ── */
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#ffffff;
  --surface:#ffffff;
  --surface2:#fafafa;
  --border:#e8e8e8;
  --border2:#d1d1d1;
  --text:#1a1a1a;
  --text2:#4b4b4b;
  --text3:#8a8a8a;
  --accent:#12805c;
  --accent-soft:#e8f3ef;
  --accent-soft-hover:#d6f5e9;
  --green:#16a34a;
  --amber:#d97706;
  --red:#dc2626;
  --blue:#2563eb;
  --purple:#7c3aed;
  --radius:14px;
  --radius-sm:10px;
}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:var(--bg);
  color:var(--text);
  font-size:13.5px;
  line-height:1.55;
  -webkit-font-smoothing:antialiased;
  letter-spacing:-.01em;
}

/* ── Topbar ── */
.topbar{
  background:var(--surface);
  border-bottom:1px solid var(--border);
  padding:.85rem 1.5rem;
  display:flex;
  align-items:center;
  gap:.75rem;
  flex-wrap:wrap;
  position:sticky;
  top:0;
  z-index:60;
}
.topbar-brand{display:flex;align-items:center;gap:.5rem}
.topbar-icon{
  width:30px;height:30px;border-radius:8px;
  background:var(--accent);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:.85rem;flex-shrink:0;
}
.topbar-title{font-size:.9rem;font-weight:700;color:var(--text);letter-spacing:-.01em}
.topbar-sub{font-size:.68rem;color:var(--text3);font-weight:500;margin-top:-.05rem}
.topbar-spacer{flex:1;min-width:.5rem}

/* Shared page-switcher pill nav (matches index.php) */
.pillnav{
  display:flex;
  gap:.15rem;
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
  color:var(--text3);
  text-decoration:none;
  white-space:nowrap;
  transition:color .15s,background .15s;
}
.pillnav a:hover{color:var(--text);background:var(--surface2)}
.pillnav a.active{color:var(--accent);background:var(--accent-soft)}
.pillnav a.has-badge{position:relative}
.pill-badge{display:none;position:absolute;top:.05rem;right:0;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#dc2626;color:#fff;font-size:.62rem;font-weight:800;line-height:16px;text-align:center;box-shadow:0 0 0 2px #fff}

/* Week nav */
.week-nav{display:flex;align-items:center;gap:.4rem}
.week-label{
  font-size:.78rem;font-weight:700;color:var(--accent);
  background:var(--accent-soft);
  border-radius:999px;padding:.32rem .95rem;white-space:nowrap;
}
.week-label.current{background:var(--accent);color:#fff}
.nav-btn{
  display:flex;align-items:center;justify-content:center;
  width:30px;height:30px;border-radius:50%;
  background:var(--surface2);border:none;
  color:var(--text2);text-decoration:none;font-size:.85rem;
  transition:background .15s,color .15s;
  cursor:pointer;
}
.nav-btn:hover{background:var(--accent-soft);color:var(--accent)}
.back-link{
  text-decoration:none;
  font-size:.78rem;font-weight:700;
  color:var(--accent);
  display:flex;align-items:center;gap:.35rem;
  padding:.3rem .7rem;
  border-radius:8px;
  background:var(--accent-soft);
  transition:background .15s;
  white-space:nowrap;
}
.back-link:hover{background:var(--accent-soft-hover)}

/* ── Page ── */
.page{max-width:1280px;margin:0 auto;padding:1.2rem 1.5rem}

/* ── Stat cards ── */
.stats-row{display:flex;gap:.65rem;margin-bottom:1.1rem;flex-wrap:wrap}
.stat-card{
  background:var(--surface2);
  border-radius:var(--radius);
  padding:.9rem 1.1rem;
  display:flex;align-items:center;gap:.75rem;
  flex:1;min-width:120px;
}
.stat-icon{
  width:34px;height:34px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:.9rem;flex-shrink:0;
  background:#fff;
}
.stat-icon.indigo{color:var(--accent)}
.stat-icon.green {color:var(--green)}
.stat-icon.yellow{color:var(--amber)}
.stat-icon.red   {color:var(--red)}
.stat-icon.blue  {color:var(--blue)}
.stat-val{font-size:1.5rem;font-weight:700;color:var(--text);letter-spacing:-.02em;line-height:1}
.stat-lbl{font-size:.62rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-top:.15rem}

/* ── Toolbar ── */
.toolbar{
  display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;
  margin-bottom:.85rem;
}
.search-wrap{position:relative;flex:1;min-width:180px;max-width:320px}
.search-wrap i{
  position:absolute;left:.65rem;top:50%;transform:translateY(-50%);
  color:var(--text3);font-size:.8rem;pointer-events:none;
}
.search-wrap input{
  width:100%;
  padding:.44rem .75rem .44rem 2rem;
  border:1px solid var(--border);border-radius:var(--radius-sm);
  font-size:.82rem;font-family:'Inter',sans-serif;
  background:var(--surface);color:var(--text);outline:none;
  transition:border-color .15s;
}
.search-wrap input:focus{border-color:var(--accent)}
.filter-pill{
  display:flex;align-items:center;gap:.3rem;
  padding:.4rem .8rem;
  border:none;border-radius:999px;
  font-size:.75rem;font-weight:600;color:var(--text2);
  background:var(--surface2);cursor:pointer;
  transition:all .15s;white-space:nowrap;
  font-family:'Inter',sans-serif;
}
.filter-pill:hover,.filter-pill.active{
  background:var(--accent-soft);color:var(--accent);
}
.filter-select{
  border:1px solid var(--border);border-radius:var(--radius-sm);
  padding:.42rem .65rem;font-size:.78rem;font-family:'Inter',sans-serif;
  background:var(--surface);color:var(--text2);outline:none;cursor:pointer;
  transition:border-color .15s;
}
.filter-select:focus{border-color:var(--accent)}
.row-count{
  font-size:.72rem;font-weight:700;color:var(--text3);
  background:var(--surface2);
  border-radius:999px;padding:.3rem .8rem;white-space:nowrap;margin-left:auto;
}

/* ── Table wrapper ── */
.tbl-wrap{
  background:var(--surface);
  border-radius:var(--radius);
  border:1px solid var(--border);
  overflow:hidden;
}
.tbl-scroll{overflow-x:auto}

table{width:100%;border-collapse:collapse;font-size:.8rem}

/* head */
thead th{
  background:var(--surface2);
  font-size:.62rem;font-weight:700;letter-spacing:.06em;
  text-transform:uppercase;color:var(--text3);
  padding:.7rem .9rem;
  border-bottom:1px solid var(--border);
  white-space:nowrap;position:sticky;top:0;z-index:10;
}
thead th.th-day{text-align:center;min-width:88px;padding:.55rem .6rem}
thead th.th-num{text-align:center;min-width:38px}
thead th.th-stat{text-align:center;min-width:52px}
thead th.th-total{text-align:center;min-width:52px}

/* day heat bar in header */
.day-head-inner{display:flex;flex-direction:column;align-items:center;gap:.22rem}
.day-name{font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.day-date{font-size:.6rem;font-weight:500;color:var(--text3)}
.day-bar-wrap{width:36px;height:4px;background:var(--border);border-radius:3px;overflow:hidden}
.day-bar{height:100%;border-radius:3px;background:var(--accent);opacity:.65;transition:opacity .2s}
.today-pip{
  width:5px;height:5px;border-radius:50%;
  background:var(--accent);display:inline-block;margin-top:1px;
}

th.col-today{background:var(--accent-soft) !important;}

/* body */
tbody tr{border-bottom:1px solid var(--border);transition:background .12s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#fafafa}
tbody tr.hidden-row{display:none}
td{padding:.65rem .9rem;vertical-align:middle}
td.td-day{text-align:center;padding:.5rem .55rem}
td.td-today{background:var(--accent-soft)}
td.td-stat{text-align:center}
td.td-total{text-align:center}

/* ── Avatar + name cell ── */
.name-cell-inner{display:flex;align-items:center;gap:.65rem}
.avatar{
  width:32px;height:32px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:.62rem;font-weight:700;color:#fff;flex-shrink:0;
  letter-spacing:.02em;
  background:var(--accent) !important;
}
.student-name{font-weight:600;font-size:.85rem;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
.student-ja  {font-size:.68rem;color:var(--text2);margin-top:.05rem}
.student-email{font-size:.63rem;color:var(--text3);margin-top:.03rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}

/* ── Rank ── */
.rank-badge{
  display:inline-flex;align-items:center;justify-content:center;
  width:20px;height:20px;
  font-size:.68rem;font-weight:700;
  color:var(--text3);
  background:none;border:none;
}
.rank-badge.gold  {color:#b45309}
.rank-badge.silver{color:#64748b}
.rank-badge.bronze{color:#9a3412}

/* ── Day cell slots ── */
.day-slots{display:flex;flex-direction:column;align-items:center;gap:.2rem}
.slot-chip{
  display:inline-flex;align-items:center;gap:.28rem;
  font-size:.68rem;font-weight:600;
  background:none;border:none;padding:0;
  white-space:nowrap;line-height:1.3;
}
.slot-chip.att{color:var(--green)}
.slot-chip.late{color:var(--amber)}
.slot-chip.abs{color:var(--red)}
.slot-chip.res{color:var(--blue)}
.chip-dot{display:none}
.day-empty{color:var(--border2);font-size:.9rem;font-weight:300}

/* ── Total cell ── */
.total-bubble{
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.9rem;font-weight:700;
  color:var(--accent);
  background:none;border:none;padding:0;
}

/* ── Stat badge ── */
.sb{
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.78rem;font-weight:700;
  background:none;border:none;padding:0;
}
.sb-att{color:var(--green)}
.sb-late{color:var(--amber)}
.sb-abs{color:var(--red)}
.sb-nil{color:var(--border2)}

/* ── Legend strip ── */
.legend-strip{
  display:flex;gap:1rem;align-items:center;
  font-size:.7rem;font-weight:600;color:var(--text2);
  padding:.6rem 0 .75rem;flex-wrap:wrap;
}
.leg{display:flex;align-items:center;gap:.35rem}
.leg-dot{width:7px;height:7px;border-radius:50%}

/* ── Alert / empty ── */
.alert{
  padding:.8rem 1rem;border-radius:var(--radius-sm);
  font-size:.84rem;font-weight:500;
  color:var(--text);background:var(--surface2);border:1px solid var(--border);
  margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem;
}
.alert.warn{color:var(--text)}
.empty-state{
  text-align:center;padding:4rem 1rem;color:var(--text3);
  background:var(--surface);border-radius:var(--radius);
  border:1px dashed var(--border2);
}
.empty-state i{font-size:2.2rem;display:block;margin-bottom:.65rem;color:var(--border2)}
.empty-state strong{display:block;font-size:.88rem;font-weight:600;color:var(--text2);margin-bottom:.25rem}

/* ── Responsive ── */
@media(max-width:680px){
  .page{padding:.85rem 1rem}
  .topbar{padding:.65rem 1rem}
  .stats-row{gap:.45rem}
  .stat-card{padding:.65rem .8rem}
  .stat-val{font-size:1.25rem}
  .student-name,.student-email{max-width:130px}
}
</style>
</head>
<body>

<!-- ── Topbar ── -->
<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-icon"><i class="bi bi-people-fill"></i></div>
    <div>
      <div class="topbar-title">Weekly Students</div>
      <div class="topbar-sub">Booking tracker</div>
    </div>
  </div>

  <div class="topbar-spacer"></div>

  <!-- Week navigation -->
  <div class="week-nav">
    <a class="nav-btn" href="?w=<?= $weekOffset - 1 ?>" title="Previous week"><i class="bi bi-chevron-left"></i></a>

    <?php if (!$isThisWeek): ?>
    <a class="nav-btn" href="?w=0" title="Back to current week" style="font-size:.65rem;font-weight:700;width:auto;padding:0 .6rem;color:var(--accent)">
      This week
    </a>
    <?php endif; ?>

    <span class="week-label <?= $isThisWeek ? 'current' : '' ?>">
      <?php if ($isThisWeek): ?><i class="bi bi-calendar-check" style="margin-right:.3rem"></i><?php endif; ?>
      <?= htmlspecialchars($weekLabel) ?>
    </span>

    <a class="nav-btn" href="?w=<?= $weekOffset + 1 ?>" title="Next week"><i class="bi bi-chevron-right"></i></a>
  </div>

  <div class="topbar-spacer"></div>

  <nav class="pillnav">
    <a href="index.php<?= $token ? '?token='.urlencode($token) : '' ?>">Schedule</a>
    <a href="weekly.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="active">Weekly</a>
    <a href="monthly.php<?= $token ? '?token='.urlencode($token) : '' ?>">Monthly</a>
    <a href="overtime.php<?= $token ? '?token='.urlencode($token) : '' ?>">Overtime</a>
    <a href="notifications.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="has-badge">Notifications<span class="pill-badge" id="pillNotifBadge"></span></a>
  </nav>
</div>

<div class="page">

<?php if ($fetchError): ?>
<div class="alert"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($fetchError) ?></div>
<?php elseif (!$token): ?>
<div class="alert warn"><i class="bi bi-key-fill"></i> No token found. Open this page from the Daily View with your token, or add <code>?token=…</code> to the URL.</div>
<?php else: ?>

<!-- ── Stat cards ── -->
<div class="stats-row">
  <div class="stat-card">
    <div class="stat-icon indigo"><i class="bi bi-people-fill"></i></div>
    <div><div class="stat-val"><?= $uniqueStudents ?></div><div class="stat-lbl">Students</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="bi bi-calendar2-week-fill"></i></div>
    <div><div class="stat-val"><?= $totalBooked ?></div><div class="stat-lbl">Total Classes</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
    <div><div class="stat-val"><?= $totalAttended ?></div><div class="stat-lbl">Attended</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow"><i class="bi bi-alarm-fill"></i></div>
    <div><div class="stat-val"><?= $totalLate ?></div><div class="stat-lbl">Late</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red"><i class="bi bi-person-x-fill"></i></div>
    <div><div class="stat-val"><?= $totalAbsent ?></div><div class="stat-lbl">Absent</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon indigo"><i class="bi bi-clock-fill"></i></div>
    <div><div class="stat-val"><?= $totalReserved ?></div><div class="stat-lbl">Reserved</div></div>
  </div>
</div>

<!-- ── Toolbar ── -->
<div class="toolbar">
  <div class="search-wrap">
    <i class="bi bi-search"></i>
    <input type="text" id="searchInput" placeholder="Search name or email…" oninput="filterTable()" autocomplete="off">
  </div>

  <select class="filter-select" id="filterStatus" onchange="filterTable()">
    <option value="">All statuses</option>
    <option value="attended">Has attended</option>
    <option value="late">Has late</option>
    <option value="absent">Has absent</option>
    <option value="reserved">Reserved only</option>
    <option value="multi">Booked 2+ days</option>
  </select>

  <select class="filter-select" id="filterDay" onchange="filterTable()">
    <option value="">All days</option>
    <?php foreach ($days as $i => $d): ?>
    <option value="<?= $d ?>"><?= $dayShort[$i] ?> · <?= date('M j', strtotime($d)) ?></option>
    <?php endforeach; ?>
  </select>

  <span class="row-count" id="rowCount"></span>
</div>

<!-- Legend -->
<div class="legend-strip">
  <span class="leg"><span class="leg-dot" style="background:var(--green)"></span> Attended</span>
  <span class="leg"><span class="leg-dot" style="background:var(--amber)"></span> Late</span>
  <span class="leg"><span class="leg-dot" style="background:var(--red)"></span> Absent</span>
  <span class="leg"><span class="leg-dot" style="background:var(--accent)"></span> Reserved</span>
</div>

<?php if (!$students): ?>
<div class="empty-state">
  <i class="bi bi-calendar-x"></i>
  <strong>No bookings this week</strong>
  Try a different week using the arrows above.
</div>
<?php else: ?>

<!-- ── Table ── -->
<div class="tbl-wrap">
  <div class="tbl-scroll">
  <table id="studentsTable">
    <thead>
      <tr>
        <th class="th-num">#</th>
        <th style="min-width:200px">Student</th>
        <?php foreach ($days as $i => $d):
          $isToday  = ($d === $todayStr);
          $barPct   = round(($dayBookingCount[$d] / $maxDayCount) * 100);
          $thCls    = $isToday ? ' col-today' : '';
        ?>
        <th class="th-day<?= $thCls ?>">
          <div class="day-head-inner">
            <span class="day-name"><?= $dayShort[$i] ?></span>
            <span class="day-date"><?= date('M j', strtotime($d)) ?></span>
            <div class="day-bar-wrap"><div class="day-bar" style="width:<?= $barPct ?>%;<?= $isToday?'opacity:.9':''; ?>"></div></div>
            <?php if ($isToday): ?><span class="today-pip"></span><?php endif; ?>
          </div>
        </th>
        <?php endforeach; ?>
        <th class="th-total">Total</th>
        <th class="th-stat" title="Attended"><i class="bi bi-check-circle" style="color:var(--green)"></i></th>
        <th class="th-stat" title="Late"><i class="bi bi-alarm" style="color:var(--amber)"></i></th>
        <th class="th-stat" title="Absent"><i class="bi bi-person-x" style="color:var(--red)"></i></th>
      </tr>
    </thead>
    <tbody id="tableBody">
      <?php
      $rank = 0;
      foreach ($students as $email => $stu):
        $rank++;
        $rankCls = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
        $avColor = avatarColor($stu['nameEn']);
        $avInit  = initials($stu['nameEn']);
      ?>
      <tr data-name="<?= strtolower(htmlspecialchars($stu['nameEn'])) ?>"
          data-email="<?= strtolower(htmlspecialchars($email)) ?>"
          data-attended="<?= $stu['attended'] > 0 ? '1' : '0' ?>"
          data-late="<?= $stu['late']       > 0 ? '1' : '0' ?>"
          data-absent="<?= $stu['absent']   > 0 ? '1' : '0' ?>"
          data-reserved="<?= ($stu['reserved'] > 0 && $stu['attended'] === 0 && $stu['absent'] === 0 && $stu['late'] === 0) ? '1' : '0' ?>"
          data-multi="<?= $stu['count'] >= 2 ? '1' : '0' ?>"
          data-days="<?= htmlspecialchars(implode(',', array_keys($stu['days']))) ?>">

        <td class="td-stat"><span class="rank-badge <?= $rankCls ?>"><?= $rank ?></span></td>

        <td>
          <div class="name-cell-inner">
            <div class="avatar" style="background:<?= $avColor ?>"><?= htmlspecialchars($avInit) ?></div>
            <div style="min-width:0">
              <div class="student-name"><?= htmlspecialchars($stu['nameEn']) ?></div>
              <?php if ($stu['nameJa']): ?><div class="student-ja"><?= htmlspecialchars($stu['nameJa']) ?></div><?php endif; ?>
              <?php if ($stu['email'] !== '-'): ?><div class="student-email"><?= htmlspecialchars($stu['email']) ?></div><?php endif; ?>
            </div>
          </div>
        </td>

        <?php foreach ($days as $d):
          $isTd     = ($d === $todayStr);
          $tdCls    = $isTd ? ' td-today' : '';
          $daySlots = $stu['days'][$d] ?? [];
        ?>
        <td class="td-day<?= $tdCls ?>">
          <?php if ($daySlots): ?>
          <div class="day-slots">
            <?php foreach ($daySlots as $sl):
              $chipCls = match($sl['status']) { 'attended'=>'att','late'=>'late','no show'=>'abs',default=>'res' };
            ?>
            <span class="slot-chip <?= $chipCls ?>">
              <?= htmlspecialchars($sl['time']) ?>
            </span>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <span class="day-empty">—</span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>

        <td class="td-total"><span class="total-bubble"><?= $stu['count'] ?></span></td>
        <td class="td-stat">
          <?php if ($stu['attended']): ?>
          <span class="sb sb-att"><?= $stu['attended'] ?></span>
          <?php else: ?><span class="sb-nil">—</span><?php endif; ?>
        </td>
        <td class="td-stat">
          <?php if ($stu['late']): ?>
          <span class="sb sb-late"><?= $stu['late'] ?></span>
          <?php else: ?><span class="sb-nil">—</span><?php endif; ?>
        </td>
        <td class="td-stat">
          <?php if ($stu['absent']): ?>
          <span class="sb sb-abs"><?= $stu['absent'] ?></span>
          <?php else: ?><span class="sb-nil">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>
<?php endif; ?>
</div><!-- /page -->

<script>
function filterTable() {
    const q         = document.getElementById('searchInput').value.toLowerCase().trim();
    const status    = document.getElementById('filterStatus').value;
    const dayFilter = document.getElementById('filterDay').value;
    const rows      = document.querySelectorAll('#tableBody tr');
    let   visible   = 0;

    rows.forEach(tr => {
        const matchQ = !q || (tr.dataset.name||'').includes(q) || (tr.dataset.email||'').includes(q);
        let matchSt  = true;
        if      (status === 'attended') matchSt = tr.dataset.attended === '1';
        else if (status === 'late')     matchSt = tr.dataset.late     === '1';
        else if (status === 'absent')   matchSt = tr.dataset.absent   === '1';
        else if (status === 'reserved') matchSt = tr.dataset.reserved === '1';
        else if (status === 'multi')    matchSt = tr.dataset.multi    === '1';
        const matchDay = !dayFilter || (tr.dataset.days||'').split(',').includes(dayFilter);

        const show = matchQ && matchSt && matchDay;
        tr.classList.toggle('hidden-row', !show);
        if (show) visible++;
    });

    const rc = document.getElementById('rowCount');
    if (rc) rc.textContent = `${visible} student${visible !== 1 ? 's' : ''}`;
}

document.addEventListener('DOMContentLoaded', () => {
    const rows = document.querySelectorAll('#tableBody tr');
    const rc   = document.getElementById('rowCount');
    if (rc) rc.textContent = `${rows.length} student${rows.length !== 1 ? 's' : ''}`;
});
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