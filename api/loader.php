<!-- Loading overlay: shows right away on form submits and internal link clicks -->
<style>
#klLoading{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;flex-direction:column;gap:.8rem;background:rgba(255,255,255,.78);backdrop-filter:blur(2px);font-family:'Inter',system-ui,sans-serif;font-weight:600;font-size:.9rem;color:#1a1a1a}
#klLoading.show{display:flex}
#klLoading .sp{width:38px;height:38px;border:4px solid #e8f3ef;border-top-color:var(--accent,#12805c);border-radius:50%;animation:klspin .8s linear infinite}
@keyframes klspin{to{transform:rotate(360deg)}}
</style>
<div id="klLoading" role="status" aria-live="polite"><div class="sp"></div><div>Loading…</div></div>
<script>
(function () {
  var el = document.getElementById('klLoading'), t;
  window.klShowLoading = function () {
    el.classList.add('show');
    clearTimeout(t);
    t = setTimeout(window.klHideLoading, 60000);   // safety net
  };
  window.klHideLoading = function () { el.classList.remove('show'); };

  // back/forward cache: never leave the overlay stuck
  window.addEventListener('pageshow', window.klHideLoading);

  document.addEventListener('submit', function (e) {
    if (!e.defaultPrevented) window.klShowLoading();
  });

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
    var h = a.getAttribute('href');
    if (!h || h.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(h)) return;
    if (a.origin !== location.origin) return;
    if (a.pathname === location.pathname && a.search === location.search && a.hash) return;
    window.klShowLoading();
  });
})();
</script>
