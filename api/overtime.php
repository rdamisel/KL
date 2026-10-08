<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/kl_helpers.php';

$token = '';
if (!empty($_GET['token'])) {
    $token = trim($_GET['token']);
    setcookie('kl_token', $token, time()+60*60*24*30, '/');
} elseif (!empty($_COOKIE['kl_token'])) {
    $token = $_COOKIE['kl_token'];
}
$teacherId = $token ? kl_resolve_teacher_id($token) : '';
$date = !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');

function currentPeriod(string $dateStr): array {
    $dt  = new DateTime($dateStr);
    $day = (int)$dt->format('j');
    $y   = (int)$dt->format('Y');
    $m   = (int)$dt->format('n');
    $mp  = str_pad($m, 2, '0', STR_PAD_LEFT);
    if ($day >= 6 && $day <= 20) {
        return ['start'=>"{$y}-{$mp}-06",'end'=>"{$y}-{$mp}-20",'label'=>'30th Cut-off','cutoff'=>30];
    } elseif ($day >= 21) {
        $nx = (new DateTime("{$y}-{$mp}-21"))->modify('first day of next month');
        return ['start'=>"{$y}-{$mp}-21",'end'=>$nx->format('Y-m').'-05','label'=>'15th Cut-off','cutoff'=>15];
    } else {
        $pv = (new DateTime("{$y}-{$m}-01"))->modify('-1 month');
        return ['start'=>$pv->format('Y-m').'-21','end'=>"{$y}-{$mp}-05",'label'=>'15th Cut-off','cutoff'=>15];
    }
}
function prevPeriod(array $cur): array { $b=(new DateTime($cur['start']))->modify('-1 day'); return currentPeriod($b->format('Y-m-d')); }
function nextPeriod(array $cur): array { $a=(new DateTime($cur['end']))->modify('+1 day'); return currentPeriod($a->format('Y-m-d')); }
function fmtRange(string $s, string $e): string {
    return (new DateTime($s))->format('M j').' – '.(new DateTime($e))->format('M j, Y');
}

$cur  = currentPeriod($date);
$prev = prevPeriod($cur);
$next = nextPeriod($cur);

/**
 * Fetch schedules for the period.
 * Clamps end_date to today so we never request future dates,
 * but still fetches whatever has already passed within the period.
 * Returns [] only if the period hasn't started at all yet.
 */
function fetchPeriod(string $tid, string $tok, string $start, string $end, ?string &$errOut = null): array {
    // Fetch the full period range including future dates —
    // the Kredo API returns already-scheduled future slots too.
    $url = "https://api.kredo-learning.com/v2/teachers/{$tid}/schedules?start_date={$start}&end_date={$end}";
    $ch  = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Accept: application/json", "Authorization: Bearer {$tok}"],
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
        return $body['data'] ?? $body['schedules'] ?? $body ?? [];
    }

    if ($code === 0) {
        if ($curlErrno === 77) {
            $diag = $caFound
                ? "cacert.pem was found at {$caBundle} but curl still rejected it — file may be corrupted/truncated, or this host's curl/OpenSSL build ignores CURLOPT_CAINFO."
                : "cacert.pem was NOT found at {$caBundle} — check the file was uploaded to this exact folder (script dir: " . __DIR__ . ").";
            $errOut = "SSL cert bundle missing on this host (curl #77). {$diag}";
        } else {
            $errOut = $curlErr
                ? "Connection failed (curl #{$curlErrno}): {$curlErr}"
                : "Connection failed — could not reach the Kredo API (no HTTP response received).";
        }
    } elseif ($code === 401 || $code === 403) {
        $errOut = "HTTP {$code} — token expired or invalid.";
    } else {
        $errOut = "HTTP {$code} — request failed.";
    }
    return [];
}

