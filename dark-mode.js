/* ===== Dark Mode Controller (student/admin shared) ===== */
/* Plain JavaScript—no JSX, no dependencies. Include with:
   <script src="dark-mode.js" defer></script>
   Ensure there is a toggle button with id="themeToggle"
   and (optionally) a tooltip element with id="themeTooltip" or data-tooltip="..." on the button.
*/

(function ThemeController() {
  var STORAGE_KEY = 'pdtsTheme'; // shared across pages (student/admin)
  var root = document.documentElement; // we toggle :root.dark
  var btn  = document.getElementById('themeToggle');
  var tooltipId = (btn && btn.getAttribute('data-tooltip')) ? btn.getAttribute('data-tooltip') : 'themeTooltip';
  var tip  = document.getElementById(tooltipId);

  if (!btn) return;

  function applyUI(isDark) {
    try {
      btn.textContent = isDark ? '☀️' : '🌙';
      if (tip) tip.textContent = isDark ? 'Switch to light mode ☀️' : 'Switch to dark mode 🌙';
      if (tip && !tip.style.position) tip.style.position = 'absolute';
    } catch (_) {}
  }

  function getInitialTheme() {
    try {
      var saved = localStorage.getItem(STORAGE_KEY);
      if (saved === 'dark' || saved === 'light') return saved;
    } catch (_) {}
    return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  }

  function setTheme(mode) {
    var isDark = mode === 'dark';
    if (isDark) root.classList.add('dark'); else root.classList.remove('dark');
    root.classList.add('theme-fade'); // graceful transition class (CSS handles the timing)
    applyUI(isDark);
  }

  // Init
  setTheme(getInitialTheme());

  // Toggle handler
  btn.addEventListener('click', function () {
    var willBeDark = !root.classList.contains('dark');
    try { localStorage.setItem(STORAGE_KEY, willBeDark ? 'dark' : 'light'); } catch (_) {}
    setTheme(willBeDark ? 'dark' : 'light');
  });

  // Optional: keep in sync with system if user never explicitly chose
  if (window.matchMedia) {
    var media = window.matchMedia('(prefers-color-scheme: dark)');
    var sync = function (e) {
      try {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved !== 'dark' && saved !== 'light') setTheme(e.matches ? 'dark' : 'light');
      } catch (_) {}
    };
    if (media.addEventListener) media.addEventListener('change', sync);
    else if (media.addListener) media.addListener(sync); // older browsers
  }

  // Lightweight tooltip show/hide (optional)
  if (tip) {
    btn.addEventListener('mouseenter', function () { tip.style.display = 'inline-block'; });
    btn.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
  }
})();
