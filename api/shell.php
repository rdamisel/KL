<?php
// Instant loading screen on full page loads.
// A normal browser navigation gets a tiny page with a spinner right away; that page then
// fetches the real page (same URL) in the background and swaps it in when it's ready.
header('Cache-Control: no-store');
header('Vary: Sec-Fetch-Mode, X-KL');
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' &&
    ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate' &&
    ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document' &&
    empty($_SERVER['HTTP_X_KL'])
) {
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Loading…</title>
<style>
html,body{height:100%;margin:0}
body{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:.8rem;background:#fff;font-family:'Inter',system-ui,sans-serif;font-weight:600;font-size:.9rem;color:#1a1a1a}
.sp{width:38px;height:38px;border:4px solid #e8f3ef;border-top-color:#12805c;border-radius:50%;animation:s .8s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
a{color:#12805c}
</style></head>
<body>
<div class="sp" id="sp"></div><div id="msg">Loading…</div>
<noscript><meta http-equiv="refresh" content="0"></noscript>
<script>
fetch(location.href, {headers: {'X-KL': '1'}, credentials: 'same-origin', cache: 'no-store'})
  .then(function (r) { return r.text(); })
  .then(function (html) { document.open(); document.write(html); document.close(); })
  .catch(function () {
    document.getElementById('sp').style.display = 'none';
    document.getElementById('msg').innerHTML = 'Failed to load. <a href="' + location.href + '">Try again</a>';
  });
</script>
</body></html>
<?php
    exit;
}