function calcOT(array $raw): array {
    $sat=$sun=0; $satDays=$sunDays=[];
    foreach ($raw as $s) {
        $dt  = (new DateTime($s['start'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
        $dow = (int)$dt->format('w');
        $dk  = $dt->format('Y-m-d');
        $dl  = $dt->format('M j');
        if ($dow===6) {
            if (!isset($satDays[$dk])) $satDays[$dk]=['label'=>$dl,'total'=>0,'reserved'=>0];
            $satDays[$dk]['total']++;
            $sat++;
            if ($s['is_reserved']??false) $satDays[$dk]['reserved']++;
        }
        if ($dow===0) {
            if (!isset($sunDays[$dk])) $sunDays[$dk]=['label'=>$dl,'total'=>0,'reserved'=>0];
            $sunDays[$dk]['total']++;
            $sun++;
            if ($s['is_reserved']??false) $sunDays[$dk]['reserved']++;
        }
    }
    return ['sat'=>$sat,'sun'=>$sun,'total'=>$sat+$sun,'satDays'=>$satDays,'sunDays'=>$sunDays];
}

$today  = date('Y-m-d');
$error  = null;
$periods = [];

if ($token && !$teacherId) {
    $error = "Couldn't detect your Teacher ID automatically. Open the Daily View and enter it in the Teacher ID box.";
} elseif ($token) {
    foreach([
        ['id'=>'prev','meta'=>$prev],
        ['id'=>'cur', 'meta'=>$cur],
        ['id'=>'next','meta'=>$next],
    ] as $p) {
        $m       = $p['meta'];
        $fetchErr = null;
        $raw = fetchPeriod($teacherId, $token, $m['start'], $m['end'], $fetchErr);
        if ($fetchErr && !$error) $error = $fetchErr;
        $ot  = calcOT($raw);

        // hasData = API returned at least one weekend slot
        $hasData   = $ot['total'] > 0;
        // isPartial = period is currently in-progress
        $isPartial = ($m['start'] <= $today && $m['end'] > $today);

        $periods[] = [
            'id'        => $p['id'],
            'meta'      => $m,
            'ot'        => $ot,
            'hasData'   => $hasData,
            'isPartial' => $isPartial,
        ];
    }
} else {
    $error = "No token.";
}

$backUrl = 'index.php?token='.urlencode($token).'&date='.urlencode($date);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weekend OT</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2312805c'/%3E%3Crect x='7' y='5' width='18' height='22' rx='3' fill='%23fff'/%3E%3Crect x='10' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='19' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='10' y='13' width='12' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='17' width='8' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='21' width='5' height='1.5' rx='.75' fill='%2312805c'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#ffffff;
  --card:#ffffff;
  --ink:#1a1a1a;
  --muted:#767676;
  --line:#e8e8e8;
  --accent:#12805c;
  --accent-soft:#e8f3ef;
  --accent-soft-hover:#d6f5e9;
  --c-red:#dc2626;
  --c-amber:#d97706;
  --c-orange:#ea580c;
  --c-blue:#2563eb;
  --c-green:#16a34a;
  --c-purple:#7c3aed;
  --c-gray:#767676;
}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:var(--bg);
  color:var(--ink);
  font-size:14px;
  line-height:1.55;
  -webkit-font-smoothing:antialiased;
  letter-spacing:-.01em;
}

/* ── Topbar ── */
.topbar{
  background:#fff;
  border-bottom:1px solid var(--line);
  padding:.85rem 1.25rem;
  display:flex;
  align-items:center;
  gap:.75rem;
  flex-wrap:wrap;
  position:sticky;
  top:0;
  z-index:50;
}
.topbar-brand{display:flex;align-items:center;gap:.5rem}
.topbar-icon{
  width:30px;height:30px;border-radius:8px;
  background:var(--accent);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:.85rem;flex-shrink:0;
}
.topbar-title{font-size:.9rem;font-weight:700;color:var(--ink);letter-spacing:-.01em}
.topbar-sub{font-size:.68rem;color:var(--muted);font-weight:500;margin-top:-.05rem}
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
  color:var(--muted);
  text-decoration:none;
  white-space:nowrap;
  transition:color .15s,background .15s;
}
.pillnav a:hover{color:var(--ink);background:#f7f7f7}
.pillnav a.active{color:var(--accent);background:var(--accent-soft)}
.pillnav a.has-badge{position:relative}
.pill-badge{display:none;position:absolute;top:.05rem;right:0;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#dc2626;color:#fff;font-size:.62rem;font-weight:800;line-height:16px;text-align:center;box-shadow:0 0 0 2px #fff}

/* ── Layout ── */
.container{
  padding:1rem;
  max-width:580px;
  margin:0 auto;
  padding-bottom:2rem;
}

/* ── Alert ── */
.alert{
  padding:.75rem 1rem;
  border-radius:12px;
  background:#fafafa;
  color:var(--ink);
  border:1px solid var(--line);
  font-size:.84rem;
  margin-bottom:.85rem;
}

/* ── Period card ── */
.pcard{
  background:#fff;
  border-radius:14px;
  border:1px solid var(--line);
  margin-bottom:.85rem;
  overflow:hidden;
}

.pcard-head{
  padding:.75rem 1rem;
  display:flex;
  align-items:center;
  justify-content:space-between;
  border-bottom:1px solid var(--line);
}
.pcard-head.prev,.pcard-head.cur,.pcard-head.next{ background:#fff; }

.ph-period{
  font-size:.64rem;
  font-weight:700;
  letter-spacing:.06em;
  text-transform:uppercase;
  margin-bottom:.2rem;
}
.pcard-head.prev .ph-period{ color:var(--muted); }
.pcard-head.cur  .ph-period{ color:var(--accent); }
.pcard-head.next .ph-period{ color:var(--c-purple); }

.ph-range{
  font-size:.9rem;
  font-weight:700;
  color:var(--ink);
}

.ph-badge{
  font-size:.68rem;
  font-weight:700;
  padding:0;
  background:none;
  letter-spacing:.01em;
  flex-shrink:0;
}
.ph-badge.done     {color:var(--muted);}
.ph-badge.active   {color:var(--accent);}
.ph-badge.partial  {color:var(--c-green);}
.ph-badge.forecast {color:var(--c-purple);}

/* ── Stats row ── */
.pcard-stats{
  display:flex;
  border-bottom:1px solid var(--line);
}
.ps{
  flex:1;
  text-align:center;
  padding:.75rem .25rem;
  border-right:1px solid var(--line);
}
.ps:last-child{border-right:none}
.ps-num{
  font-size:1.5rem;
  font-weight:700;
  line-height:1;
  letter-spacing:-.02em;
}
.ps-lbl{
  font-size:.6rem;
  font-weight:600;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:var(--muted);
  margin-top:.25rem;
}
.c-total{color:var(--c-red)}
.c-sat  {color:var(--c-amber)}
.c-sun  {color:var(--c-blue)}
.c-dim  {color:#d1d1d1}

/* ── Partial note ── */
.partial-note{
  font-size:.74rem;
  color:var(--accent);
  background:var(--accent-soft);
  padding:.5rem 1rem;
  border-bottom:1px solid var(--line);
  display:flex;
  align-items:center;
  gap:.35rem;
  font-weight:500;
}

/* ── Day list ── */
.day-list{ padding:.3rem 0; }

.sec-lbl{
  font-size:.6rem;
  font-weight:700;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:var(--muted);
  padding:.5rem 1rem .2rem;
}

.day-item{
  display:flex;
  align-items:center;
  gap:.6rem;
  padding:.45rem 1rem;
  border-bottom:1px solid #f5f5f5;
}
.day-item:last-child{border-bottom:none}

.di-pill{
  font-size:.62rem;
  font-weight:700;
  padding:0;
  background:none;
  min-width:28px;
  flex-shrink:0;
  letter-spacing:.02em;
}
.di-pill.sat{color:var(--c-orange)}
.di-pill.sun{color:var(--c-blue)}

.di-label{
  font-size:.85rem;
  font-weight:600;
  color:var(--ink);
  flex:1;
}

.di-counts{display:flex;gap:.65rem;align-items:center;flex-shrink:0}
.cnt-chip{
  font-size:.72rem;
  font-weight:600;
  padding:0;
  background:none;
  white-space:nowrap;
}
.cnt-chip.reserved{color:var(--c-green)}
.cnt-chip.nobook  {color:var(--c-amber)}
.cnt-chip.total   {color:var(--muted)}

.no-days{
  font-size:.8rem;
  color:var(--muted);
  padding:.6rem 1rem;
  text-align:center;
}

.forecast-note{
  font-size:.78rem;
  color:var(--c-purple);
  padding:.8rem 1rem;
  text-align:center;
  font-style:italic;
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-icon"><i class="bi bi-calendar-week"></i></div>
    <div>
      <div class="topbar-title">Weekend OT</div>
      <div class="topbar-sub">Sat &amp; Sun OT slots (all slots counted)</div>
    </div>
  </div>

  <div class="topbar-spacer"></div>

  <nav class="pillnav">
    <a href="index.php<?= $token ? '?token='.urlencode($token) : '' ?>">Schedule</a>
    <a href="weekly.php<?= $token ? '?token='.urlencode($token) : '' ?>">Weekly</a>
    <a href="monthly.php<?= $token ? '?token='.urlencode($token) : '' ?>">Monthly</a>
    <a href="overtime.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="active">Overtime</a>
    <a href="notifications.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="has-badge">Notifications<span class="pill-badge" id="pillNotifBadge"></span></a>
  </nav>
</div>

<div class="container">

<?php if ($error): ?>
  <div class="alert"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php else: ?>

<?php foreach ($periods as $p):
    $m         = $p['meta'];
    $ot        = $p['ot'];
    $id        = $p['id'];
    $hasData   = $p['hasData'];
    $isPartial = $p['isPartial'];
    $isActive  = ($m['start'] <= $today && $today <= $m['end']);

    if ($isPartial) {
        $badgeClass = 'partial';  $badgeText = 'In Progress';
    } elseif ($isActive) {
        $badgeClass = 'active';   $badgeText = 'Active';
    } elseif ($m['start'] > $today) {
        $badgeClass = 'forecast'; $badgeText = 'Upcoming';
    } else {
        $badgeClass = 'done';     $badgeText = 'Done';
    }
    $periodLabel = $id==='prev'?'Previous':($id==='cur'?'Current':'Next');
?>
<div class="pcard">

  <div class="pcard-head <?= $id ?>">
    <div>
      <div class="ph-period"><?= $periodLabel ?> · <?= htmlspecialchars($m['label']) ?></div>
      <div class="ph-range"><?= fmtRange($m['start'],$m['end']) ?></div>
    </div>
    <span class="ph-badge <?= $badgeClass ?>"><?= $badgeText ?></span>
  </div>

  <div class="pcard-stats">
    <div class="ps">
      <div class="ps-num <?= !$hasData?'c-dim':'c-total' ?>"><?= !$hasData?'—':$ot['total'] ?></div>
      <div class="ps-lbl">Total OT</div>
    </div>
    <div class="ps">
      <div class="ps-num <?= !$hasData?'c-dim':'c-sat' ?>"><?= !$hasData?'—':$ot['sat'] ?></div>
      <div class="ps-lbl">Saturday</div>
    </div>
    <div class="ps">
      <div class="ps-num <?= !$hasData?'c-dim':'c-sun' ?>"><?= !$hasData?'—':$ot['sun'] ?></div>
      <div class="ps-lbl">Sunday</div>
    </div>
  </div>

  <?php if (!$hasData): ?>
    <div class="forecast-note">
      <i class="bi bi-stars"></i> No weekend OT slots found for this period yet
    </div>

  <?php else: ?>

    <?php if ($isPartial): ?>
    <div class="partial-note">
      <i class="bi bi-clock"></i>
      Showing data up to today · period ends <?= (new DateTime($m['end']))->format('M j') ?>
    </div>
    <?php endif; ?>

    <div class="day-list">

      <?php if ($ot['satDays']): ?>
        <div class="sec-lbl">Saturdays</div>
        <?php foreach ($ot['satDays'] as $info):
          $booked = $info['reserved'];
          $open   = $info['total'] - $info['reserved'];
        ?>
        <div class="day-item">
          <span class="di-pill sat">SAT</span>
          <span class="di-label"><?= htmlspecialchars($info['label']) ?></span>
          <div class="di-counts">
            <span class="cnt-chip total"><?= $info['total'] ?> OT</span>
            <?php if ($booked > 0): ?>
            <span class="cnt-chip reserved"><?= $booked ?> booked</span>
            <?php endif; ?>
            <?php if ($open > 0): ?>
            <span class="cnt-chip nobook"><?= $open ?> open</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="no-days">No Saturday slots</div>
      <?php endif; ?>

      <?php if ($ot['sunDays']): ?>
        <div class="sec-lbl">Sundays</div>
        <?php foreach ($ot['sunDays'] as $info):
          $booked = $info['reserved'];
          $open   = $info['total'] - $info['reserved'];
        ?>
        <div class="day-item">
          <span class="di-pill sun">SUN</span>
          <span class="di-label"><?= htmlspecialchars($info['label']) ?></span>
          <div class="di-counts">
            <span class="cnt-chip total"><?= $info['total'] ?> OT</span>
            <?php if ($booked > 0): ?>
            <span class="cnt-chip reserved"><?= $booked ?> booked</span>
            <?php endif; ?>
            <?php if ($open > 0): ?>
            <span class="cnt-chip nobook"><?= $open ?> open</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="no-days">No Sunday slots</div>
      <?php endif; ?>

    </div>
  <?php endif; ?>

</div>
<?php endforeach; ?>

<?php endif; ?>
</div>

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