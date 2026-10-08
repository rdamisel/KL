<?php
require_once __DIR__ . '/shell.php';
// ── Token: cookie or GET, same pattern as the other pages ──
$token = '';
if (!empty($_GET['token'])) {
    $token = trim($_GET['token']);
    setcookie('kl_token', $token, time() + 60*60*24*30, '/');
} elseif (!empty($_COOKIE['kl_token'])) {
    $token = $_COOKIE['kl_token'];
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// ── Fetch ──
$notifications = [];
$fetchError    = null;
$curPage       = $page;
$lastPage      = 1;
$total         = 0;

if ($token) {
    $url = "https://api.kredo-learning.com/v2/notifications/paginate?page={$page}";
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
        $body          = json_decode($res, true);
        $notifications = $body['data'] ?? [];
        $curPage       = $body['current_page'] ?? $page;
        $lastPage      = $body['last_page'] ?? 1;
        $total         = $body['total'] ?? count($notifications);
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

// ── Fetch full lesson details (subject, actual class date/time) for each
//    notification's reservation, in parallel via curl_multi ──
function fetchReservationDetails(array $uuids, string $token, string $caBundle, bool $caFound): array {
    $results = [];
    if (!$uuids) return $results;

    $mh      = curl_multi_init();
    $handles = [];
    foreach ($uuids as $uuid) {
        $url = "https://api.kredo-learning.com/v2/reservations/{$uuid}?uuid={$uuid}";
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Accept: application/json", "Authorization: Bearer {$token}"],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($caFound) $opts[CURLOPT_CAINFO] = $caBundle;
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $handles[$uuid] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) curl_multi_select($mh, 1.0);
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($handles as $uuid => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 200) {
            $res  = curl_multi_getcontent($ch);
            $body = json_decode($res, true);
            $results[$uuid] = $body['reservation'] ?? null;
        } else {
            $results[$uuid] = null;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

$reservationDetails = [];
if ($token && $notifications && !$fetchError) {
    $uuids = array_values(array_unique(array_filter(array_map(
        fn($n) => $n['data']['reservation_uuid'] ?? null,
        $notifications
    ))));
    $reservationDetails = fetchReservationDetails($uuids, $token, $caBundle, $caFound);
}

// De-duplicate notifications that point to the same reservation + event
// (the API can emit more than one notification row for one reservation),
// and drop any notification whose reservation already has teacher_notes —
// once feedback (Done/Late/Absent) has been given, that lesson is already
// handled and shouldn't keep showing up / "notifying" here.
function filterAndDedupeNotifications(array $notifications, array $reservationDetails): array {
    $seen = [];
    $out  = [];
    foreach ($notifications as $n) {
        $data     = $n['data'] ?? [];
        $uuid     = $data['reservation_uuid'] ?? null;
        $isCancel = (stripos($data['message'] ?? '', 'cancel') !== false) || (stripos($n['type'] ?? '', 'Cancel') !== false);

        if ($uuid && !empty($reservationDetails[$uuid]['teacher_notes'])) {
            continue; // feedback already given — no need to notify anymore
        }

        // Same reservation + same event type (reserved/cancelled) only needs to show once.
        $key = $uuid ? ($uuid.'|'.($isCancel ? 'c' : 'r')) : ($n['id'] ?? json_encode($n));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $out[] = $n;
    }
    return $out;
}

$totalBeforeFilter = count($notifications);
$notifications      = filterAndDedupeNotifications($notifications, $reservationDetails);
$hiddenCount        = $totalBeforeFilter - count($notifications);

// ── Classify + format each notification for display ──
function classifyNotification(array $n, array $reservationDetails): array {
    $data    = $n['data'] ?? [];
    $message = $data['message'] ?? '';
    $isCancel = (stripos($message, 'cancel') !== false) || (stripos($n['type'] ?? '', 'Cancel') !== false);

    $studentName = $data['student_name'] ?? 'Unknown student';
    $rawDate     = $data['created_at'] ?? null;
    $dateLabel   = '—';
    $timeLabel   = '';
    if ($rawDate) {
        try {
            $dt        = new DateTime($rawDate);
            $dateLabel = $dt->format('M j, Y');
            $timeLabel = $dt->format('g:i A');
        } catch (Exception $e) {
            // leave defaults
        }
    }

    // Pull the actual lesson (class) date/time + subject from the reservation detail, if fetched.
    $uuid       = $data['reservation_uuid'] ?? null;
    $res        = $uuid ? ($reservationDetails[$uuid] ?? null) : null;
    $subject    = $res['subject']['name'] ?? null;
    $lessonDate = null;
    $lessonTime = null;
    if ($res && !empty($res['schedule']['start']) && !empty($res['schedule']['end'])) {
        try {
            $start = (new DateTime($res['schedule']['start'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
            $end   = (new DateTime($res['schedule']['end'],   new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
            $lessonDate = $start->format('M j, Y (D)');
            $lessonTime = $start->format('g:i A') . ' – ' . $end->format('g:i A');
        } catch (Exception $e) {
            // leave null
        }
    }

    return [
        'isCancel'    => $isCancel,
        'studentName' => $studentName,
        'dateLabel'   => $dateLabel,
        'timeLabel'   => $timeLabel,
        'unread'      => empty($n['read_at']),
        'message'     => $isCancel ? 'cancelled the lesson' : 'reserved a lesson',
        'subject'     => $subject,
        'lessonDate'  => $lessonDate,
        'lessonTime'  => $lessonTime,
        'detailFound' => $res !== null,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifications</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%2312805c'/%3E%3Crect x='7' y='5' width='18' height='22' rx='3' fill='%23fff'/%3E%3Crect x='10' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='19' y='3' width='3' height='5' rx='1.5' fill='%2312805c'/%3E%3Crect x='10' y='13' width='12' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='17' width='8' height='1.5' rx='.75' fill='%2312805c'/%3E%3Crect x='10' y='21' width='5' height='1.5' rx='.75' fill='%2312805c'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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

/* Shared page-switcher pill nav (matches other pages) */
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
.page{max-width:760px;margin:0 auto;padding:1.2rem 1.5rem 2.5rem}

/* ── Alert ── */
.alert{
  padding:.8rem 1rem;border-radius:var(--radius-sm);
  font-size:.84rem;font-weight:500;
  color:var(--text);background:var(--surface2);border:1px solid var(--border);
  margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem;
}

/* ── Header row ── */
.list-header{
  display:flex;align-items:baseline;justify-content:space-between;
  margin-bottom:.85rem;
}
.list-title{font-size:1.05rem;font-weight:700;color:var(--text)}
.list-count{font-size:.72rem;color:var(--text3);font-weight:600}

/* ── Notification list ── */
.notif-list{
  border-radius:var(--radius);
  border:1px solid var(--border);
  overflow:hidden;
}
.notif-item{
  display:flex;
  gap:.75rem;
  align-items:flex-start;
  padding:.85rem 1.1rem;
  border-bottom:1px solid var(--border);
  background:#fff;
}
.notif-item:last-child{border-bottom:none}
.notif-item.unread{background:var(--accent-soft)}

.notif-icon{
  width:32px;height:32px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;margin-top:.1rem;
  font-size:.85rem;
}
.notif-icon.reserved{background:#eafaf0;color:var(--green)}
.notif-icon.cancelled{background:#fdecec;color:var(--red)}

.notif-body{flex:1;min-width:0}
.notif-title{
  font-size:.84rem;font-weight:700;color:var(--text);
  margin-bottom:.15rem;
}
.notif-title.cancelled{color:var(--red)}
.notif-title.reserved{color:var(--green)}
.notif-detail{
  font-size:.8rem;color:var(--text2);
  line-height:1.45;
}
.notif-detail b{color:var(--text);font-weight:700}

.notif-lesson{
  margin-top:.35rem;
  display:flex;align-items:center;gap:.4rem;
  font-size:.76rem;color:var(--text2);font-weight:600;
  background:var(--surface2);
  border-radius:8px;
  padding:.35rem .6rem;
  width:fit-content;
}
.notif-lesson i{color:var(--accent);font-size:.75rem}
.notif-lesson.na{color:var(--text3);font-weight:500;font-style:italic;background:transparent;padding-left:0}

.notif-meta{
  flex-shrink:0;text-align:right;
  font-size:.68rem;color:var(--text3);font-weight:600;
  white-space:nowrap;padding-top:.15rem;
}
.notif-meta .meta-label{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.04em;color:var(--border2);margin-bottom:.1rem}
.notif-meta .time{display:block;margin-top:.1rem;font-weight:500;color:var(--border2)}

.unread-dot{
  width:7px;height:7px;border-radius:50%;
  background:var(--accent);flex-shrink:0;margin-top:.4rem;
}

.empty-state{
  padding:2.5rem 1rem;text-align:center;
  color:var(--text3);font-size:.85rem;
}

/* ── Pagination ── */
.pagination{
  display:flex;align-items:center;justify-content:center;gap:.6rem;
  margin-top:1.1rem;
}
.page-btn{
  display:flex;align-items:center;gap:.35rem;
  padding:.5rem 1rem;border-radius:999px;
  font-size:.78rem;font-weight:700;
  text-decoration:none;color:var(--accent);
  background:var(--accent-soft);
  transition:background .15s;
}
.page-btn:hover{background:var(--accent-soft-hover)}
.page-btn.disabled{
  color:var(--border2);background:var(--surface2);
  pointer-events:none;
}
.page-info{font-size:.74rem;color:var(--text3);font-weight:600}

/* ── Responsive ── */
@media(max-width:680px){
  .page{padding:.85rem 1rem 2.5rem}
  .topbar{padding:.65rem 1rem}
  .notif-item{padding:.7rem .85rem;gap:.6rem}
  .notif-icon{width:28px;height:28px;font-size:.75rem}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-brand">
    <div class="topbar-icon"><i class="bi bi-bell-fill"></i></div>
    <div>
      <div class="topbar-title">Notifications</div>
      <div class="topbar-sub">Reservations &amp; cancellations</div>
    </div>
  </div>

  <div class="topbar-spacer"></div>

  <nav class="pillnav">
    <a href="index.php<?= $token ? '?token='.urlencode($token) : '' ?>">Schedule</a>
    <a href="weekly.php<?= $token ? '?token='.urlencode($token) : '' ?>">Weekly</a>
    <a href="monthly.php<?= $token ? '?token='.urlencode($token) : '' ?>">Monthly</a>
    <a href="overtime.php<?= $token ? '?token='.urlencode($token) : '' ?>">Overtime</a>
    <a href="notifications.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="active has-badge">Notifications<span class="pill-badge" id="pillNotifBadge"></span></a>
  </nav>
</div>

<div class="page">

<?php if ($fetchError): ?>
  <div class="alert"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($fetchError) ?></div>
<?php elseif (!$token): ?>
  <div class="alert"><i class="bi bi-key-fill"></i> No token found. Open the <a href="index.php">Daily View</a> and paste your token there. Need help? <a href="get-token.php" target="_blank" rel="noopener">How to get your token</a>.</div>
<?php else: ?>

<div class="list-header">
  <div class="list-title">All Notifications</div>
  <div class="list-count">
    <?= number_format(count($notifications)) ?> shown
    <?php if ($hiddenCount > 0): ?>
      &middot; <?= number_format($hiddenCount) ?> already handled
    <?php endif; ?>
  </div>
</div>

<?php if (!$notifications): ?>
  <div class="notif-list">
    <div class="empty-state"><i class="bi bi-inbox"></i><br>No notifications on this page.</div>
  </div>
<?php else: ?>
  <div class="notif-list">
    <?php foreach ($notifications as $n):
      $c = classifyNotification($n, $reservationDetails);
      $iconClass  = $c['isCancel'] ? 'cancelled' : 'reserved';
      $iconGlyph  = $c['isCancel'] ? 'bi-x-lg' : 'bi-check-lg';
      $titleText  = $c['isCancel'] ? 'Lesson cancelled' : 'Lesson reserved';
    ?>
    <div class="notif-item <?= $c['unread'] ? 'unread' : '' ?>">
      <div class="notif-icon <?= $iconClass ?>"><i class="bi <?= $iconGlyph ?>"></i></div>
      <div class="notif-body">
        <div class="notif-title <?= $iconClass ?>"><?= htmlspecialchars($titleText) ?></div>
        <div class="notif-detail">
          <b><?= htmlspecialchars($c['studentName']) ?></b> has <?= htmlspecialchars($c['message']) ?>.
        </div>
        <?php if ($c['lessonDate']): ?>
        <div class="notif-lesson">
          <i class="bi bi-calendar-event"></i>
          <?= htmlspecialchars($c['subject'] ?? 'Class') ?> · <?= htmlspecialchars($c['lessonDate']) ?> · <?= htmlspecialchars($c['lessonTime']) ?>
        </div>
        <?php elseif (!$c['detailFound']): ?>
        <div class="notif-lesson na"><i class="bi bi-dash-circle"></i> Lesson details unavailable</div>
        <?php endif; ?>
      </div>
      <div class="notif-meta">
        <span class="meta-label">Notified</span>
        <?= htmlspecialchars($c['dateLabel']) ?>
        <span class="time"><?= htmlspecialchars($c['timeLabel']) ?></span>
      </div>
      <?php if ($c['unread']): ?><span class="unread-dot" title="Unread"></span><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="pagination">
    <?php
      $prevUrl = 'notifications.php?page='.($curPage-1).($token ? '&token='.urlencode($token) : '');
      $nextUrl = 'notifications.php?page='.($curPage+1).($token ? '&token='.urlencode($token) : '');
      $prevDisabled = $curPage <= 1;
      $nextDisabled = $curPage >= $lastPage;
    ?>
    <a class="page-btn <?= $prevDisabled ? 'disabled' : '' ?>" href="<?= $prevDisabled ? '#' : htmlspecialchars($prevUrl) ?>"><i class="bi bi-chevron-left"></i> Prev</a>
    <span class="page-info">Page <?= (int)$curPage ?> of <?= (int)$lastPage ?></span>
    <a class="page-btn <?= $nextDisabled ? 'disabled' : '' ?>" href="<?= $nextDisabled ? '#' : htmlspecialchars($nextUrl) ?>">Next <i class="bi bi-chevron-right"></i></a>
  </div>
<?php endif; ?>

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
