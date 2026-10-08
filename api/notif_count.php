<?php
// Lightweight endpoint used by the header badge to poll for new notifications
// in the background. Deliberately does NOT fetch full reservation/teacher_notes
// details (like notifications.php does) — that would mean extra Kredo API
// calls on every poll tick, which is too heavy to run every 30s. Instead this
// only de-dupes by reservation+event (cheap, no extra calls) and counts unread.
header('Content-Type: application/json');

$token = '';
if (!empty($_GET['token'])) {
    $token = trim($_GET['token']);
} elseif (!empty($_COOKIE['kl_token'])) {
    $token = $_COOKIE['kl_token'];
}

if (!$token) {
    echo json_encode(['count' => 0, 'error' => 'no token']);
    exit;
}

$url = "https://api.kredo-learning.com/v2/notifications/paginate?page=1";
$ch  = curl_init($url);
$curlOpts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Accept: application/json", "Authorization: Bearer {$token}"],
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
];
$caBundle = __DIR__ . '/cacert.pem';
if (is_file($caBundle)) {
    $curlOpts[CURLOPT_CAINFO] = $caBundle;
}
curl_setopt_array($ch, $curlOpts);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$count = 0;
if ($code === 200) {
    $body          = json_decode($res, true);
    $notifications = $body['data'] ?? [];

    // Same cheap de-dupe key used on the Notifications page, so the badge
    // count roughly matches what's actually shown there (minus the
    // already-gave-feedback filter, which needs extra API calls per item).
    $seen = [];
    foreach ($notifications as $n) {
        $data     = $n['data'] ?? [];
        $uuid     = $data['reservation_uuid'] ?? null;
        $isCancel = (stripos($data['message'] ?? '', 'cancel') !== false) || (stripos($n['type'] ?? '', 'Cancel') !== false);
        $key      = $uuid ? ($uuid . '|' . ($isCancel ? 'c' : 'r')) : ($n['id'] ?? json_encode($n));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        if (empty($n['read_at'])) $count++;
    }
}

echo json_encode(['count' => $count]);
