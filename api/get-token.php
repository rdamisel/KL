<?php /* How to get the Kredo token */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Get token</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--ink:#1a1a1a;--muted:#767676;--line:#e8e8e8;--accent:#12805c}
*{box-sizing:border-box}
body{margin:0;font-family:'Inter',system-ui,sans-serif;color:var(--ink);line-height:1.55}
.wrap{max-width:640px;margin:0 auto;padding:1.4rem 1.1rem 3rem}
a.back{color:var(--accent);font-weight:600;text-decoration:none;font-size:.9rem}
h1{font-size:1.4rem;margin:.8rem 0 1.2rem}
h2{font-size:1rem;margin:1.6rem 0 .3rem}
ol{padding-left:1.2rem;margin:.4rem 0}
li{margin-bottom:.35rem}
code{font-family:ui-monospace,Consolas,monospace;background:#f3f3f3;border-radius:5px;padding:.1rem .35rem;font-size:.85em}
.box{display:flex;gap:.5rem;align-items:flex-start;background:#f3f3f3;border-radius:8px;padding:.6rem .7rem;margin:.4rem 0}
.box code{background:none;padding:0;flex:1;word-break:break-all;font-size:.8rem}
button{font-family:inherit;font-weight:600;font-size:.78rem;border:none;border-radius:999px;padding:.35rem .85rem;cursor:pointer;background:var(--accent);color:#fff}
.bm{display:inline-block;text-decoration:none;font-weight:600;font-size:.85rem;border:1px dashed var(--accent);color:var(--accent);border-radius:999px;padding:.4rem 1rem;cursor:grab;margin:.3rem 0}
.small{color:var(--muted);font-size:.85rem;margin-top:1.8rem}
</style>
</head>
<body>
<div class="wrap">
  <a class="back" href="index.php">← Back</a>
  <h1>Getting your token</h1>

  <h2>Option 1: Bookmark</h2>
  <ol>
    <li>Drag this to your bookmarks bar:<br><a class="bm" id="bmLink" href="#">Get Kredo Token</a></li>
    <li>Log in to Kredo.</li>
    <li>Click the bookmark while on the Kredo tab. The token gets copied.</li>
    <li>Paste it in the Token box here.</li>
  </ol>

  <h2>Option 2: Console</h2>
  <ol>
    <li>On Kredo (logged in), press <code>F12</code> and open <b>Console</b>.</li>
    <li>Paste this and hit Enter:
      <div class="box"><code id="snip"></code><button type="button" onclick="copyText(document.getElementById('snip').textContent,this)">Copy</button></div>
    </li>
    <li>Paste the token here.</li>
  </ol>
  <p class="small" style="margin-top:.3rem">If Chrome blocks pasting, type <code>allow pasting</code> first.</p>

  <h2>Option 3: By hand</h2>
  <ol>
    <li>On Kredo, press <code>F12</code> → <b>Application</b> → <b>Local Storage</b>.</li>
    <li>Find <code>kl_access_token</code> and copy the value. Check Cookies if it's not there.</li>
  </ol>

  <h2>Teacher ID (only if asked)</h2>
  <ol>
    <li>On Kredo, press <code>F12</code> → <b>Network</b>, then refresh your schedule page.</li>
    <li>Find a request that looks like <code>teachers/&lt;long-id&gt;/schedules</code>. That long ID is yours.</li>
  </ol>

  <p class="small">Don't share your token with anyone. If it says expired, just get a new one.</p>
</div>

<script>
const FIND = "(function(){var k='kl_access_token',v=null;try{v=localStorage.getItem(k)||sessionStorage.getItem(k)}catch(e){}"
  + "if(!v){var m=document.cookie.match(new RegExp('(?:^|; )'+k+'=([^;]*)'));if(m)v=decodeURIComponent(m[1])}"
  + "if(v)v=v.replace(/^\"+|\"+$/g,'');return v})()";

document.getElementById('snip').textContent = "copy(" + FIND + ") || console.log('no token found')";

const bm = "javascript:(function(){var t=" + FIND + ";"
  + "if(!t){alert('No token found. Are you on Kredo and logged in?');return}"
  + "if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(function(){alert('Token copied')},function(){prompt('Copy your token:',t)})}"
  + "else{prompt('Copy your token:',t)}})();";
const link = document.getElementById('bmLink');
link.setAttribute('href', bm);
link.addEventListener('click', e => { e.preventDefault(); alert('Drag this to your bookmarks bar instead of clicking.'); });

function copyText(t, btn){
  navigator.clipboard.writeText(t).then(()=>{const o=btn.textContent;btn.textContent='Copied';setTimeout(()=>btn.textContent=o,1200)});
}
</script>
</body>
</html>
