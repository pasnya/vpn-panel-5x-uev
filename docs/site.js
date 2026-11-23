(function () {
  var root = document.documentElement;

  // Сохранённая тема применяется встроенным скриптом в <head> ещё до отрисовки.
  function currentTheme() {
    var t = root.getAttribute('data-theme');
    if (t) return t;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var themeBtn = document.querySelector('.theme-toggle');
    if (themeBtn) themeBtn.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) {}
    });

    // Мобильное меню
    var header = document.querySelector('.site-header');
    var navBtn = document.querySelector('.nav-toggle');
    if (header && navBtn) {
      navBtn.addEventListener('click', function () {
        var open = header.classList.toggle('open');
        navBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      header.querySelectorAll('.nav a').forEach(function (a) {
        a.addEventListener('click', function () { header.classList.remove('open'); navBtn.setAttribute('aria-expanded', 'false'); });
      });
    }

    // Текущий раздел в меню
    var page = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.nav a').forEach(function (a) {
      if (a.getAttribute('href') === page) a.setAttribute('aria-current', 'page');
    });

    // Кнопки «Скопировать»
    var copyIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    function addCopy(container, getText) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'copy-btn';
      btn.innerHTML = copyIcon + '<span>Скопировать</span>';
      btn.addEventListener('click', function () {
        var text = getText();
        var done = function () {
          btn.classList.add('done'); btn.querySelector('span').textContent = 'Скопировано';
          setTimeout(function () { btn.classList.remove('done'); btn.querySelector('span').textContent = 'Скопировать'; }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
        } else { fallback(text); done(); }
      });
      container.appendChild(btn);
    }
    function fallback(text) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      ta.remove();
    }
    document.querySelectorAll('.cmd').forEach(function (box) {
      var code = box.querySelector('code');
      if (code) addCopy(box, function () { return code.textContent.trim(); });
    });
    document.querySelectorAll('pre:not(.no-copy)').forEach(function (pre) {
      var text = pre.textContent.trim();
      pre.classList.add('has-copy');
      addCopy(pre, function () { return text; });
    });

    // Появление блоков при прокрутке
    var items = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
      }, { rootMargin: '0px 0px -8% 0px' });
      items.forEach(function (el) { io.observe(el); });
    } else {
      items.forEach(function (el) { el.classList.add('in'); });
    }
  });
})();
