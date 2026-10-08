<?php
/**
 * Works out the logged-in teacher's ID from their own token.
 * Nothing is hardcoded, so any registered Kredo teacher can use the dashboard.
 *
 * Order: ?teacher_id= (manual) -> cookie -> token (JWT) -> Kredo API -> give up ('').
 * Every guess is checked against the schedules endpoint before it's trusted.
 */
const KL_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

function kl_get(string $url, string $token, ?int &$code = 0): ?string {
    $ch = curl_init($url);
    $o  = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Accept: application/json", "Authorization: Bearer {$token}"],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $ca = __DIR__ . '/cacert.pem';
    if (is_file($ca)) $o[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $o);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $res === false ? null : $res;
}

function kl_jwt_payload(string $t): ?array {
    $p = explode('.', $t);
    if (count($p) !== 3) return null;
    $json = base64_decode(strtr($p[1], '-_', '+/') . str_repeat('=', (4 - strlen($p[1]) % 4) % 4));
    $d = $json ? json_decode($json, true) : null;
    return is_array($d) ? $d : null;
}

function kl_collect_uuids($data, string $path, array &$out): void {
    if (is_array($data)) {
        foreach ($data as $k => $v) kl_collect_uuids($v, $path . '.' . $k, $out);
    } elseif (is_string($data) && preg_match(KL_UUID_RE, $data)) {
        $out[] = ['id' => $data, 'score' => stripos($path, 'teacher') !== false ? 3 : (preg_match('/(^|\.)(sub|id|user_id)$/i', $path) ? 1 : 0)];
    }
}

function kl_teacher_ok(string $tid, string $token): bool {
    $d = date('Y-m-d');
    kl_get("https://api.kredo-learning.com/v2/teachers/{$tid}/schedules?start_date={$d}&end_date={$d}", $token, $code);
    return $code === 200;
}

function kl_remember(string $hash, string $tid): void {
    if (!headers_sent()) setcookie('kl_teacher', $hash . '|' . $tid, time() + 60*60*24*30, '/');
    $_COOKIE['kl_teacher'] = $hash . '|' . $tid;
}

function kl_resolve_teacher_id(string $token): string {
    $hash = substr(sha1($token), 0, 12);   // ties the saved ID to THIS token

    // 1) typed in manually
    if (!empty($_GET['teacher_id']) && preg_match(KL_UUID_RE, trim($_GET['teacher_id']))) {
        $tid = trim($_GET['teacher_id']);
        kl_remember($hash, $tid);
        return $tid;
    }
    // 2) saved earlier for this same token
    if (!empty($_COOKIE['kl_teacher'])) {
        [$h, $tid] = array_pad(explode('|', $_COOKIE['kl_teacher'], 2), 2, '');
        if ($h === $hash && preg_match(KL_UUID_RE, $tid)) return $tid;
    }
    // recently failed? don't hammer the API on every page load
    if (($_COOKIE['kl_tfail'] ?? '') === $hash) return '';

    // 3) collect candidates: token claims first, then API "who am I" endpoints
    $cands = [];
    $claims = kl_jwt_payload($token);
    if ($claims) kl_collect_uuids($claims, 'jwt', $cands);

    $tried = []; $checks = 0;
    $try = function (array $list) use (&$tried, &$checks, $token): string {
        usort($list, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($list as $c) {
            if (isset($tried[$c['id']]) || $checks >= 6) continue;
            $tried[$c['id']] = true; $checks++;
            if (kl_teacher_ok($c['id'], $token)) return $c['id'];
        }
        return '';
    };

    $found = $try($cands);
    if (!$found) {
        foreach (['/v2/me', '/v2/auth/me', '/v2/profile', '/v2/user', '/v2/users/me', '/v2/teachers/me', '/v2/account'] as $ep) {
            $res = kl_get('https://api.kredo-learning.com' . $ep, $token, $code);
            if ($code !== 200 || !$res) continue;
            $j = json_decode($res, true);
            $list = [];
            if (is_array($j)) kl_collect_uuids($j, 'api', $list);
            if ($list && ($found = $try($list))) break;
        }
    }

    if ($found) { kl_remember($hash, $found); return $found; }

    if (!headers_sent()) setcookie('kl_tfail', $hash, time() + 300, '/');
    $_COOKIE['kl_tfail'] = $hash;
    return '';
}
