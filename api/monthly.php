<?php
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

// ── Month navigation: offset from current month ──
$monthOffset = isset($_GET['m']) ? (int)$_GET['m'] : 0;
$today       = new DateTime('today', new DateTimeZone('Asia/Manila'));
$todayStr    = $today->format('Y-m-d');

$firstOfMonth = (new DateTime('first day of this month', new DateTimeZone('Asia/Manila')))->modify("{$monthOffset} months");
$lastOfMonth  = (clone $firstOfMonth)->modify('last day of this month');
$monthLabel   = $firstOfMonth->format('F Y');
$isThisMonth  = ($monthOffset === 0);

// Grid: Monday-start weeks that fully cover the month
$dowStart  = (int)$firstOfMonth->format('N'); // 1=Mon…7=Sun
$gridStart = (clone $firstOfMonth)->modify('-'.($dowStart - 1).' days');
$dowEnd    = (int)$lastOfMonth->format('N');
$gridEnd   = (clone $lastOfMonth)->modify('+'.(7 - $dowEnd).' days');

$gridDays = [];
$cursor   = clone $gridStart;
while ($cursor <= $gridEnd) {
    $gridDays[] = $cursor->format('Y-m-d');
    $cursor->modify('+1 day');
}

// ── Fetch ──
$dayStats   = []; // Y-m-d => ['total'=>,'attended'=>,'late'=>,'absent'=>,'open'=>,'reserved'=>]
$daySlots   = []; // Y-m-d => [ ['time'=>,'name'=>,'email'=>,'status'=>], ... ] sorted by time
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
         . "?start_date={$firstOfMonth->format('Y-m-d')}&end_date={$lastOfMonth->format('Y-m-d')}";
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

        foreach ($raw as $s) {
            $st  = (new DateTime($s['start'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
            $dk  = $st->format('Y-m-d');
            $r   = $s['reservation'] ?? null;
            $rv  = $r['reservee'] ?? null;
            $fallbackStatus = $r['status'] ?? ($s['is_reserved'] ? 'reserved' : 'open');
            $status = $r ? resolveAttendanceStatus($r['teacher_notes'] ?? null, $fallbackStatus) : $fallbackStatus;
            if ($status === 'cancelled') continue;

            if (!isset($dayStats[$dk])) {
                $dayStats[$dk] = ['total'=>0,'attended'=>0,'late'=>0,'absent'=>0,'open'=>0,'reserved'=>0];
            }
            $dayStats[$dk]['total']++;
            if ($status === 'attended')      $dayStats[$dk]['attended']++;
            elseif ($status === 'late')      $dayStats[$dk]['late']++;
            elseif ($status === 'no show')   $dayStats[$dk]['absent']++;
            elseif ($status === 'open')      $dayStats[$dk]['open']++;
            else                              $dayStats[$dk]['reserved']++;

            $nameEn = $rv['name_en'] ?? null;
            $nameJa = $rv['name_ja'] ?? null;
            $name   = $nameEn ?: ($nameJa ?: null);
            $daySlots[$dk][] = [
                'time'   => $st->format('H:i'),
                'tsRaw'  => $st->getTimestamp(),
                'name'   => $name,
                'email'  => $rv['email'] ?? null,
                'status' => $status,
            ];
        }
        foreach ($daySlots as $dk => &$slots) {
            usort($slots, fn($a, $b) => $a['tsRaw'] <=> $b['tsRaw']);
        }
        unset($slots);
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

// ── Month totals (actual month days only, not the leading/trailing grid days) ──
$mTotal = $mAttended = $mLate = $mAbsent = $mOpen = 0;
foreach ($dayStats as $dk => $st) {
    if ($dk < $firstOfMonth->format('Y-m-d') || $dk > $lastOfMonth->format('Y-m-d')) continue;
    $mTotal    += $st['total'];
    $mAttended += $st['attended'];
    $mLate     += $st['late'];
    $mAbsent   += $st['absent'];
    $mOpen     += $st['open'];
}

$dayShort = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

// ── Flat searchable dataset for the student-name search box ──
$searchData = [];
foreach ($daySlots as $dk => $slots) {
    if ($dk < $firstOfMonth->format('Y-m-d') || $dk > $lastOfMonth->format('Y-m-d')) continue;
    foreach ($slots as $sl) {
        if (empty($sl['name'])) continue; // skip open/unbooked slots
        $searchData[] = [
            'date'   => $dk,
            'label'  => (new DateTime($dk))->format('M j (D)'),
            'time'   => $sl['time'],
            'name'   => $sl['name'],
            'email'  => $sl['email'],
            'status' => $sl['status'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Monthly View</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2312805c'/%3E%3Crect x='7' y='5' width='18' height='22' rx='3' fill='%23fff'/%3E%3Crect x='10' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='19' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='10' y='13' width='12' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='17' width='8' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='21' width='5' height='1.5' rx='.75' fill='%2312805c'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
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
  --radius:14px;
  --radius-sm:10px;
}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:var(--bg);
  color:var(--text);
  font-size:13.5px;
  line-height:1.5;
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

.month-nav{display:flex;align-items:center;gap:.4rem}
.month-label{
  font-size:.8rem;font-weight:700;color:var(--accent);
  background:var(--accent-soft);
  border-radius:999px;padding:.32rem .95rem;white-space:nowrap;
}
.month-label.current{background:var(--accent);color:#fff}
.nav-btn{
  display:flex;align-items:center;justify-content:center;
  width:30px;height:30px;border-radius:50%;
  background:var(--surface2);border:none;
  color:var(--text2);text-decoration:none;font-size:.85rem;
  transition:background .15s,color .15s;
  cursor:pointer;
}
.nav-btn:hover{background:var(--accent-soft);color:var(--accent)}
.jump-link{
  font-size:.65rem;font-weight:700;width:auto;padding:0 .6rem;
  color:var(--accent);text-decoration:none;
}
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

/* ── Page ── */
.page{max-width:1100px;margin:0 auto;padding:1.2rem 1.5rem 2.5rem}

/* ── Alert ── */
.alert{
  padding:.8rem 1rem;border-radius:var(--radius-sm);
  font-size:.84rem;font-weight:500;
  color:var(--text);background:var(--surface2);border:1px solid var(--border);
  margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem;
}

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

/* ── Legend ── */
.legend-strip{
  display:flex;gap:1rem;align-items:center;
  font-size:.7rem;font-weight:600;color:var(--text2);
  padding:0 0 .75rem;flex-wrap:wrap;
}
.leg{display:flex;align-items:center;gap:.35rem}
.leg-dot{width:7px;height:7px;border-radius:50%}

/* ── Calendar ── */
.cal-wrap{
  background:var(--surface);
  border-radius:var(--radius);
  border:1px solid var(--border);
  overflow:hidden;
}
.cal-weekdays{
  display:grid;grid-template-columns:repeat(7,1fr);
  background:var(--surface2);
  border-bottom:1px solid var(--border);
}
.cal-weekdays span{
  padding:.55rem 0;text-align:center;
  font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text3);
}
.cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
.cal-cell{
  min-height:118px;
  min-width:0;
  padding:.5rem .55rem;
  border-right:1px solid var(--border);
  border-bottom:1px solid var(--border);
  text-decoration:none;
  color:inherit;
  display:flex;
  flex-direction:column;
  gap:.3rem;
  overflow:hidden;
  transition:background .15s,opacity .15s,outline .15s;
}
.cal-grid a.cal-cell:hover{background:var(--surface2)}
.cal-cell:nth-child(7n){border-right:none}
.cal-date{font-size:.8rem;font-weight:600;color:var(--text2)}
.cal-cell.outside .cal-date{color:var(--border2)}
.cal-cell.today .cal-date{
  color:#fff;background:var(--accent);
  width:22px;height:22px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-weight:700;
}
.cal-total{font-size:.72rem;font-weight:700;color:var(--text)}
.cal-breakdown{font-size:.66rem;font-weight:600;display:flex;flex-wrap:wrap;gap:.35rem}
.cb-att{color:var(--green)}
.cb-late{color:var(--amber)}
.cb-abs{color:var(--red)}
.cb-open{color:var(--text3)}

/* ── Search ── */
.search-row{margin-bottom:1rem}
.search-box{
  position:relative;
  max-width:420px;
}
.search-box i.bi-search{
  position:absolute;left:.85rem;top:50%;transform:translateY(-50%);
  color:var(--text3);font-size:.85rem;pointer-events:none;
}
.search-box input{
  width:100%;
  font-family:inherit;
  font-size:.85rem;
  padding:.6rem .8rem .6rem 2.2rem;
  border-radius:999px;
  border:1px solid var(--border);
  background:var(--surface2);
  color:var(--text);
  outline:none;
  transition:border-color .15s,background .15s;
}
.search-box input:focus{border-color:var(--accent);background:#fff}
.search-clear{
  position:absolute;right:.5rem;top:50%;transform:translateY(-50%);
  width:22px;height:22px;border-radius:50%;
  display:none;align-items:center;justify-content:center;
  background:var(--border);border:none;color:var(--text2);
  cursor:pointer;font-size:.7rem;
}
.search-box.has-value .search-clear{display:flex}
.search-summary{
  font-size:.72rem;font-weight:600;color:var(--text3);
  margin-top:.5rem;display:none;
}
.search-summary.show{display:block}
.search-results{
  margin-top:.5rem;
  border-radius:var(--radius-sm);
  border:1px solid var(--border);
  overflow:hidden;
  display:none;
  max-height:280px;
  overflow-y:auto;
}
.search-results.show{display:block}
.sr-item{
  display:flex;align-items:center;gap:.6rem;
  padding:.55rem .85rem;
  text-decoration:none;color:inherit;
  border-bottom:1px solid var(--border);
  font-size:.78rem;
}
.sr-item:last-child{border-bottom:none}
.sr-item:hover{background:var(--surface2)}
.sr-date{font-weight:700;color:var(--accent);min-width:78px;flex-shrink:0}
.sr-time{color:var(--text3);min-width:48px;flex-shrink:0}
.sr-name{font-weight:600;flex:1;color:var(--text)}
.sr-status{
  font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
  padding:.15rem .45rem;border-radius:999px;flex-shrink:0;
}
.sr-status.attended{color:var(--green);background:#eafaf0}
.sr-status.late{color:var(--amber);background:#fef6e8}
.sr-status.reserved{color:var(--blue);background:#eaf1fd}
.sr-status.no-show,.sr-status.absent{color:var(--red);background:#fdecec}
.search-empty{padding:.85rem;font-size:.78rem;color:var(--text3);text-align:center}

/* ── Name chips inside calendar cells ── */
.cal-names{display:flex;flex-direction:column;gap:.12rem;margin-top:.15rem;min-width:0}
.cal-name{
  font-size:.6rem;font-weight:600;color:var(--text2);
  line-height:1.3;
  display:flex;gap:.28rem;align-items:baseline;
  flex-wrap:nowrap;
  white-space:nowrap;
  min-width:0;
}
.cal-name .nm-time{color:var(--text3);font-weight:600;flex-shrink:0;white-space:nowrap}
.cal-name .nm-who{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.nm-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.nm-dot.ns-attended{background:var(--green)}
.nm-dot.ns-late{background:var(--amber)}
.nm-dot.ns-absent{background:var(--red)}
.nm-dot.ns-reserved{background:var(--blue)}
.cal-name-more{font-size:.6rem;font-weight:600;color:var(--text3)}
.cal-cell.dimmed{opacity:.25}
.cal-cell.search-match{outline:2px solid var(--accent);outline-offset:-2px;background:var(--accent-soft)}
.cal-name.name-hit .nm-who{background:#fff3b0;border-radius:3px;padding:0 .15rem}

/* ── Responsive ── */
@media(max-width:680px){
  .page{padding:.85rem 1rem 2.5rem}
  .topbar{padding:.65rem 1rem}
  .stats-row{gap:.45rem}
  .stat-card{padding:.65rem .8rem}
  .stat-val{font-size:1.25rem}
  .cal-cell{min-height:70px;padding:.35rem .3rem;gap:.2rem}
  .cal-breakdown{font-size:.6rem;gap:.25rem}
  .cal-weekdays span{font-size:.55rem}
  .view-link span.txt{display:none}
  .cal-name{font-size:.56rem;gap:.2rem}
  .cal-names{gap:.12rem}
  .sr-date{min-width:60px}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-icon"><i class="bi bi-calendar-month-fill"></i></div>
    <div>
      <div class="topbar-title">Monthly View</div>
      <div class="topbar-sub">Calendar overview</div>
    </div>
  </div>

  <div class="topbar-spacer"></div>

  <div class="month-nav">
    <a class="nav-btn" href="?m=<?= $monthOffset - 1 ?>" title="Previous month"><i class="bi bi-chevron-left"></i></a>
    <?php if (!$isThisMonth): ?>
    <a class="nav-btn jump-link" href="?m=0" title="Back to current month">This month</a>
    <?php endif; ?>
    <span class="month-label <?= $isThisMonth ? 'current' : '' ?>">
      <?php if ($isThisMonth): ?><i class="bi bi-calendar-check" style="margin-right:.3rem"></i><?php endif; ?>
      <?= htmlspecialchars($monthLabel) ?>
    </span>
    <a class="nav-btn" href="?m=<?= $monthOffset + 1 ?>" title="Next month"><i class="bi bi-chevron-right"></i></a>
  </div>

  <div class="topbar-spacer"></div>

  <nav class="pillnav">
    <a href="index.php<?= $token ? '?token='.urlencode($token) : '' ?>">Schedule</a>
    <a href="weekly.php<?= $token ? '?token='.urlencode($token) : '' ?>">Weekly</a>
    <a href="monthly.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="active">Monthly</a>
    <a href="overtime.php<?= $token ? '?token='.urlencode($token) : '' ?>">Overtime</a>
    <a href="notifications.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="has-badge">Notifications<span class="pill-badge" id="pillNotifBadge"></span></a>
  </nav>
</div>

<div class="page">

<?php if ($fetchError): ?>
  <div class="alert"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($fetchError) ?></div>
<?php elseif (!$token): ?>
  <div class="alert"><i class="bi bi-key-fill"></i> No token found. Open this page from the Daily View with your token, or add <code>?token=…</code> to the URL.</div>
<?php else: ?>

<div class="search-row">
  <div class="search-box" id="searchBox">
    <i class="bi bi-search"></i>
    <input type="text" id="searchInput" placeholder="Search student name..." autocomplete="off">
    <button type="button" class="search-clear" id="searchClear" aria-label="Clear search"><i class="bi bi-x"></i></button>
  </div>
  <div class="search-summary" id="searchSummary"></div>
  <div class="search-results" id="searchResults"></div>
</div>

<div class="stats-row">
  <div class="stat-card">
    <div class="stat-icon blue"><i class="bi bi-calendar2-week-fill"></i></div>
    <div><div class="stat-val"><?= $mTotal ?></div><div class="stat-lbl">Total Classes</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
    <div><div class="stat-val"><?= $mAttended ?></div><div class="stat-lbl">Attended</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow"><i class="bi bi-alarm-fill"></i></div>
    <div><div class="stat-val"><?= $mLate ?></div><div class="stat-lbl">Late</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red"><i class="bi bi-person-x-fill"></i></div>
    <div><div class="stat-val"><?= $mAbsent ?></div><div class="stat-lbl">Absent</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon indigo"><i class="bi bi-inbox-fill"></i></div>
    <div><div class="stat-val"><?= $mOpen ?></div><div class="stat-lbl">No Booking</div></div>
  </div>
</div>

<div class="legend-strip">
  <span class="leg"><span class="leg-dot" style="background:var(--blue)"></span> Reserved</span>
  <span class="leg"><span class="leg-dot" style="background:var(--green)"></span> A = Attended</span>
  <span class="leg"><span class="leg-dot" style="background:var(--amber)"></span> L = Late</span>
  <span class="leg"><span class="leg-dot" style="background:var(--red)"></span> X = Absent</span>
  <span class="leg"><span class="leg-dot" style="background:var(--text3)"></span> O = Open (no booking yet)</span>
</div>

<div class="cal-wrap">
  <div class="cal-weekdays">
    <?php foreach ($dayShort as $dn): ?><span><?= $dn ?></span><?php endforeach; ?>
  </div>
  <div class="cal-grid">
    <?php foreach ($gridDays as $d):
      $dt        = new DateTime($d);
      $isOutside = ($d < $firstOfMonth->format('Y-m-d') || $d > $lastOfMonth->format('Y-m-d'));
      $isToday   = ($d === $todayStr);
      // Only this month's own days carry data — padding days from adjacent months stay blank.
      $st        = !$isOutside ? ($dayStats[$d] ?? null) : null;
      $slots     = !$isOutside ? ($daySlots[$d] ?? []) : [];
      $named     = array_values(array_filter($slots, fn($sl) => !empty($sl['name'])));
      $cls       = 'cal-cell' . ($isOutside ? ' outside' : '') . ($isToday ? ' today' : '');
      $href      = 'index.php?date='.$d.($token ? '&token='.urlencode($token) : '');
    ?>
    <a class="<?= $cls ?>" href="<?= htmlspecialchars($href) ?>" data-date="<?= $d ?>">
      <span class="cal-date"><?= (int)$dt->format('j') ?></span>
      <?php if ($st && $st['total'] > 0): ?>
        <span class="cal-total"><?= $st['total'] ?> class<?= $st['total']===1?'':'es' ?></span>
        <span class="cal-breakdown">
          <?php if ($st['attended']): ?><span class="cb-att" title="<?= $st['attended'] ?> Attended"><?= $st['attended'] ?>A</span><?php endif; ?>
          <?php if ($st['late']):     ?><span class="cb-late" title="<?= $st['late'] ?> Late"><?= $st['late'] ?>L</span><?php endif; ?>
          <?php if ($st['absent']):   ?><span class="cb-abs" title="<?= $st['absent'] ?> Absent"><?= $st['absent'] ?>X</span><?php endif; ?>
          <?php if ($st['open']):     ?><span class="cb-open" title="<?= $st['open'] ?> Open — no booking yet"><?= $st['open'] ?>O</span><?php endif; ?>
        </span>
        <?php if ($named): ?>
        <span class="cal-names">
          <?php foreach ($named as $sl):
            $dotClass = 'ns-reserved';
            if ($sl['status'] === 'attended') $dotClass = 'ns-attended';
            elseif ($sl['status'] === 'late') $dotClass = 'ns-late';
            elseif ($sl['status'] === 'no show') $dotClass = 'ns-absent';
          ?>
          <span class="cal-name" data-name="<?= htmlspecialchars(mb_strtolower($sl['name'])) ?>">
            <span class="nm-dot <?= $dotClass ?>"></span>
            <span class="nm-time"><?= htmlspecialchars($sl['time']) ?></span>
            <span class="nm-who" title="<?= htmlspecialchars($sl['name']) ?>"><?= htmlspecialchars($sl['name']) ?></span>
          </span>
          <?php endforeach; ?>
        </span>
        <?php endif; ?>
      <?php endif; ?>
    </a>
        <?php endforeach; ?>
  </div>
</div>

<?php endif; ?>
</div>

<script id="searchData" type="application/json"><?= json_encode($searchData, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script>window.__klToken = <?= json_encode($token) ?>;</script>
<script>
(function(){
  var data = [];
  try { data = JSON.parse(document.getElementById('searchData').textContent || '[]'); } catch(e) {}

  var input   = document.getElementById('searchInput');
  var box     = document.getElementById('searchBox');
  var clearBt = document.getElementById('searchClear');
  var summary = document.getElementById('searchSummary');
  var results = document.getElementById('searchResults');
  var cells   = document.querySelectorAll('.cal-cell');

  function statusClass(s){
    if (s === 'no show') return 'no-show';
    return s || 'reserved';
  }
  function statusLabel(s){
    if (s === 'no show') return 'Absent';
    if (s === 'attended') return 'Attended';
    if (s === 'late') return 'Late';
    return 'Reserved';
  }

  function render(q){
    var query = q.trim().toLowerCase();
    box.classList.toggle('has-value', query.length > 0);

    // Reset all cells/name highlights
    cells.forEach(function(c){
      c.classList.remove('dimmed','search-match');
      c.querySelectorAll('.cal-name').forEach(function(n){ n.classList.remove('name-hit'); });
    });

    if (!query) {
      summary.classList.remove('show');
      results.classList.remove('show');
      results.innerHTML = '';
      return;
    }

    var matches = data.filter(function(row){
      return row.name && row.name.toLowerCase().indexOf(query) !== -1;
    });

    // Dim non-matching cells, highlight matching ones + their name chips
    var matchDates = {};
    matches.forEach(function(m){ matchDates[m.date] = true; });
    cells.forEach(function(c){
      var d = c.getAttribute('data-date');
      if (matchDates[d]) {
        c.classList.add('search-match');
        c.querySelectorAll('.cal-name').forEach(function(n){
          if ((n.getAttribute('data-name')||'').indexOf(query) !== -1) n.classList.add('name-hit');
        });
      } else {
        c.classList.add('dimmed');
      }
    });

    summary.classList.add('show');
    summary.textContent = matches.length
      ? matches.length + ' booking' + (matches.length === 1 ? '' : 's') + ' found for "' + q.trim() + '"'
      : 'No bookings found for "' + q.trim() + '"';

    results.classList.add('show');
    if (!matches.length) {
      results.innerHTML = '<div class="search-empty">No matching students this month.</div>';
      return;
    }

    var html = matches.map(function(m){
      var href = 'index.php?date=' + m.date + (window.__klToken ? ('&token=' + encodeURIComponent(window.__klToken)) : '');
      return '<a class="sr-item" href="' + href + '">'
        + '<span class="sr-date">' + m.label + '</span>'
        + '<span class="sr-time">' + m.time + '</span>'
        + '<span class="sr-name">' + m.name + '</span>'
        + '<span class="sr-status ' + statusClass(m.status) + '">' + statusLabel(m.status) + '</span>'
        + '</a>';
    }).join('');
    results.innerHTML = html;
  }

  input.addEventListener('input', function(){ render(input.value); });
  clearBt.addEventListener('click', function(){ input.value = ''; render(''); input.focus(); });
})();
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
