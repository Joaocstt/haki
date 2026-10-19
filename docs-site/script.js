/* Haki — documentação
 * Navegação entre páginas, "nesta página", busca, tema, realce de código e botão copiar.
 * Sem dependências. Sem JS, todas as seções continuam visíveis.
 */
(function () {
  'use strict';

  var pages = Array.prototype.slice.call(document.querySelectorAll('.doc-page'));
  var navLinks = Array.prototype.slice.call(document.querySelectorAll('.nav-list a'));
  var tocList = document.getElementById('toc-list');
  var baseTitle = document.title;
  var observer = null;

  function storage(key, value) {
    try {
      if (value === undefined) return localStorage.getItem(key);
      if (value === null) localStorage.removeItem(key);
      else localStorage.setItem(key, value);
    } catch (e) {
      return null;
    }
  }

  /* ------------------------------------------------------------- tema */

  // Dois botões (claro/escuro). Sem escolha salva, segue o tema do sistema.
  var themeButtons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-set]'));
  var systemDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function effectiveTheme() {
    var saved = document.documentElement.getAttribute('data-theme');
    if (saved) return saved;
    return systemDark && systemDark.matches ? 'dark' : 'light';
  }

  function markTheme() {
    var current = effectiveTheme();
    themeButtons.forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.getAttribute('data-theme-set') === current));
    });
  }

  themeButtons.forEach(function (b) {
    b.addEventListener('click', function () {
      var theme = b.getAttribute('data-theme-set');
      document.documentElement.setAttribute('data-theme', theme);
      storage('haki-theme', theme);
      markTheme();
    });
  });

  if (systemDark && systemDark.addEventListener) systemDark.addEventListener('change', markTheme);
  markTheme();

  /* ------------------------------------------------------------- recolher a barra lateral */

  function setCollapsed(collapsed) {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    storage('haki-sidebar', collapsed ? 'recolhida' : null);
  }

  document.getElementById('sidebar-collapse').addEventListener('click', function () { setCollapsed(true); });
  document.getElementById('sidebar-expand').addEventListener('click', function () { setCollapsed(false); });
  if (storage('haki-sidebar') === 'recolhida') document.body.classList.add('sidebar-collapsed');

  /* ------------------------------------------------------------- páginas */

  function pageFor(hash) {
    var id = decodeURIComponent((hash || '').replace(/^#/, ''));
    if (!id) return { page: pages[0], target: null };

    var el = document.getElementById(id);
    if (!el) return { page: pages[0], target: null };

    var page = el.classList.contains('doc-page') ? el : el.closest('.doc-page');
    return { page: page || pages[0], target: el.classList.contains('doc-page') ? null : el };
  }

  function show(hash, scroll) {
    var found = pageFor(hash);
    var page = found.page;

    pages.forEach(function (p) { p.hidden = p !== page; });

    navLinks.forEach(function (a) {
      if (a.getAttribute('href') === '#' + page.id) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });

    var h1 = page.querySelector('h1');
    document.title = (h1 ? h1.textContent + ' — ' : '') + baseTitle;

    buildToc(page);
    buildPager(page);
    document.body.classList.remove('nav-open');

    if (scroll !== false) {
      if (found.target) found.target.scrollIntoView();
      else window.scrollTo(0, 0);
    }
  }

  function headingText(h) {
    return h.textContent.replace(/\s*#$/, '').trim();
  }

  function buildToc(page) {
    tocList.innerHTML = '';
    var headings = page.querySelectorAll('h2[id], h3[id]');

    headings.forEach(function (h) {
      var li = document.createElement('li');
      li.className = 'depth-' + h.tagName.charAt(1);
      var a = document.createElement('a');
      a.href = '#' + h.id;
      a.textContent = headingText(h);
      li.appendChild(a);
      tocList.appendChild(li);
    });

    document.querySelector('.toc').style.visibility = headings.length ? '' : 'hidden';

    if (observer) observer.disconnect();
    if (!('IntersectionObserver' in window)) return;

    observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        tocList.querySelectorAll('a').forEach(function (a) {
          a.classList.toggle('is-active', a.getAttribute('href') === '#' + entry.target.id);
        });
      });
    }, { rootMargin: '-90px 0px -70% 0px' });

    headings.forEach(function (h) { observer.observe(h); });
  }

  function buildPager(page) {
    var old = page.querySelector('.pager');
    if (old) old.remove();

    var index = navLinks.findIndex(function (a) { return a.getAttribute('href') === '#' + page.id; });
    if (index < 0) return;

    var nav = document.createElement('nav');
    nav.className = 'pager';
    nav.setAttribute('aria-label', 'Navegação entre páginas');

    if (navLinks[index - 1]) nav.appendChild(pagerLink(navLinks[index - 1], 'Anterior', 'prev'));
    if (navLinks[index + 1]) nav.appendChild(pagerLink(navLinks[index + 1], 'Próximo', 'next'));

    page.appendChild(nav);
  }

  function pagerLink(source, label, cls) {
    var a = document.createElement('a');
    a.href = source.getAttribute('href');
    a.className = cls;
    a.innerHTML = '<small>' + label + '</small><span></span>';
    a.querySelector('span').textContent = source.textContent;
    return a;
  }

  // Âncoras nos títulos
  pages.forEach(function (page) {
    page.querySelectorAll('h2[id], h3[id]').forEach(function (h) {
      var a = document.createElement('a');
      a.className = 'anchor';
      a.href = '#' + h.id;
      a.setAttribute('aria-label', 'Link para esta seção');
      a.textContent = '#';
      h.appendChild(a);
    });
  });

  window.addEventListener('hashchange', function () { show(location.hash); });

  /* ------------------------------------------------------------- menu mobile */

  document.getElementById('menu-toggle').addEventListener('click', function () {
    document.body.classList.toggle('nav-open');
  });

  window.addEventListener('scroll', function () {
    // No fim da página, o último título nunca chega ao topo: marca ele manualmente.
    if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 4) {
      var links = tocList.querySelectorAll('a');
      links.forEach(function (a, i) { a.classList.toggle('is-active', i === links.length - 1); });
    }
  }, { passive: true });

  /* ------------------------------------------------------------- realce de sintaxe */

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  var grammars = {
    php: [
      ['comment', /\/\/[^\n]*|#(?!\[)[^\n]*|\/\*[\s\S]*?\*\//],
      ['tag', /<\?php|\?>/],
      ['string', /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/],
      ['var', /\$[A-Za-z_][A-Za-z0-9_]*/],
      ['keyword', /\b(?:use|namespace|class|final|abstract|public|private|protected|function|fn|return|new|if|else|elseif|foreach|for|while|as|throw|readonly|interface|implements|extends|static|self|null|true|false|array|string|int|float|bool|void|mixed|never|declare|require|require_once|match|default|try|catch|finally|echo|instanceof|enum|case|const)\b/],
      ['class', /\b[A-Z][A-Za-z0-9_]*(?:\\[A-Z][A-Za-z0-9_]*)*\b/],
      ['number', /\b\d[\d_]*(?:\.\d+)?\b/]
    ],
    bash: [
      ['comment', /#[^\n]*/],
      ['string', /'[^']*'|"(?:\\.|[^"\\])*"/],
      ['flag', /(?:^|\s)--?[A-Za-z][\w-]*/],
      ['keyword', /\b(?:composer|php|curl|cd|mkdir|cp|git|openssl)\b/]
    ],
    json: [
      ['key', /"(?:\\.|[^"\\])*"(?=\s*:)/],
      ['string', /"(?:\\.|[^"\\])*"/],
      ['number', /-?\b\d+(?:\.\d+)?\b/],
      ['keyword', /\b(?:true|false|null)\b/]
    ],
    env: [
      ['comment', /#[^\n]*/],
      ['key', /^[A-Z_][A-Z0-9_]*(?==)/m]
    ],
    http: [
      ['keyword', /^(?:GET|POST|PUT|PATCH|DELETE|OPTIONS|HTTP\/1\.1)\b/m],
      ['key', /^[A-Za-z-]+(?=:)/m],
      ['string', /"(?:\\.|[^"\\])*"/],
      ['number', /\b\d{3}\b/]
    ]
  };

  function highlight(code, lang) {
    var rules = grammars[lang];
    if (!rules) return escapeHtml(code);

    var pattern = new RegExp(rules.map(function (r) { return '(' + r[1].source + ')'; }).join('|'), 'gm');
    var out = '';
    var last = 0;
    var match;

    while ((match = pattern.exec(code)) !== null) {
      if (match[0] === '') { pattern.lastIndex++; continue; }
      out += escapeHtml(code.slice(last, match.index));
      for (var i = 1; i < match.length; i++) {
        if (match[i] !== undefined) {
          out += '<span class="t-' + rules[i - 1][0] + '">' + escapeHtml(match[0]) + '</span>';
          break;
        }
      }
      last = pattern.lastIndex;
    }

    return out + escapeHtml(code.slice(last));
  }

  document.querySelectorAll('.code pre code').forEach(function (block) {
    var lang = block.getAttribute('data-lang');
    var text = block.textContent.replace(/^\n/, '').replace(/\s+$/, '');
    block.innerHTML = highlight(text, lang);

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy-button';
    button.textContent = 'Copiar';
    button.addEventListener('click', function () {
      var done = function () {
        button.textContent = 'Copiado';
        setTimeout(function () { button.textContent = 'Copiar'; }, 1400);
      };
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () {});
    });
    block.closest('.code').appendChild(button);
  });

  /* ------------------------------------------------------------- busca */

  var dialog = document.getElementById('search-dialog');
  var input = document.getElementById('search-input');
  var results = document.getElementById('search-results');
  var selected = 0;

  function normalize(s) {
    return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  var index = [];
  pages.forEach(function (page) {
    var h1 = page.querySelector('h1');
    var pageTitle = h1 ? h1.textContent : page.id;
    index.push({ title: pageTitle, section: 'Página', href: '#' + page.id, text: normalize(pageTitle + ' ' + page.textContent.slice(0, 400)) });
    page.querySelectorAll('h2[id], h3[id]').forEach(function (h) {
      var title = headingText(h);
      var next = h.nextElementSibling ? h.nextElementSibling.textContent.slice(0, 200) : '';
      index.push({ title: title, section: pageTitle, href: '#' + h.id, text: normalize(title + ' ' + next) });
    });
  });

  function search(query) {
    var q = normalize(query.trim());
    var terms = q.split(/\s+/).filter(Boolean);
    var list = !terms.length ? index.filter(function (i) { return i.section === 'Página'; }) : index
      .map(function (item) {
        var title = normalize(item.title);
        var score = 0;
        for (var t = 0; t < terms.length; t++) {
          if (title.indexOf(terms[t]) !== -1) score += 3;
          else if (item.text.indexOf(terms[t]) !== -1) score += 1;
          else return null;
        }
        return { item: item, score: score };
      })
      .filter(Boolean)
      .sort(function (a, b) { return b.score - a.score; })
      .map(function (r) { return r.item; });

    render(list.slice(0, 12));
  }

  function render(list) {
    results.innerHTML = '';
    selected = 0;

    if (!list.length) {
      results.innerHTML = '<li class="search-empty">Nada encontrado.</li>';
      return;
    }

    list.forEach(function (item, i) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = item.href;
      a.innerHTML = '<small></small><span></span>';
      a.querySelector('small').textContent = item.section;
      a.querySelector('span').textContent = item.title;
      if (i === 0) a.classList.add('is-selected');
      a.addEventListener('click', function () { dialog.close(); });
      li.appendChild(a);
      results.appendChild(li);
    });
  }

  function move(delta) {
    var links = results.querySelectorAll('a');
    if (!links.length) return;
    links[selected].classList.remove('is-selected');
    selected = (selected + delta + links.length) % links.length;
    links[selected].classList.add('is-selected');
    links[selected].scrollIntoView({ block: 'nearest' });
  }

  function openSearch() {
    if (typeof dialog.showModal !== 'function') return;
    input.value = '';
    search('');
    dialog.showModal();
    input.focus();
  }

  document.getElementById('search-open').addEventListener('click', openSearch);
  document.getElementById('search-open-mobile').addEventListener('click', openSearch);
  input.addEventListener('input', function () { search(input.value); });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
    if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    if (e.key === 'Enter') {
      var link = results.querySelectorAll('a')[selected];
      if (link) { location.hash = link.getAttribute('href'); dialog.close(); }
    }
  });

  dialog.addEventListener('click', function (e) {
    if (e.target === dialog) dialog.close();
  });

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      openSearch();
    } else if (e.key === '/' && !/input|textarea/i.test(document.activeElement.tagName)) {
      e.preventDefault();
      openSearch();
    }
  });

  var isMac = /Mac|iPhone|iPad/.test(navigator.platform || '');
  document.querySelectorAll('[data-shortcut]').forEach(function (el) {
    el.textContent = isMac ? '⌘K' : 'Ctrl K';
  });

  /* ------------------------------------------------------------- início */

  show(location.hash, Boolean(location.hash));
})();
