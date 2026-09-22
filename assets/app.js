(function () {
  var root = document.documentElement;

  var AppLog = {
    listeners: [],
    subscribe: function (fn) {
      this.listeners.push(fn);
      return function () {
        AppLog.listeners = AppLog.listeners.filter(function (item) { return item !== fn; });
      };
    },
    publish: function (event) {
      this.listeners.forEach(function (fn) {
        try { fn(event); } catch (err) {}
      });
    },
    write: function (topic, message, context, level, persist) {
      var event = {
        id: 'js-' + Date.now() + '-' + Math.random().toString(16).slice(2),
        ts: Date.now() / 1000,
        topic: topic || 'ui',
        message: message || '',
        level: level || 'info',
        context: context && typeof context === 'object' ? context : {}
      };
      this.publish(event);
      if (persist && AppLog.writeUrl) {
        fetch(AppLog.writeUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            topic: event.topic,
            message: event.message,
            level: event.level,
            context: event.context
          })
        }).catch(function () {});
      }
    }
  };
  window.AppLog = AppLog;

  function isDesktopShell() {
    return root.getAttribute('data-shell') === 'desktop';
  }

  function bindTitleSearch(input, applyFn) {
    if (!input) return;
    var clear = document.querySelector('[data-search-clear]');

    function syncClear() {
      if (!clear) return;
      clear.hidden = input.value === '';
    }

    if (isDesktopShell()) {
      input.placeholder = 'Search titles';
      input.addEventListener('input', function () {
        syncClear();
        applyFn();
      });
    } else {
      input.placeholder = 'Search, then press Search';
      input.addEventListener('search', function () {
        applyFn();
        input.blur();
      });
      input.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        applyFn();
        input.blur();
      });
      input.addEventListener('input', function () {
        syncClear();
        if (input.value.trim() === '') applyFn();
      });
    }

    if (clear) {
      clear.addEventListener('click', function (event) {
        event.preventDefault();
        input.value = '';
        syncClear();
        applyFn();
        input.focus();
      });
    }
    syncClear();
  }

  function pref() {
    return root.getAttribute('data-theme-pref') || 'auto';
  }

  function resolve(value) {
    if (value === 'light' || value === 'dark') return value;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function apply(value) {
    var resolved = resolve(value);
    root.setAttribute('data-theme', resolved);
    root.setAttribute('data-theme-pref', value);
    try {
      localStorage.setItem('media_theme', value);
    } catch (e) {}
    document.cookie = 'media_theme=' + encodeURIComponent(value) + ';path=/;max-age=31536000;SameSite=Lax';
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', resolved === 'dark' ? '#2c2c31' : '#ffffff');
    document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
      var on = btn.getAttribute('data-theme-set') === value;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-theme-set]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      apply(btn.getAttribute('data-theme-set') || 'auto');
    });
  });

  var media = window.matchMedia('(prefers-color-scheme: dark)');
  if (media.addEventListener) {
    media.addEventListener('change', function () {
      if (pref() === 'auto') apply('auto');
    });
  }

  apply(pref());

  document.querySelectorAll('[data-menu]').forEach(function (menu) {
    var btn = menu.querySelector('[data-menu-btn]');
    var panel = menu.querySelector('[data-menu-panel]');
    if (!btn || !panel) return;

    function setOpen(open) {
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.classList.toggle('is-active', open);
    }

    btn.addEventListener('click', function (event) {
      event.stopPropagation();
      setOpen(panel.hidden);
    });
    document.addEventListener('click', function (event) {
      if (!panel.hidden && !menu.contains(event.target)) {
        setOpen(false);
      }
    });
  });

  function genreRow(name) {
    var row = document.createElement('li');
    row.className = 'genre-edit-row';
    row.setAttribute('data-genre-row', '');
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'genres[]';
    hidden.value = name;
    var label = document.createElement('span');
    label.className = 'genre-edit-label';
    label.textContent = name;
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-ghost';
    remove.setAttribute('data-remove-genre', '');
    remove.setAttribute('aria-label', 'Remove ' + name);
    remove.textContent = 'Remove';
    row.appendChild(hidden);
    row.appendChild(label);
    row.appendChild(remove);
    return row;
  }

  function addGenreFromInput() {
    var list = document.querySelector('[data-genre-list]');
    var field = document.querySelector('[data-genre-new]');
    if (!list || !field) return;
    var name = field.value.trim();
    if (name === '') return;
    var exists = false;
    list.querySelectorAll('input[name="genres[]"]').forEach(function (input) {
      if ((input.value || '').toLowerCase() === name.toLowerCase()) exists = true;
    });
    if (!exists) list.appendChild(genreRow(name));
    field.value = '';
    field.focus();
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-add-genre]')) {
      event.preventDefault();
      addGenreFromInput();
      return;
    }
    var remove = event.target.closest('[data-remove-genre]');
    if (!remove) return;
    var row = remove.closest('[data-genre-row]');
    if (row) row.remove();
  });

  var genreNew = document.querySelector('[data-genre-new]');
  if (genreNew) {
    genreNew.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      addGenreFromInput();
    });
  }

  var input = document.querySelector('[data-search-input]');
  if (input && !document.querySelector('[data-catalog]')) {
    var cards = document.querySelectorAll('[data-card]');
    var empty = document.querySelector('[data-search-empty]');

    bindTitleSearch(input, function () {
      var query = input.value.trim().toLowerCase();
      var visible = 0;
      for (var i = 0; i < cards.length; i++) {
        var hay = (cards[i].getAttribute('data-search') || '').toLowerCase();
        var show = !query || hay.indexOf(query) !== -1;
        cards[i].hidden = !show;
        if (show) visible += 1;
      }
      if (empty) empty.hidden = !(query && visible === 0);
    });
  }

  function folderRow(kind) {
    var specs = {
      video: { name: 'video_roots[]', cat: 'video_root_categories[]', label: 'Video folder', placeholder: '/volume1/video' },
      music: { name: 'music_roots[]', cat: 'music_root_categories[]', label: 'Music folder', placeholder: '/volume1/music' },
      'video-exclude': { name: 'video_excludes[]', label: 'Excluded video path', placeholder: '/volume1/video/skip-this' },
      'music-exclude': { name: 'music_excludes[]', label: 'Excluded music path', placeholder: '/volume1/music/skip-this' }
    };
    var spec = specs[kind] || specs.video;
    var row = document.createElement('div');
    row.className = 'folder-row';
    row.setAttribute('data-folder-row', '');

    var fields = document.createElement('div');
    fields.className = 'folder-fields';

    var label = document.createElement('label');
    label.className = 'visually-hidden';
    label.textContent = spec.label;

    var input = document.createElement('input');
    input.type = 'text';
    input.name = spec.name;
    input.className = 'folder-input';
    input.placeholder = spec.placeholder;
    input.spellcheck = false;
    input.setAttribute('autocapitalize', 'off');

    fields.appendChild(label);
    fields.appendChild(input);

    if (spec.cat) {
      var select = document.createElement('select');
      select.name = spec.cat;
      select.className = 'folder-input folder-category';
      select.setAttribute('data-source-category', kind);
      var existing = document.querySelector('[data-source-category="' + kind + '"]');
      select.innerHTML = existing ? existing.innerHTML : '<option value="">Uncategorized</option>';
      select.value = '';
      fields.appendChild(select);
    }

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-ghost';
    remove.setAttribute('data-remove-folder', '');
    remove.setAttribute('aria-label', 'Remove ' + spec.label.toLowerCase());
    remove.textContent = 'Remove';

    row.appendChild(fields);
    row.appendChild(remove);
    return row;
  }

  document.addEventListener('click', function (event) {
    var add = event.target.closest('[data-add-folder]');
    if (add) {
      event.preventDefault();
      var kind = add.getAttribute('data-add-folder');
      var list = document.querySelector('[data-folder-list="' + kind + '"]');
      if (!list) return;
      var row = folderRow(kind);
      list.appendChild(row);
      var field = row.querySelector('input');
      if (field) field.focus();
      return;
    }

    var remove = event.target.closest('[data-remove-folder]');
    if (!remove) return;
    var list = remove.closest('[data-folder-list]');
    var row = remove.closest('[data-folder-row]');
    if (!list || !row) return;
    event.preventDefault();
    var rows = list.querySelectorAll('[data-folder-row]');
    if (rows.length <= 1) {
      var field = row.querySelector('input');
      if (field) field.value = '';
      var status = row.querySelector('.folder-status');
      if (status) status.remove();
      return;
    }
    row.remove();
  });

  document.addEventListener('click', function (event) {
    var addCat = event.target.closest('[data-add-category]');
    if (addCat) {
      event.preventDefault();
      var domain = addCat.getAttribute('data-add-category') || 'video';
      var list = document.querySelector('[data-category-list="' + domain + '"]');
      if (!list) return;
      var idName = domain === 'music' ? 'music_category_ids[]' : 'video_category_ids[]';
      var labelName = domain === 'music' ? 'music_category_labels[]' : 'video_category_labels[]';
      var row = document.createElement('div');
      row.className = 'folder-row';
      row.setAttribute('data-category-row', '');
      row.innerHTML = '<div class="folder-fields">' +
        '<input type="hidden" name="' + idName + '" value="">' +
        '<label class="visually-hidden">Category name</label>' +
        '<input type="text" name="' + labelName + '" class="folder-input" value="" placeholder="Category name" spellcheck="false">' +
        '</div>' +
        '<button type="button" class="btn btn-ghost" data-remove-category aria-label="Remove category">Remove</button>';
      list.appendChild(row);
      var field = row.querySelector('input[type="text"]');
      if (field) field.focus();
      return;
    }
    var removeCat = event.target.closest('[data-remove-category]');
    if (!removeCat) return;
    var list = removeCat.closest('[data-category-list]');
    var row = removeCat.closest('[data-category-row]');
    if (!list || !row) return;
    event.preventDefault();
    var rows = list.querySelectorAll('[data-category-row]');
    if (rows.length <= 1) {
      row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
      return;
    }
    row.remove();
  });

  var configTabs = document.querySelectorAll('[data-config-tab]');
  if (configTabs.length) {
    function showConfigTab(id) {
      if (id !== 'music' && id !== 'general') {
        id = 'video';
      }
      configTabs.forEach(function (tab) {
        var on = tab.getAttribute('data-config-tab') === id;
        tab.classList.toggle('is-active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      document.querySelectorAll('[data-config-panel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-config-panel') !== id;
      });
      var field = document.querySelector('[data-config-tab-field]');
      if (field) field.value = id;
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        history.replaceState(null, '', url.pathname + url.search + url.hash);
      } catch (err) {}
    }
    configTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        showConfigTab(tab.getAttribute('data-config-tab') || 'video');
      });
    });
  }

  var catalog = document.querySelector('[data-catalog]');
  if (catalog) {
    try { history.scrollRestoration = 'manual'; } catch (e) {}

    var catalogScrollTimer = 0;
    function catalogScrollKey(view) {
      view = view || catalog.getAttribute('data-view') || '';
      return 'media-catalog-scroll:' + location.pathname + ':' + view;
    }
    function catalogPersistScroll(view) {
      try {
        sessionStorage.setItem(catalogScrollKey(view), String(window.scrollY || window.pageYOffset || 0));
      } catch (e) {}
    }
    function catalogRestoreScroll() {
      var raw;
      try { raw = sessionStorage.getItem(catalogScrollKey()); } catch (e) { return; }
      if (raw == null || raw === '') return;
      var y = parseInt(raw, 10);
      if (!isFinite(y) || y < 1) return;
      function apply() {
        var max = Math.max(0, (document.documentElement.scrollHeight || 0) - window.innerHeight);
        window.scrollTo(0, Math.min(y, max));
      }
      apply();
      requestAnimationFrame(apply);
      window.addEventListener('load', apply, { once: true });
      var n = 0;
      var timer = window.setInterval(function () {
        apply();
        n += 1;
        if (n >= 10) window.clearInterval(timer);
      }, 100);
    }

    window.addEventListener('scroll', function () {
      if (catalogScrollTimer) return;
      catalogScrollTimer = window.setTimeout(function () {
        catalogScrollTimer = 0;
        catalogPersistScroll();
      }, 80);
    }, { passive: true });
    window.addEventListener('pagehide', function () { catalogPersistScroll(); });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') catalogPersistScroll();
    });
    document.addEventListener('click', function (event) {
      if (event.target.closest('a[href]')) catalogPersistScroll();
    }, true);

    function catalogSave() {
      var raw = JSON.stringify({
        view: catalog.getAttribute('data-view') || 'poster',
        columns: (catalog.getAttribute('data-cols') || 'title').trim().split(/\s+/),
        columns_known: Array.prototype.map.call(catalog.querySelectorAll('[data-col-id]'), function (box) {
          return box.getAttribute('data-col-id');
        }).filter(Boolean),
        sort: catalog.getAttribute('data-sort') || 'title',
        dir: catalog.getAttribute('data-dir') || 'asc',
        kinds: (catalog.getAttribute('data-kinds') || 'movie show documentary').trim().split(/\s+/),
        kinds_known: Array.prototype.map.call(catalog.querySelectorAll('[data-kind-filter]'), function (box) {
          return box.getAttribute('data-kind-filter');
        }).filter(Boolean),
        categories: (catalog.getAttribute('data-categories') || '').trim().split(/\s+/).filter(Boolean),
        categories_known: Array.prototype.map.call(catalog.querySelectorAll('[data-category-filter]'), function (box) {
          return box.getAttribute('data-category-filter');
        }).filter(Boolean)
      });
      try { localStorage.setItem('media_catalog', raw); } catch (e) {}
      document.cookie = 'media_catalog=' + encodeURIComponent(raw) + ';path=/;max-age=31536000;SameSite=Lax';
    }

    function setView(view) {
      catalogPersistScroll(catalog.getAttribute('data-view-rendered') || catalog.getAttribute('data-view'));
      catalog.setAttribute('data-view', view);
      catalogSave();
      if (view !== (catalog.getAttribute('data-view-rendered') || '')) {
        window.location.reload();
        return;
      }
      applySearch();
    }

    function visibleCols() {
      return (catalog.getAttribute('data-cols') || '').trim().split(/\s+/).filter(Boolean);
    }

    function setCols(cols) {
      if (cols.indexOf('title') === -1) cols.unshift('title');
      catalog.setAttribute('data-cols', cols.join(' '));
      catalog.querySelectorAll('[data-col-id]').forEach(function (box) {
        box.checked = cols.indexOf(box.getAttribute('data-col-id')) !== -1;
      });
      catalogSave();
    }

    function sortValue(node, col) {
      return (node.getAttribute('data-sort-' + col) || node.getAttribute('data-sort-title') || '').toString();
    }

    function applySort() {
      var col = catalog.getAttribute('data-sort') || 'title';
      var dir = catalog.getAttribute('data-dir') === 'desc' ? -1 : 1;
      function bySort(a, b) {
        var cmp = sortValue(a, col).localeCompare(sortValue(b, col), undefined, { numeric: true, sensitivity: 'base' });
        if (cmp === 0) cmp = sortValue(a, 'title').localeCompare(sortValue(b, 'title'), undefined, { numeric: true, sensitivity: 'base' });
        return cmp * dir;
      }
      var body = catalog.querySelector('[data-list-body]');
      if (body) {
        var roots = [];
        var map = {};
        Array.prototype.forEach.call(body.children, function (row) {
          var node = { head: row, kids: [] };
          var gid = row.getAttribute('data-group');
          if (gid) map[gid] = node;
          var parent = row.getAttribute('data-parent');
          if (!parent) {
            roots.push(node);
            return;
          }
          if (map[parent]) map[parent].kids.push(node);
          else roots.push(node);
        });
        function appendNode(node) {
          body.appendChild(node.head);
          node.kids.forEach(appendNode);
        }
        roots.sort(function (a, b) { return bySort(a.head, b.head); });
        roots.forEach(appendNode);
      }
      var grid = catalog.querySelector('[data-poster-grid]');
      if (grid) {
        var cards = Array.prototype.slice.call(grid.querySelectorAll('[data-card]'));
        cards.sort(bySort);
        rebuildPosterBuckets(grid, cards);
      }
    }

    function posterBucketLabel(card, col) {
      return (card.getAttribute('data-bucket-' + col) || '').trim() || '—';
    }

    function rebuildPosterBuckets(grid, cards) {
      if (!grid) return;
      grid.querySelectorAll('[data-poster-bucket]').forEach(function (el) {
        el.remove();
      });
      var col = catalog.getAttribute('data-sort') || 'title';
      var last = null;
      cards.forEach(function (card) {
        grid.appendChild(card);
        var label = posterBucketLabel(card, col);
        if (label !== last) {
          var head = document.createElement('h2');
          head.className = 'poster-bucket';
          head.setAttribute('data-poster-bucket', '');
          head.textContent = label;
          grid.insertBefore(head, card);
          last = label;
        }
      });
      syncPosterBuckets();
    }

    function syncPosterBuckets() {
      var grid = catalog.querySelector('[data-poster-grid]');
      if (!grid) return;
      var heading = null;
      var any = false;
      Array.prototype.forEach.call(grid.children, function (el) {
        if (el.hasAttribute('data-poster-bucket')) {
          if (heading) heading.hidden = !any;
          heading = el;
          any = false;
          return;
        }
        if (el.hasAttribute('data-card') && !el.hidden) any = true;
      });
      if (heading) heading.hidden = !any;
    }

    function kindAllowed(el) {
      var allowed = (catalog.getAttribute('data-kinds') || '').trim().split(/\s+/).filter(Boolean);
      if (allowed.length === 0) return true;
      var kind = el.getAttribute('data-kind') || 'movie';
      return allowed.indexOf(kind) !== -1;
    }

    function categoryAllowed(el) {
      var allowed = (catalog.getAttribute('data-categories') || '').trim().split(/\s+/).filter(Boolean);
      if (allowed.length === 0) return true;
      var cat = el.getAttribute('data-category') || 'none';
      return allowed.indexOf(cat) !== -1;
    }

    function rowAllowed(el) {
      return kindAllowed(el) && categoryAllowed(el);
    }

    function catalogWhere(attr, value) {
      var nodes = catalog.querySelectorAll('[' + attr + ']');
      var matches = [];
      for (var i = 0; i < nodes.length; i += 1) {
        if (nodes[i].getAttribute(attr) === value) matches.push(nodes[i]);
      }
      return matches;
    }

    function searchQuery() {
      var input = document.querySelector('[data-search-input]');
      return isDesktopShell()
        ? (input ? input.value.trim().toLowerCase() : '')
        : searchCommitted;
    }

    function searchHit(el, query) {
      return !query || (el.getAttribute('data-search') || '').toLowerCase().indexOf(query) !== -1;
    }

    function groupIsOpen(head) {
      var btn = head.querySelector('[data-expand]');
      return !!(btn && btn.getAttribute('aria-expanded') === 'true');
    }

    function setExpanded(id, open) {
      var btn = catalogWhere('data-expand', id)[0];
      if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      var query = searchQuery();
      catalogWhere('data-parent', id).forEach(function (row) {
        if (!open) {
          row.hidden = true;
          var nested = row.getAttribute('data-group');
          if (nested) setExpanded(nested, false);
          return;
        }
        row.hidden = !(searchHit(row, query) && rowAllowed(row));
      });
    }

    var searchCommitted = '';

    function applySearch() {
      var query = searchQuery();
      var visible = 0;
      var view = catalog.getAttribute('data-view') || 'poster';

      catalog.querySelectorAll('[data-group]').forEach(function (head) {
        var id = head.getAttribute('data-group');
        if (!id) return;
        var kids = catalogWhere('data-parent', id);
        var headHit = searchHit(head, query);
        var kidHit = false;
        kids.forEach(function (kid) {
          if (searchHit(kid, query)) kidHit = true;
        });
        var show = rowAllowed(head) && (headHit || kidHit);
        var nested = head.hasAttribute('data-parent');
        if (!nested) {
          head.hidden = !show;
        } else if (!show) {
          head.hidden = true;
        }
        if (!show) {
          setExpanded(id, false);
        } else if (query && kidHit) {
          setExpanded(id, true);
        } else {
          setExpanded(id, groupIsOpen(head));
        }
      });

      catalog.querySelectorAll('[data-card]:not([data-parent])').forEach(function (card) {
        var hay = (card.getAttribute('data-search') || '').toLowerCase();
        card.hidden = !rowAllowed(card) || !!(query && hay.indexOf(query) === -1);
      });

      var layout = catalog.querySelector('[data-layout="' + view + '"]');
      if (layout) {
        if (view === 'list') {
          layout.querySelectorAll('[data-group], tr[data-card]:not([data-parent])').forEach(function (row) {
            if (!row.hidden) visible += 1;
          });
        } else {
          layout.querySelectorAll('[data-card]').forEach(function (card) {
            if (!card.hidden) visible += 1;
          });
        }
      }
      catalog.querySelectorAll('[data-genre-rail]').forEach(function (rail) {
        var any = false;
        rail.querySelectorAll('[data-card]').forEach(function (card) {
          if (!card.hidden) any = true;
        });
        rail.hidden = !any;
      });
      syncPosterBuckets();
      var empty = document.querySelector('[data-search-empty]');
      if (empty) empty.hidden = !((query || (catalog.getAttribute('data-kinds') || '') === '') && visible === 0);
    }

    catalog.querySelectorAll('[data-catalog-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setView(btn.getAttribute('data-catalog-view') || 'poster');
      });
    });

    function syncSortOptions() {
      var col = catalog.getAttribute('data-sort') || 'title';
      catalog.querySelectorAll('[data-sort-panel] [data-sort]').forEach(function (btn) {
        var on = btn.getAttribute('data-sort') === col;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-checked', on ? 'true' : 'false');
      });
    }

    catalog.querySelectorAll('[data-sort]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var col = btn.getAttribute('data-sort');
        if (!col) return;
        var current = catalog.getAttribute('data-sort');
        var dir = catalog.getAttribute('data-dir') || 'asc';
        if (current === col) dir = dir === 'asc' ? 'desc' : 'asc';
        else dir = 'asc';
        catalog.setAttribute('data-sort', col);
        catalog.setAttribute('data-dir', dir);
        syncSortOptions();
        applySort();
        catalogSave();
      });
    });

    var colPanel = catalog.querySelector('[data-col-panel]');
    var listHead = catalog.querySelector('[data-list-head]');
    var colPressTimer = 0;
    var colOpenedByPress = false;

    function closeColPanel() {
      if (!colPanel || colPanel.hidden) return;
      colPanel.hidden = true;
    }

    function openColPanel(clientX, clientY) {
      if (!colPanel) return;
      colPanel.hidden = false;
      var pad = 8;
      var w = colPanel.offsetWidth;
      var h = colPanel.offsetHeight;
      var x = Math.min(Math.max(pad, clientX), window.innerWidth - w - pad);
      var y = Math.min(Math.max(pad, clientY), window.innerHeight - h - pad);
      colPanel.style.left = x + 'px';
      colPanel.style.top = y + 'px';
    }

    var sortPanel = catalog.querySelector('[data-sort-panel]');
    var sortOpen = catalog.querySelector('[data-sort-open]');

    function closeSortPanel() {
      if (!sortPanel || sortPanel.hidden) return;
      sortPanel.hidden = true;
      if (sortOpen) sortOpen.setAttribute('aria-expanded', 'false');
    }

    function openSortPanel(anchor) {
      if (!sortPanel) return;
      sortPanel.hidden = false;
      if (sortOpen) sortOpen.setAttribute('aria-expanded', 'true');
      var pad = 8;
      var w = sortPanel.offsetWidth;
      var h = sortPanel.offsetHeight;
      var x = pad;
      var y = pad;
      if (anchor && anchor.getBoundingClientRect) {
        var rect = anchor.getBoundingClientRect();
        x = rect.left;
        y = rect.bottom + 6;
      }
      x = Math.min(Math.max(pad, x), window.innerWidth - w - pad);
      y = Math.min(Math.max(pad, y), window.innerHeight - h - pad);
      sortPanel.style.left = x + 'px';
      sortPanel.style.top = y + 'px';
    }

    if (sortOpen && sortPanel) {
      sortOpen.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        if (sortPanel.hidden) openSortPanel(sortOpen);
        else closeSortPanel();
      });
      document.addEventListener('click', function (event) {
        if (sortPanel.hidden) return;
        if (sortPanel.contains(event.target) || sortOpen.contains(event.target)) return;
        closeSortPanel();
      });
    }

    if (colPanel && listHead) {
      listHead.addEventListener('contextmenu', function (event) {
        event.preventDefault();
        openColPanel(event.clientX, event.clientY);
      });
      listHead.addEventListener('touchstart', function (event) {
        if (!event.touches || !event.touches[0]) return;
        var touch = event.touches[0];
        colOpenedByPress = false;
        colPressTimer = window.setTimeout(function () {
          colOpenedByPress = true;
          openColPanel(touch.clientX, touch.clientY);
        }, 520);
      }, { passive: true });
      listHead.addEventListener('touchend', function (event) {
        window.clearTimeout(colPressTimer);
        if (colOpenedByPress) {
          event.preventDefault();
        }
      });
      listHead.addEventListener('touchmove', function () {
        window.clearTimeout(colPressTimer);
      }, { passive: true });
      listHead.addEventListener('touchcancel', function () {
        window.clearTimeout(colPressTimer);
      });
      listHead.addEventListener('click', function (event) {
        if (!colOpenedByPress) return;
        event.preventDefault();
        event.stopPropagation();
        colOpenedByPress = false;
      }, true);
      document.addEventListener('click', function (event) {
        if (colPanel.hidden) return;
        if (colPanel.contains(event.target)) return;
        closeColPanel();
      });
    }

    catalog.addEventListener('click', function (event) {
      var exp = event.target.closest('[data-expand]');
      if (exp) {
        event.preventDefault();
        var gid = exp.getAttribute('data-expand');
        setExpanded(gid, exp.getAttribute('aria-expanded') !== 'true');
        return;
      }
      var openList = event.target.closest('[data-open-list]');
      if (openList) {
        event.preventDefault();
        var sid = openList.getAttribute('data-open-list');
        setView('list');
        setExpanded(sid, true);
        var head = catalog.querySelector('[data-group="' + sid + '"]');
        if (head && head.scrollIntoView) head.scrollIntoView({ block: 'center' });
      }
    });

    catalog.querySelectorAll('[data-kind-filter]').forEach(function (box) {
      box.addEventListener('change', function () {
        var kinds = [];
        catalog.querySelectorAll('[data-kind-filter]').forEach(function (item) {
          if (item.checked) kinds.push(item.getAttribute('data-kind-filter'));
        });
        if (kinds.length === 0) {
          box.checked = true;
          kinds = [box.getAttribute('data-kind-filter')];
        }
        catalog.setAttribute('data-kinds', kinds.join(' '));
        catalogSave();
        applySearch();
      });
    });

    catalog.querySelectorAll('[data-category-filter]').forEach(function (box) {
      box.addEventListener('change', function () {
        var cats = [];
        catalog.querySelectorAll('[data-category-filter]').forEach(function (item) {
          if (item.checked) cats.push(item.getAttribute('data-category-filter'));
        });
        if (cats.length === 0) {
          box.checked = true;
          cats = [box.getAttribute('data-category-filter')];
        }
        catalog.setAttribute('data-categories', cats.join(' '));
        catalogSave();
        applySearch();
      });
    });

    catalog.querySelectorAll('[data-col-id]').forEach(function (box) {
      box.addEventListener('change', function () {
        if (box.disabled) {
          box.checked = true;
          return;
        }
        var cols = visibleCols().filter(function (id) { return id !== box.getAttribute('data-col-id'); });
        if (box.checked) cols.push(box.getAttribute('data-col-id'));
        setCols(cols);
      });
    });

    var search = document.querySelector('[data-search-input]');
    if (search) {
      bindTitleSearch(search, function () {
        searchCommitted = search.value.trim().toLowerCase();
        applySearch();
      });
    }

    var listBody = catalog.querySelector('[data-list-body]');
    if (listBody) {
      listBody.addEventListener('click', function (event) {
        if (event.target.closest('a, [data-expand], [data-poster-open]')) return;
        var row = event.target.closest('tr[data-card]');
        if (!row) return;
        var link = row.querySelector('a.catalog-row-link');
        if (link) window.location.href = link.href;
      });
    }

    try {
      var stored = localStorage.getItem('media_catalog');
      if (stored) {
        var parsed = JSON.parse(stored);
        if (parsed && typeof parsed === 'object') {
          if (parsed.view) catalog.setAttribute('data-view', parsed.view);
          if (parsed.sort) catalog.setAttribute('data-sort', parsed.sort);
          if (parsed.dir) catalog.setAttribute('data-dir', parsed.dir);
          if (Array.isArray(parsed.columns)) catalog.setAttribute('data-cols', parsed.columns.join(' '));
          if (Array.isArray(parsed.kinds)) {
            var allowedKinds = ['movie', 'show', 'documentary'];
            var kinds = parsed.kinds.filter(function (id) { return allowedKinds.indexOf(id) !== -1; });
            var knownKinds = Array.isArray(parsed.kinds_known) ? parsed.kinds_known : [];
            allowedKinds.forEach(function (id) {
              if (knownKinds.indexOf(id) === -1 && kinds.indexOf(id) === -1) kinds.push(id);
            });
            if (kinds.length === 0) kinds = allowedKinds.slice();
            catalog.setAttribute('data-kinds', kinds.join(' '));
            catalog.querySelectorAll('[data-kind-filter]').forEach(function (box) {
              box.checked = kinds.indexOf(box.getAttribute('data-kind-filter')) !== -1;
            });
          }
          if (Array.isArray(parsed.categories)) {
            var allowedCats = Array.prototype.map.call(catalog.querySelectorAll('[data-category-filter]'), function (box) {
              return box.getAttribute('data-category-filter');
            }).filter(Boolean);
            var cats = parsed.categories.filter(function (id) { return allowedCats.indexOf(id) !== -1; });
            var known = Array.isArray(parsed.categories_known) ? parsed.categories_known : [];
            allowedCats.forEach(function (id) {
              if (known.indexOf(id) === -1 && cats.indexOf(id) === -1) cats.push(id);
            });
            if (cats.length === 0) cats = allowedCats;
            catalog.setAttribute('data-categories', cats.join(' '));
            catalog.querySelectorAll('[data-category-filter]').forEach(function (box) {
              box.checked = cats.indexOf(box.getAttribute('data-category-filter')) !== -1;
            });
          }
          var nextView = catalog.getAttribute('data-view') || 'poster';
          if (nextView !== (catalog.getAttribute('data-view-rendered') || '')) {
            catalogPersistScroll(catalog.getAttribute('data-view-rendered') || '');
            catalogSave();
            window.location.reload();
            return;
          }
        }
      }
    } catch (e) {}
    applySearch();
    catalogRestoreScroll();
  }

  function overlayOpen(name) {
    var el = document.querySelector('[data-overlay="' + name + '"]');
    if (!el) return;
    document.querySelectorAll('[data-overlay]').forEach(function (item) {
      item.hidden = item !== el;
    });
    el.hidden = false;
    document.body.classList.add('overlay-open');
    var close = el.querySelector('.overlay-close');
    if (close) close.focus();
  }

  function overlayClose(el) {
    if (!el) {
      document.querySelectorAll('[data-overlay]').forEach(function (item) {
        item.hidden = true;
      });
      document.body.classList.remove('overlay-open');
      return;
    }
    el.hidden = true;
    if (!document.querySelector('[data-overlay]:not([hidden])')) {
      document.body.classList.remove('overlay-open');
    }
  }

  function fillPosterOverlay(btn) {
    var img = document.querySelector('[data-poster-overlay-img]');
    var fallback = document.querySelector('[data-poster-overlay-fallback]');
    var initial = document.querySelector('[data-poster-overlay-initial]');
    var title = document.querySelector('[data-poster-overlay-title]');
    var caption = document.querySelector('[data-poster-overlay-caption]');
    var src = btn.getAttribute('data-poster-src') || '';
    var label = btn.getAttribute('data-poster-title') || '';
    if (title) title.textContent = label;
    if (caption) caption.textContent = btn.getAttribute('data-poster-caption') || '';
    if (initial) initial.textContent = btn.getAttribute('data-poster-initial') || '?';
    if (img && src) {
      img.src = src;
      img.alt = label;
      img.hidden = false;
      if (fallback) fallback.hidden = true;
    } else {
      if (img) {
        img.removeAttribute('src');
        img.hidden = true;
      }
      if (fallback) fallback.hidden = false;
    }
  }

  function setText(selector, value) {
    document.querySelectorAll(selector).forEach(function (node) {
      node.textContent = value;
    });
  }

  function scanIsStopping(data) {
    if (!data) return false;
    return !!(data.cancel_requested) || data.phase === 'stopping';
  }

  function pageScanCatalog() {
    return (scanPanel && scanPanel.getAttribute('data-scan-catalog')) || 'video';
  }

  function statusScanCatalog(data) {
    if (data && data.state === 'running') {
      return data.catalog === 'music' ? 'music' : 'video';
    }
    return pageScanCatalog();
  }

  function catalogTitle(cat) {
    return cat === 'music' ? 'Music' : 'Video';
  }

  function catalogNoun(cat) {
    return cat === 'music' ? 'music' : 'video';
  }

  function scanStateLabel(state, data) {
    if (scanIsStopping(data) && state === 'running') return 'Stopping';
    if (state === 'running') return 'In progress';
    if (state === 'done') return 'Done';
    if (state === 'stopped') return 'Stopped';
    if (state === 'error') return 'Failed';
    return 'Ready';
  }

  function scanTitleLabel(state, data) {
    var name = catalogTitle(statusScanCatalog(data));
    var noun = catalogNoun(statusScanCatalog(data));
    if (scanIsStopping(data) && state === 'running') return 'Stopping ' + noun + ' scan';
    if (state === 'running') return 'Scanning ' + noun;
    if (state === 'done') return name + ' scan finished';
    if (state === 'stopped') return name + ' scan stopped';
    if (state === 'error') return name + ' scan failed';
    return name + ' scan';
  }

  function elapsedLabel(startedAt) {
    var start = Number(startedAt || 0);
    if (!start) return '—';
    var sec = Math.max(0, Math.floor(Date.now() / 1000) - start);
    var hrs = Math.floor(sec / 3600);
    var mins = Math.floor((sec % 3600) / 60);
    var rem = sec % 60;
    if (hrs > 0) return hrs + 'h ' + mins + 'm';
    if (mins > 0) return mins + 'm ' + rem + 's';
    return rem + 's';
  }

  var scanLive = document.querySelector('[data-scan-live]');
  var scanPanel = document.querySelector('[data-scan-panel]');
  var grokPanel = document.querySelector('[data-grok-panel]');
  var lastScanState = '';
  var lastScanKind = 'tmdb';
  var lastScanPaused = false;
  var scanPumping = false;
  var grokPumping = false;
  var scanStopRequested = false;
  var grokStopRequested = false;

  function scanRunUrl() {
    if (scanLive && scanLive.getAttribute('data-scan-run-url')) {
      return scanLive.getAttribute('data-scan-run-url');
    }
    if (scanPanel && scanPanel.getAttribute('data-scan-run-url')) {
      return scanPanel.getAttribute('data-scan-run-url');
    }
    return '';
  }

  function grokRunUrl() {
    if (scanLive && scanLive.getAttribute('data-grok-run-url')) {
      return scanLive.getAttribute('data-grok-run-url');
    }
    if (grokPanel && grokPanel.getAttribute('data-grok-run-url')) {
      return grokPanel.getAttribute('data-grok-run-url');
    }
    return '';
  }

  function activityBusy() {
    return lastScanState === 'running';
  }

  function restartCssSpin(el) {
    if (!el) return;
    el.classList.remove('is-spinning');
    void el.offsetWidth;
    el.classList.add('is-spinning');
  }

  function setLiveActive(active, message) {
    if (scanLive) {
      scanLive.hidden = !active;
      scanLive.setAttribute('aria-label', active ? (message || 'Scan in progress') : 'Scan idle');
    }
    document.body.classList.toggle('scan-running', active);
    var barSpin = document.querySelector('[data-scan-bar-spin]');
    if (barSpin) barSpin.hidden = !active;
    if (active) {
      restartCssSpin(scanLive ? scanLive.querySelector('.scan-live-ring') : null);
      restartCssSpin(barSpin);
    } else {
      var ring = scanLive ? scanLive.querySelector('.scan-live-ring') : null;
      if (ring) ring.classList.remove('is-spinning');
      if (barSpin) barSpin.classList.remove('is-spinning');
    }
    var launchWrap = document.querySelector('[data-scan-launch-wrap]');
    if (launchWrap) launchWrap.hidden = active;
    var grokLaunch = document.querySelector('[data-grok-launch-wrap]');
    if (grokLaunch) grokLaunch.hidden = active;
  }

  function setOverlayKind(kind, data) {
    var tmdbStats = document.querySelector('[data-overlay-stats="tmdb"]');
    var grokStats = document.querySelector('[data-overlay-stats="grok"]');
    if (tmdbStats) tmdbStats.hidden = kind === 'grok';
    if (grokStats) grokStats.hidden = kind !== 'grok';
    // Continue visibility is owned by paintScan / paintGrok (paused flag).
    var link = document.querySelector('[data-scan-overlay-link]');
    if (link && scanLive) {
      if (kind === 'grok') {
        link.href = scanLive.getAttribute('data-grok-page-url') || link.href;
        link.textContent = 'Open video scan page';
      } else {
        var cat = statusScanCatalog(data || {});
        if (cat === 'music') {
          link.href = scanLive.getAttribute('data-music-scan-page-url') || scanLive.getAttribute('data-scan-page-url') || link.href;
          link.textContent = 'Open music scan page';
        } else {
          link.href = scanLive.getAttribute('data-scan-page-url') || link.href;
          link.textContent = 'Open video scan page';
        }
      }
    }
  }

  function grokUiEnabled() {
    var toggle = document.querySelector('[data-grok-toggle]');
    return !!(toggle && toggle.checked);
  }

  function setGrokDependentUi(on) {
    on = !!on;
    document.querySelectorAll('[data-grok-dependent]').forEach(function (el) {
      if (on) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
    });
    var pause = document.querySelector('[data-grok-pause-toggle]');
    if (pause) {
      pause.disabled = !on;
    }
    document.querySelectorAll('[data-grok-hint-on]').forEach(function (el) {
      el.hidden = !on;
    });
    document.querySelectorAll('[data-grok-hint-off]').forEach(function (el) {
      el.hidden = on;
    });
  }

  function paintScan(data) {
    if (!data) return;
    var state = data.state || 'idle';
    var stopping = scanIsStopping(data) && state === 'running';
    var running = state === 'running';
    var active = running;
    var found = Number(data.found || 0);
    var pending = Number(data.pending || 0);
    var lookups = Number(data.lookups || 0);
    var unmatched = Number(data.unmatched || 0);
    var unidentified = Number(data.unidentified || 0);
    var processed = Number(data.processed || 0);
    var total = Number(data.total || 0);
    var message = data.message || '';
    var phase = data.phase || '';
    var titleState = state;
    var titleData = data;
    if (!running && scanPanel) {
      var pageCat = pageScanCatalog();
      var statusCat = data.catalog === 'music' ? 'music' : 'video';
      if (statusCat !== pageCat) {
        message = scanPanel.getAttribute('data-scan-idle') || message;
        titleState = 'idle';
        titleData = { state: 'idle', catalog: pageCat };
      }
    }

    lastScanKind = 'tmdb';
    var noun = catalogNoun(statusScanCatalog(titleData));
    setLiveActive(active, message || (stopping ? ('Stopping ' + noun + ' scan') : (catalogTitle(statusScanCatalog(data)) + ' scan in progress')));
    setOverlayKind('tmdb', data);

    setText('[data-scan-kicker]', scanStateLabel(titleState, titleData));
    setText('[data-scan-title]', scanTitleLabel(titleState, titleData));
    setText('[data-scan-message]', message);
    setText('[data-scan-found]', String(found));
    setText('[data-scan-pending]', String(pending));
    setText('[data-scan-lookups]', String(lookups));
    setText('[data-scan-matched]', String(data.matched || found));
    setText('[data-scan-unmatched]', String(unmatched));
    setText('[data-scan-unidentified]', String(unidentified));

    var bar = document.querySelector('[data-scan-bar]');
    var barWrap = bar ? bar.parentElement : null;
    if (bar && barWrap) {
      var pct = 0;
      var indeterminate = running && !stopping && ((phase === 'walk' && total === 0) || (phase === 'lookup' && pending === 0 && lookups === 0));
      barWrap.classList.toggle('is-indeterminate', indeterminate);
      if (phase === 'walk' && total === 0) {
        pct = 0;
      } else if (phase === 'grok') {
        var grokQueued = Number(data.grok_queued || 0);
        var grokAttempted = Number(data.grok_attempted || 0);
        pct = grokQueued > 0 ? Math.min(100, Math.round((grokAttempted / grokQueued) * 100)) : 0;
      } else if (phase === 'lookup' || phase === 'stopping') {
        if (pending > 0) {
          pct = Math.min(99, Math.round((Math.min(pending, lookups) / pending) * 100));
        } else if (total > 0) {
          pct = Math.min(99, Math.round((processed / total) * 100));
        }
      } else if (total > 0) {
        pct = Math.min(100, Math.round((processed / total) * 100));
      }
      if (state === 'done' || state === 'stopped') pct = 100;
      if (indeterminate) pct = 30;
      bar.style.width = pct + '%';
    }

    var stopBtn = document.querySelector('[data-scan-stop-btn]');
    if (stopBtn) {
      if (!running) {
        stopBtn.hidden = true;
        stopBtn.disabled = false;
        stopBtn.textContent = 'Stop ' + catalogNoun(statusScanCatalog(data)) + ' scan';
        scanStopRequested = false;
      } else if (stopping || scanStopRequested) {
        stopBtn.hidden = false;
        stopBtn.disabled = true;
        stopBtn.textContent = 'Stopping…';
      } else {
        stopBtn.hidden = false;
        stopBtn.disabled = false;
        stopBtn.textContent = 'Stop ' + catalogNoun(statusScanCatalog(data)) + ' scan';
      }
    }

    setText('[data-scan-overlay-kicker]', scanStateLabel(state, data));
    setText('[data-scan-overlay-title]', stopping ? ('Stopping ' + noun + ' scan') : (running ? (catalogTitle(statusScanCatalog(data)) + ' scan in progress') : scanTitleLabel(state, data)));
    setText('[data-scan-overlay-message]', message || (running ? 'Working…' : ('No ' + noun + ' scan is running.')));
    setText('[data-scan-overlay-found]', String(found));
    setText('[data-scan-overlay-pending]', String(pending));
    setText('[data-scan-overlay-lookups]', String(lookups));
    setText('[data-scan-overlay-unmatched]', String(unmatched));
    setText('[data-scan-overlay-unidentified]', String(unidentified));
    setText('[data-scan-overlay-files]', total > 0 ? (processed + ' / ' + total) : (processed > 0 ? String(processed) : '—'));
    setText('[data-scan-overlay-elapsed]', running || state === 'done' || state === 'stopped'
      ? elapsedLabel(data.started_at)
      : '—');
    setText('[data-scan-grok-matched]', String(data.grok_matched || 0));
    setText('[data-scan-grok-attempted]', String(data.grok_attempted || 0));
    setText('[data-scan-grok-left]', String(data.grok_left || 0));
    setText('[data-scan-overlay-grok-matched]', String(data.grok_matched || 0));
    setText('[data-scan-overlay-grok-attempted]', String(data.grok_attempted || 0));
    setText('[data-scan-overlay-grok-left]', String(data.grok_left || 0));
    var grokCost = data.grok_cost_label || '$0.0000';
    setText('[data-scan-grok-cost]', grokCost);
    setText('[data-scan-overlay-grok-cost]', grokCost);

    var grokOn = typeof data.grok_enabled === 'boolean' ? data.grok_enabled : grokUiEnabled();
    setGrokDependentUi(grokOn);

    var showWait = grokOn && running && !stopping && !scanStopRequested && !!data.paused;
    document.querySelectorAll('[data-grok-wait-actions]').forEach(function (el) {
      el.classList.toggle('is-visible', showWait);
      if (showWait) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
    });

    var grokToggle = document.querySelector('[data-grok-toggle]');
    if (grokToggle && typeof data.grok_enabled === 'boolean' && grokToggle !== document.activeElement) {
      grokToggle.checked = data.grok_enabled;
    }
    var grokPauseToggle = document.querySelector('[data-grok-pause-toggle]');
    if (grokPauseToggle && typeof data.grok_pause === 'boolean' && grokPauseToggle !== document.activeElement) {
      grokPauseToggle.checked = data.grok_pause;
    }

    lastScanState = state;
    lastScanKind = 'tmdb';
    lastScanPaused = running && !!data.paused;
  }

  function paintGrok(data) {
    if (!data) return;
    var state = data.state || 'idle';
    var running = state === 'running';
    var stopping = running && !!(data.cancel_requested);
    var message = data.message || '';
    lastScanKind = 'grok';
    lastScanState = state;
    setLiveActive(running, message || (stopping ? 'Stopping Grok' : 'Grok resolve in progress'));
    setOverlayKind('grok', data);

    setText('[data-grok-kicker]', stopping ? 'Stopping' : (state === 'running' ? 'In progress' : (state === 'done' ? 'Done' : (state === 'stopped' ? 'Stopped' : (state === 'error' ? 'Failed' : 'Ready')))));
    setText('[data-grok-title]', stopping ? 'Stopping' : (running ? 'Resolving unmatched' : (state === 'done' ? 'Grok finished' : (state === 'stopped' ? 'Grok stopped' : (state === 'error' ? 'Grok failed' : 'Resolve unmatched (Grok)')))));
    setText('[data-grok-message]', message);
    setText('[data-grok-matched]', String(data.matched || 0));
    setText('[data-grok-attempted]', String(data.attempted || 0));
    setText('[data-grok-pending]', String(data.pending || 0));
    setText('[data-grok-low]', String(data.skipped_low_confidence || 0));
    setText('[data-grok-invalid]', String(data.invalid_rejected || 0));
    setText('[data-grok-errors]', String(data.errors || 0));

    setText('[data-scan-overlay-kicker]', stopping ? 'Stopping' : (running ? 'In progress' : (state === 'done' ? 'Done' : (state === 'stopped' ? 'Stopped' : (state === 'error' ? 'Failed' : 'Grok')))));
    setText('[data-scan-overlay-title]', stopping ? 'Stopping Grok' : (running ? 'Grok resolve' : (state === 'done' ? 'Grok finished' : (state === 'stopped' ? 'Grok stopped' : (state === 'error' ? 'Grok failed' : 'Grok resolve')))));
    setText('[data-scan-overlay-message]', message || (running ? 'Resolving unmatched titles…' : 'No Grok resolve is running.'));
    setText('[data-grok-overlay-matched]', String(data.matched || 0));
    setText('[data-grok-overlay-attempted]', String(data.attempted || 0));
    setText('[data-grok-overlay-pending]', String(data.pending || 0));
    setText('[data-grok-overlay-low]', String(data.skipped_low_confidence || 0));
    setText('[data-grok-overlay-invalid]', String(data.invalid_rejected || 0));
    setText('[data-grok-overlay-errors]', String(data.errors || 0));
    setText('[data-grok-overlay-elapsed]', running || state === 'done' || state === 'stopped' || state === 'error'
      ? elapsedLabel(data.started_at)
      : '—');

    var stopBtn = document.querySelector('[data-grok-stop-btn]');
    if (stopBtn) {
      if (!running) {
        stopBtn.hidden = true;
        stopBtn.disabled = false;
        stopBtn.textContent = 'Stop Grok';
        grokStopRequested = false;
      } else if (stopping || grokStopRequested) {
        stopBtn.hidden = false;
        stopBtn.disabled = true;
        stopBtn.textContent = 'Stopping…';
      } else {
        stopBtn.hidden = false;
        stopBtn.disabled = false;
        stopBtn.textContent = 'Stop Grok';
      }
    }
    var showWait = running && !stopping && !grokStopRequested && !!data.paused;
    document.querySelectorAll('[data-grok-wait-actions]').forEach(function (el) {
      el.classList.toggle('is-visible', showWait);
      if (showWait) el.removeAttribute('hidden');
      else el.setAttribute('hidden', 'hidden');
    });
  }

  function paintLive(data) {
    if (!data) return;
    if ((data.kind || 'tmdb') === 'grok') {
      paintGrok(data);
    } else {
      paintScan(data);
    }
  }

  function pumpScan(start, resumePause) {
    var runUrl = scanRunUrl();
    if (!runUrl || scanPumping) return;
    if (start && scanPanel && scanPanel.getAttribute('data-scan-enabled') === '0') return;
    if (start) {
      var mode = typeof start === 'string' ? start : ((scanPanel && scanPanel.getAttribute('data-scan-start')) || 'retry');
      if (mode === '1' || mode === 'true') mode = 'retry';
      if (mode !== 'unidentified') mode = 'retry';
      runUrl += (runUrl.indexOf('?') >= 0 ? '&' : '?') + 'start=1&mode=' + encodeURIComponent(mode);
    }
    if (resumePause) {
      runUrl += (runUrl.indexOf('?') >= 0 ? '&' : '?') + 'continue=1';
    }
    scanPumping = true;
    fetch(runUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        scanPumping = false;
        if (data && data.blocked) {
          paintLive(data);
          return;
        }
        if (data && data.unsupported) {
          lastScanState = data.state || 'idle';
          paintLive(data);
          return;
        }
        paintLive(data);
        var state = data && data.state;
        var kind = data && data.kind;
        if (data && data.paused) {
          return;
        }
        if (kind === 'grok') {
          if (state === 'running' && !grokPumping) pumpGrok();
          return;
        }
        if (state === 'running' && !data.busy) {
          pumpScan();
          return;
        }
        if (state === 'running' && data.busy) {
          setTimeout(function () {
            if (lastScanState === 'running' && lastScanKind === 'tmdb' && !lastScanPaused && !scanStopRequested) pumpScan();
          }, 400);
        }
      })
      .catch(function () {
        scanPumping = false;
        setTimeout(function () {
          if (lastScanState === 'running' && lastScanKind === 'tmdb' && !lastScanPaused && !scanStopRequested) pumpScan();
        }, 1500);
      });
  }

  function pumpGrok(start) {
    var runUrl = grokRunUrl();
    if (!runUrl || grokPumping) return;
    if (grokStopRequested && !start) return;
    if (start) {
      grokStopRequested = false;
      runUrl += (runUrl.indexOf('?') >= 0 ? '&' : '?') + 'start=1';
    }
    grokPumping = true;
    fetch(runUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        grokPumping = false;
        if (data && data.blocked) {
          paintLive(data);
          return;
        }
        paintLive(data);
        var state = data && data.state;
        var kind = data && data.kind;
        if (kind === 'tmdb') {
          if (state === 'running' && !scanPumping) pumpScan();
          return;
        }
        if (scanStopRequested || grokStopRequested || (data && data.cancel_requested) || state === 'stopped') {
          return;
        }
        if (data && data.paused) {
          return;
        }
        if (state === 'running' && !data.busy) {
          pumpGrok();
          return;
        }
        if (state === 'running' && data.busy) {
          setTimeout(function () {
            if (!grokStopRequested && lastScanState === 'running' && lastScanKind === 'grok') pumpGrok();
          }, 400);
        }
      })
      .catch(function () {
        grokPumping = false;
        setTimeout(function () {
          if (!grokStopRequested && lastScanState === 'running' && lastScanKind === 'grok') pumpGrok();
        }, 1500);
      });
  }

  function pollScan() {
    if (!scanLive) return;
    var url = scanLive.getAttribute('data-scan-status-url');
    if (!url) return;
    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        paintLive(data);
        if (!data || data.state !== 'running') return;
        if (data.paused || scanStopRequested) return;
        if ((data.kind || 'scan') === 'grok') {
          if (!grokPumping && !data.cancel_requested && !grokStopRequested) pumpGrok();
        } else if (!scanPumping) {
          pumpScan();
        }
      })
      .catch(function () {});
  }

  if (scanLive) {
    pollScan();
    setInterval(pollScan, 1000);
  }

  if (scanPanel && scanPanel.getAttribute('data-scan-start')) {
    if (scanLive) scanLive.hidden = false;
    document.body.classList.add('scan-running');
    lastScanState = 'running';
    pumpScan(scanPanel.getAttribute('data-scan-start'));
  }

  document.querySelectorAll('[data-scan-launch]').forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      if (!window.fetch) return;
      event.preventDefault();
      if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
      if (scanPanel && scanPanel.getAttribute('data-scan-enabled') === '0') return;
      if (activityBusy() && lastScanKind === 'grok') {
        overlayOpen('scan');
        return;
      }
      var mode = btn.getAttribute('data-scan-launch') || 'retry';
      if (scanPanel) scanPanel.setAttribute('data-scan-start', mode);
      lastScanKind = 'tmdb';
      lastScanState = 'running';
      setLiveActive(true, 'Starting ' + catalogNoun(pageScanCatalog()) + ' scan…');
      pumpScan(mode);
    });
  });

  if (grokPanel && grokPanel.getAttribute('data-grok-start') === '1') {
    lastScanKind = 'grok';
    lastScanState = 'running';
    setLiveActive(true, 'Starting Grok resolve…');
    pumpGrok(true);
  }

  document.querySelectorAll('[data-grok-launch]').forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      if (!window.fetch) return;
      event.preventDefault();
      if (activityBusy() && lastScanKind === 'tmdb') {
        overlayOpen('scan');
        return;
      }
      lastScanKind = 'grok';
      lastScanState = 'running';
      setLiveActive(true, 'Starting Grok resolve…');
      pumpGrok(true);
    });
  });

  document.querySelectorAll('[data-grok-continue]').forEach(function (grokContinue) {
    grokContinue.addEventListener('click', function (event) {
      event.preventDefault();
      if (scanStopRequested || grokStopRequested) return;
      pumpScan(false, true);
    });
  });

  var grokToggle = document.querySelector('[data-grok-toggle]');
  var grokToggleUrl = scanPanel ? scanPanel.getAttribute('data-grok-toggle-url') : '';
  if (grokToggle) {
    setGrokDependentUi(grokToggle.checked);
  } else {
    setGrokDependentUi(false);
  }
  if (grokToggle && grokToggleUrl) {
    grokToggle.addEventListener('change', function () {
      setGrokDependentUi(grokToggle.checked);
      fetch(grokToggleUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ enabled: grokToggle.checked ? '1' : '0' })
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (data && typeof data.enabled === 'boolean') {
          grokToggle.checked = data.enabled;
          setGrokDependentUi(data.enabled);
        }
      }).catch(function () {});
    });
  }

  var grokPauseToggle = document.querySelector('[data-grok-pause-toggle]');
  var grokPauseUrl = scanPanel ? scanPanel.getAttribute('data-grok-pause-url') : '';
  if (grokPauseToggle && grokPauseUrl) {
    grokPauseToggle.addEventListener('change', function () {
      fetch(grokPauseUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ enabled: grokPauseToggle.checked ? '1' : '0' })
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (data && typeof data.pause === 'boolean') grokPauseToggle.checked = data.pause;
        paintLive(data);
        if (data && data.pause === false && (data.state || lastScanState) === 'running' && !scanStopRequested) {
          pumpScan(false);
        }
      }).catch(function () {});
    });
  }

  var scanOptionsUrl = scanPanel ? scanPanel.getAttribute('data-scan-options-url') : '';
  if (scanOptionsUrl) {
    document.querySelectorAll('[data-scan-option]').forEach(function (box) {
      box.addEventListener('change', function () {
        var payload = {};
        document.querySelectorAll('[data-scan-option]').forEach(function (item) {
          var key = item.getAttribute('data-scan-option');
          if (key) payload[key] = item.checked ? 1 : 0;
        });
        fetch(scanOptionsUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (data) {
          if (!data || !data.lookup_targets) return;
          document.querySelectorAll('[data-scan-option]').forEach(function (item) {
            var key = item.getAttribute('data-scan-option');
            if (key && typeof data.lookup_targets[key] === 'boolean') {
              item.checked = data.lookup_targets[key];
            }
          });
        }).catch(function () {});
      });
    });
  }

  var grokStopForm = document.querySelector('[data-grok-stop]');
  if (grokStopForm) {
    grokStopForm.addEventListener('submit', function (event) {
      if (!window.fetch) return;
      event.preventDefault();
      grokStopRequested = true;
      var stopBtn = document.querySelector('[data-grok-stop-btn]');
      if (stopBtn) {
        stopBtn.disabled = true;
        stopBtn.textContent = 'Stopping…';
      }
      setText('[data-grok-kicker]', 'Stopping');
      setText('[data-grok-title]', 'Stopping');
      setText('[data-grok-message]', 'Stop requested. Finishing the current Grok batch…');
      fetch(grokStopForm.action, { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          paintLive(data);
        })
        .catch(function () {});
    });
  }

  var stopForm = document.querySelector('[data-scan-stop]');
  if (stopForm) {
    stopForm.addEventListener('submit', function (event) {
      if (!window.fetch) return;
      event.preventDefault();
      scanStopRequested = true;
      var stopBtn = document.querySelector('[data-scan-stop-btn]');
      if (stopBtn) {
        stopBtn.disabled = true;
        stopBtn.textContent = 'Stopping…';
      }
      var noun = catalogNoun(pageScanCatalog());
      setText('[data-scan-kicker]', 'Stopping');
      setText('[data-scan-title]', 'Stopping ' + noun + ' scan');
      setText('[data-scan-message]', 'Stop requested. Finishing the current ' + noun + ' step…');
      fetch(stopForm.action, { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          paintLive(data);
          if (!scanPumping) pumpScan();
        })
        .catch(function () {
          if (!scanPumping) pumpScan();
        });
    });
  }

  document.addEventListener('click', function (event) {
    var dismiss = event.target.closest('[data-overlay-dismiss]');
    if (dismiss) {
      overlayClose(dismiss.closest('[data-overlay]'));
      return;
    }
    var about = event.target.closest('[data-about-open]');
    if (about) {
      event.preventDefault();
      overlayOpen('about');
      return;
    }
    var live = event.target.closest('[data-scan-live]');
    if (live) {
      event.preventDefault();
      overlayOpen('scan');
      pollScan();
      return;
    }
    var poster = event.target.closest('[data-poster-open]');
    if (poster) {
      event.preventDefault();
      event.stopPropagation();
      fillPosterOverlay(poster);
      overlayOpen('poster');
    }
  });

  var logRoot = document.querySelector('[data-log-console]');
  if (logRoot) {
    var logList = logRoot.querySelector('[data-log-list]');
    var logSeen = {};
    var logSince = 0;
    var logTimer = null;
    AppLog.feedUrl = logRoot.getAttribute('data-log-feed-url') || '';
    AppLog.writeUrl = logRoot.getAttribute('data-log-write-url') || '';
    AppLog.clearUrl = logRoot.getAttribute('data-log-clear-url') || '';

    function logOpen() {
      return logRoot.classList.contains('is-open');
    }

    function logTime(ts) {
      var d = new Date((Number(ts) || 0) * 1000);
      if (isNaN(d.getTime())) return '--:--:--';
      return String(d.getHours()).padStart(2, '0') + ':'
        + String(d.getMinutes()).padStart(2, '0') + ':'
        + String(d.getSeconds()).padStart(2, '0');
    }

    function logAppend(event) {
      if (!logList || !event || !event.message) return;
      var id = String(event.id || event.ts + event.message);
      if (logSeen[id]) return;
      logSeen[id] = true;
      var ts = Number(event.ts || 0);
      if (ts > logSince) logSince = ts;
      var empty = logList.querySelector('.log-console-empty');
      if (empty) empty.remove();
      var li = document.createElement('li');
      if (event.level === 'warn' || event.level === 'error' || event.level === 'debug') {
        li.className = 'is-' + event.level;
      }
      var time = document.createElement('span');
      time.className = 'log-console-time';
      time.textContent = logTime(ts);
      var topic = document.createElement('span');
      topic.className = 'log-console-topic';
      topic.textContent = event.topic || 'app';
      var msg = document.createElement('span');
      msg.className = 'log-console-msg';
      msg.textContent = event.message;
      li.appendChild(time);
      li.appendChild(topic);
      li.appendChild(msg);
      logList.appendChild(li);
      logList.scrollTop = logList.scrollHeight;
    }

    function logEmpty() {
      if (!logList || logList.children.length) return;
      var li = document.createElement('li');
      li.className = 'log-console-empty';
      li.textContent = 'No log events yet.';
      logList.appendChild(li);
    }

    function logPull() {
      if (!AppLog.feedUrl) return;
      fetch(AppLog.feedUrl + (AppLog.feedUrl.indexOf('?') >= 0 ? '&' : '?') + 'since=' + encodeURIComponent(String(logSince)), {
        cache: 'no-store',
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var events = data && data.events ? data.events : [];
          events.forEach(logAppend);
          if (logOpen()) logEmpty();
        })
        .catch(function () {});
    }

    function setLogOpen(open) {
      logRoot.classList.toggle('is-open', open);
      var panel = logRoot.querySelector('.log-console-panel');
      if (panel) panel.hidden = !open;
      logRoot.querySelectorAll('[data-log-toggle]').forEach(function (btn) {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      if (open) {
        logPull();
        if (!logTimer) logTimer = setInterval(logPull, 2000);
        if (logList) logList.scrollTop = logList.scrollHeight;
      } else if (logTimer) {
        clearInterval(logTimer);
        logTimer = null;
      }
    }

    AppLog.subscribe(logAppend);

    var verboseBtn = logRoot.querySelector('[data-log-verbose]');
    function verboseOn() {
      try {
        return localStorage.getItem('media_log_verbose') === '1';
      } catch (e) {
        return false;
      }
    }
    function setVerbose(on) {
      logRoot.classList.toggle('is-verbose', on);
      if (verboseBtn) {
        verboseBtn.classList.toggle('is-active', on);
        verboseBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      }
      try { localStorage.setItem('media_log_verbose', on ? '1' : '0'); } catch (e) {}
      document.cookie = 'media_log_verbose=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
    }
    setVerbose(verboseOn());
    if (verboseBtn) {
      verboseBtn.addEventListener('click', function (event) {
        event.preventDefault();
        setVerbose(!logRoot.classList.contains('is-verbose'));
      });
    }

    var clearBtn = logRoot.querySelector('[data-log-clear]');
    if (clearBtn) {
      clearBtn.addEventListener('click', function (event) {
        event.preventDefault();
        function wipeView() {
          logSeen = {};
          logSince = Date.now() / 1000;
          if (logList) logList.innerHTML = '';
          logEmpty();
        }
        if (!AppLog.clearUrl) {
          wipeView();
          return;
        }
        fetch(AppLog.clearUrl, { method: 'POST', credentials: 'same-origin', cache: 'no-store' })
          .then(function () { wipeView(); })
          .catch(function () { wipeView(); });
      });
    }

    logRoot.querySelectorAll('[data-log-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function (event) {
        event.preventDefault();
        setLogOpen(!logOpen());
      });
    });
  }

  var groupDetail = document.querySelector('[data-group-detail]');
  if (groupDetail) {
    try { history.scrollRestoration = 'manual'; } catch (e) {}
    var groupScrollTimer = 0;
    function groupScrollKey() {
      return 'media-group-scroll:' + (groupDetail.getAttribute('data-group-detail') || location.search);
    }
    function groupPersistScroll() {
      try {
        sessionStorage.setItem(groupScrollKey(), String(window.scrollY || window.pageYOffset || 0));
      } catch (e) {}
    }
    function groupRestoreScroll() {
      var raw;
      try { raw = sessionStorage.getItem(groupScrollKey()); } catch (e) { return; }
      if (raw == null || raw === '') return;
      var y = parseInt(raw, 10);
      if (!isFinite(y) || y < 1) return;
      function apply() {
        var max = Math.max(0, (document.documentElement.scrollHeight || 0) - window.innerHeight);
        window.scrollTo(0, Math.min(y, max));
      }
      apply();
      requestAnimationFrame(apply);
      window.addEventListener('load', apply, { once: true });
      var n = 0;
      var timer = window.setInterval(function () {
        apply();
        n += 1;
        if (n >= 10) window.clearInterval(timer);
      }, 100);
    }
    window.addEventListener('scroll', function () {
      if (groupScrollTimer) return;
      groupScrollTimer = window.setTimeout(function () {
        groupScrollTimer = 0;
        groupPersistScroll();
      }, 80);
    }, { passive: true });
    window.addEventListener('pagehide', function () { groupPersistScroll(); });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') groupPersistScroll();
    });
    document.querySelectorAll('.group-ep-row').forEach(function (link) {
      link.addEventListener('click', function () { groupPersistScroll(); });
    });
    groupRestoreScroll();
  }

  var toolsPage = document.querySelector('[data-tools-page]');
  if (toolsPage) {
    var toolsTree = toolsPage.querySelector('[data-tools-tree]');
    var toolsSource = toolsPage.querySelector('[data-tools-source]');
    var toolsStatus = toolsPage.querySelector('[data-tools-status]');
    var toolsMenu = document.querySelector('[data-tools-menu]');
    var browseUrl = toolsPage.getAttribute('data-tools-browse-url') || '';
    var renameUrl = toolsPage.getAttribute('data-tools-rename-url') || '';
    var smartPlanUrl = toolsPage.getAttribute('data-tools-smart-plan-url') || '';
    var smartRunUrl = toolsPage.getAttribute('data-tools-smart-run-url') || '';
    var menuTarget = null;
    var smartPlans = [];
    var menuTimer = 0;
    var menuFromPress = false;

    function toolsJoin(parent, name) {
      return parent ? parent + '/' + name : name;
    }

    function toolsSetStatus(text) {
      if (toolsStatus) toolsStatus.textContent = text || '';
    }

    function toolsCloseMenu() {
      if (!toolsMenu || toolsMenu.hidden) return;
      toolsMenu.hidden = true;
    }

    function toolsApplyMenu(folder) {
      var hasMedia = folder.getAttribute('data-tools-has-media') === '1';
      var hasFolders = folder.getAttribute('data-tools-has-folders') === '1';
      var renameBtn = document.querySelector('[data-tools-rename]');
      var smartBtn = document.querySelector('[data-tools-smart-rename]');
      if (renameBtn) renameBtn.hidden = !hasMedia;
      if (smartBtn) smartBtn.hidden = !hasFolders || hasMedia;
      return (renameBtn && !renameBtn.hidden) || (smartBtn && !smartBtn.hidden);
    }

    function toolsSetFlags(li, data) {
      if (!li || !data) return;
      li.setAttribute('data-tools-has-media', data.has_media ? '1' : '0');
      li.setAttribute('data-tools-has-folders', data.has_folders ? '1' : '0');
    }

    function toolsOpenMenu(x, y, folder) {
      if (!toolsMenu || !folder) return;
      if (!toolsApplyMenu(folder)) return;
      menuTarget = folder;
      toolsMenu.hidden = false;
      var pad = 8;
      var w = toolsMenu.offsetWidth;
      var h = toolsMenu.offsetHeight;
      var left = Math.min(Math.max(pad, x), window.innerWidth - w - pad);
      var top = Math.min(Math.max(pad, y), window.innerHeight - h - pad);
      toolsMenu.style.left = left + 'px';
      toolsMenu.style.top = top + 'px';
    }

    function toolsFolderIcon() {
      return '<svg class="tools-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M3.5 7.5h6l1.5 2H20.5v9.5H3.5z"/><path fill="none" stroke="currentColor" stroke-width="1.8" d="M3.5 9.5V6.8h5.2l1.2 1.7"/></svg>';
    }

    function toolsFileIcon() {
      return '<svg class="tools-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M7 3.5h7l5 5V20.5H7z"/><path fill="none" stroke="currentColor" stroke-width="1.8" d="M14 3.5V9h5.5"/></svg>';
    }

    function toolsRenderList(ul, dir, data) {
      ul.innerHTML = '';
      (data.folders || []).forEach(function (folder) {
        var rel = toolsJoin(dir, folder.name);
        var li = document.createElement('li');
        li.setAttribute('data-tools-folder', rel);
        li.setAttribute('data-tools-name', folder.name);
        li.setAttribute('data-tools-has-media', folder.has_media ? '1' : '0');
        li.setAttribute('data-tools-has-folders', folder.has_folders ? '1' : '0');
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'tools-row is-folder';
        row.setAttribute('data-tools-expand', '');
        row.setAttribute('aria-expanded', 'false');
        row.innerHTML = '<span class="expand-caret" aria-hidden="true"></span>' + toolsFolderIcon()
          + '<span class="tools-name"></span>';
        row.querySelector('.tools-name').textContent = folder.name;
        var kids = document.createElement('ul');
        kids.hidden = true;
        li.appendChild(row);
        li.appendChild(kids);
        ul.appendChild(li);
      });
      (data.files || []).forEach(function (file) {
        var li = document.createElement('li');
        var row = document.createElement('div');
        row.className = 'tools-row is-file';
        row.innerHTML = '<span class="tools-caret-spacer" aria-hidden="true"></span>' + toolsFileIcon()
          + '<span class="tools-name"></span>';
        row.querySelector('.tools-name').textContent = file.name;
        li.appendChild(row);
        ul.appendChild(li);
      });
      if (!data.folders.length && !data.files.length) {
        var empty = document.createElement('li');
        empty.className = 'tools-row is-file';
        empty.textContent = 'Empty folder';
        ul.appendChild(empty);
      }
    }

    function toolsLoad(dir, ul, done) {
      if (!browseUrl || !toolsSource) return;
      var src = toolsSource.value;
      var url = browseUrl + (browseUrl.indexOf('?') === -1 ? '?' : '&')
        + 'src=' + encodeURIComponent(src) + '&dir=' + encodeURIComponent(dir || '');
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            toolsSetStatus((data && data.error) || 'Could not read that folder.');
            if (done) done(null);
            return;
          }
          toolsRenderList(ul, dir || '', data);
          if (ul) {
            ul.dataset.loaded = '1';
            ul.dataset.extensions = JSON.stringify(data.extensions || []);
            ul.dataset.files = JSON.stringify((data.files || []).map(function (f) { return f.name; }));
            if (ul.parentElement) toolsSetFlags(ul.parentElement, data);
          }
          if (done) done(data);
        })
        .catch(function () {
          toolsSetStatus('Could not read that folder.');
          if (done) done(null);
        });
    }

    function toolsKids(li) {
      if (!li) return null;
      for (var i = 0; i < li.children.length; i++) {
        if (li.children[i].tagName === 'UL') return li.children[i];
      }
      return null;
    }

    function toolsLoadRoot() {
      if (!toolsTree || !toolsSource || !toolsSource.value) {
        if (toolsTree) toolsTree.innerHTML = '';
        return;
      }
      if (toolsSource.selectedOptions[0] && toolsSource.selectedOptions[0].disabled) {
        toolsTree.innerHTML = '';
        return;
      }
      toolsTree.innerHTML = '';
      var li = document.createElement('li');
      li.setAttribute('data-tools-folder', '');
      li.setAttribute('data-tools-name', toolsSource.options[toolsSource.selectedIndex].text);
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'tools-row is-folder';
      row.setAttribute('data-tools-expand', '');
      row.setAttribute('aria-expanded', 'true');
      row.innerHTML = '<span class="expand-caret" aria-hidden="true"></span>' + toolsFolderIcon()
        + '<span class="tools-name"></span>';
      row.querySelector('.tools-name').textContent = toolsSource.options[toolsSource.selectedIndex].text;
      var kids = document.createElement('ul');
      li.appendChild(row);
      li.appendChild(kids);
      toolsTree.appendChild(li);
      toolsLoad('', kids, function (data) {
        toolsSetFlags(li, data);
        if (data) toolsSetStatus('Right-click a folder of files for Rename Group, or a folder of folders for Smart Rename.');
      });
    }

    if (toolsSource) {
      toolsSource.addEventListener('change', function () {
        toolsCloseMenu();
        toolsLoadRoot();
      });
      toolsLoadRoot();
    }

    if (toolsTree) {
      toolsTree.addEventListener('click', function (event) {
        var exp = event.target.closest('[data-tools-expand]');
        if (!exp || menuFromPress) {
          menuFromPress = false;
          return;
        }
        var li = exp.closest('[data-tools-folder]');
        if (!li) return;
        var kids = toolsKids(li);
        if (!kids) return;
        var open = exp.getAttribute('aria-expanded') === 'true';
        if (open) {
          exp.setAttribute('aria-expanded', 'false');
          kids.hidden = true;
          return;
        }
        exp.setAttribute('aria-expanded', 'true');
        kids.hidden = false;
        if (kids.dataset.loaded !== '1') {
          toolsLoad(li.getAttribute('data-tools-folder') || '', kids);
        }
      });

      function folderFromEvent(event) {
        var row = event.target.closest('.tools-row.is-folder');
        if (!row) return null;
        return row.closest('[data-tools-folder]');
      }

      toolsTree.addEventListener('contextmenu', function (event) {
        var folder = folderFromEvent(event);
        if (!folder) return;
        event.preventDefault();
        toolsOpenMenu(event.clientX, event.clientY, folder);
      });
      toolsTree.addEventListener('touchstart', function (event) {
        var folder = folderFromEvent(event);
        if (!folder || !event.touches || !event.touches[0]) return;
        var touch = event.touches[0];
        menuFromPress = false;
        menuTimer = window.setTimeout(function () {
          menuFromPress = true;
          toolsOpenMenu(touch.clientX, touch.clientY, folder);
        }, 520);
      }, { passive: true });
      toolsTree.addEventListener('touchend', function (event) {
        window.clearTimeout(menuTimer);
        if (menuFromPress) event.preventDefault();
      });
      toolsTree.addEventListener('touchmove', function () {
        window.clearTimeout(menuTimer);
      }, { passive: true });
      toolsTree.addEventListener('touchcancel', function () {
        window.clearTimeout(menuTimer);
      });
    }

    document.addEventListener('click', function (event) {
      if (!toolsMenu || toolsMenu.hidden) return;
      if (toolsMenu.contains(event.target)) return;
      toolsCloseMenu();
    });

    function toolsSuggestFind(names) {
      if (!names || !names.length) return '';
      var prefix = String(names[0] || '');
      for (var i = 1; i < names.length; i++) {
        var s = String(names[i] || '');
        var n = Math.min(prefix.length, s.length);
        var j = 0;
        while (j < n && prefix.charAt(j) === s.charAt(j)) j += 1;
        prefix = prefix.slice(0, j);
        if (!prefix) return '';
      }
      return prefix;
    }

    function toolsApplyFindSuggest(findEl, names) {
      if (!findEl) return;
      var suggest = toolsSuggestFind(names);
      findEl.setAttribute('data-rename-suggest', suggest);
      findEl.placeholder = suggest || 'Text shared by the filenames';
      findEl.value = suggest;
    }

    function toolsFillExts(exts) {
      var wrap = document.querySelector('[data-rename-exts]');
      if (!wrap) return;
      wrap.innerHTML = '';
      if (!exts || !exts.length) {
        wrap.innerHTML = '<p class="hint">No files with extensions in this folder.</p>';
        return;
      }
      exts.forEach(function (ext) {
        var safe = String(ext).replace(/[^a-z0-9]+/gi, '');
        if (!safe) return;
        var label = document.createElement('label');
        label.className = 'grok-toggle';
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = true;
        box.setAttribute('data-rename-ext', safe);
        var span = document.createElement('span');
        span.textContent = '.' + safe;
        label.appendChild(box);
        label.appendChild(span);
        wrap.appendChild(label);
      });
    }

    var renameBtn = document.querySelector('[data-tools-rename]');
    if (renameBtn) {
      renameBtn.addEventListener('click', function (event) {
        event.preventDefault();
        toolsCloseMenu();
        if (!menuTarget) return;
        var dir = menuTarget.getAttribute('data-tools-folder') || '';
        var name = menuTarget.getAttribute('data-tools-name') || dir || 'this folder';
        var kids = toolsKids(menuTarget);
        var exts = [];
        try {
          exts = JSON.parse((kids && kids.dataset.extensions) || '[]');
        } catch (e) {}
        var folderHint = document.querySelector('[data-rename-folder]');
        if (folderHint) folderHint.textContent = name;
        var find = document.querySelector('[data-rename-find]');
        var replace = document.querySelector('[data-rename-replace]');
        if (replace) replace.value = '';
        var err = document.querySelector('[data-rename-error]');
        var ok = document.querySelector('[data-rename-ok]');
        var log = document.querySelector('[data-rename-log]');
        if (err) { err.hidden = true; err.textContent = ''; }
        if (ok) { ok.hidden = true; ok.textContent = ''; }
        if (log) { log.hidden = true; log.innerHTML = ''; }
        var fill = function (list, files) {
          toolsFillExts(list || []);
          var names = [];
          if (files && files.length) {
            names = files.map(function (f) { return typeof f === 'string' ? f : f.name; });
          } else if (kids) {
            try { names = JSON.parse(kids.dataset.files || '[]'); } catch (e) {}
          }
          toolsApplyFindSuggest(find, names);
          overlayOpen('rename-group');
          if (find) find.focus();
        };
        if (kids && kids.dataset.loaded === '1') {
          fill(exts);
          return;
        }
        toolsLoad(dir, kids, function (data) {
          if (kids) {
            kids.hidden = false;
            var exp = menuTarget.querySelector('[data-tools-expand]');
            if (exp) exp.setAttribute('aria-expanded', 'true');
          }
          fill(data ? data.extensions : [], data ? data.files : []);
        });
      });
    }

    var renameRun = document.querySelector('[data-rename-run]');
    if (renameRun) {
      renameRun.addEventListener('click', function () {
        if (!renameUrl || !toolsSource || !menuTarget) return;
        var findEl = document.querySelector('[data-rename-find]');
        var replaceEl = document.querySelector('[data-rename-replace]');
        var err = document.querySelector('[data-rename-error]');
        var ok = document.querySelector('[data-rename-ok]');
        var log = document.querySelector('[data-rename-log]');
        var find = findEl ? findEl.value : '';
        if (!find && findEl) {
          find = (findEl.getAttribute('data-rename-suggest') || findEl.placeholder || '').trim();
        }
        if (!find || find === 'Text shared by the filenames') {
          if (err) { err.hidden = false; err.textContent = 'Enter the text to find in filenames.'; }
          return;
        }
        var exts = [];
        document.querySelectorAll('[data-rename-ext]').forEach(function (box) {
          if (box.checked) exts.push(box.getAttribute('data-rename-ext'));
        });
        if (!exts.length) {
          if (err) { err.hidden = false; err.textContent = 'Choose at least one file type.'; }
          return;
        }
        renameRun.disabled = true;
        fetch(renameUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            src: toolsSource.value,
            dir: menuTarget.getAttribute('data-tools-folder') || '',
            find: find,
            replace: replaceEl ? replaceEl.value : '',
            extensions: exts
          })
        }).then(function (r) { return r.json(); }).then(function (data) {
          renameRun.disabled = false;
          if (!data || !data.ok) {
            if (err) { err.hidden = false; err.textContent = (data && data.error) || 'Rename failed.'; }
            if (ok) ok.hidden = true;
            return;
          }
          if (err) { err.hidden = true; err.textContent = ''; }
          if (ok) {
            ok.hidden = false;
            ok.textContent = 'Renamed ' + (data.renamed || 0)
              + (data.catalog ? ', catalog updated ' + data.catalog : '')
              + (data.skipped ? ', skipped ' + data.skipped : '')
              + (data.errors ? ', errors ' + data.errors : '') + '.';
          }
          if (log) {
            log.innerHTML = '';
            (data.changes || []).forEach(function (row) {
              var li = document.createElement('li');
              if (!row.ok) li.className = 'is-error';
              li.textContent = row.from + ' → ' + row.to + (row.ok ? '' : ' (' + (row.error || 'failed') + ')');
              log.appendChild(li);
            });
            log.hidden = !(data.changes && data.changes.length);
          }
          var kids = toolsKids(menuTarget);
          if (kids) toolsLoad(menuTarget.getAttribute('data-tools-folder') || '', kids);
        }).catch(function () {
          renameRun.disabled = false;
          if (err) { err.hidden = false; err.textContent = 'Rename failed.'; }
        });
      });
    }

    function smartPlanLine(plan) {
      var label = plan.label || 'Folder';
      if (plan.skip) {
        return label + ': no change';
      }
      return label + ':  \'' + (plan.sample || '') + '\' -> \'' + (plan.preview || '') + '\'';
    }

    var smartRenameBtn = document.querySelector('[data-tools-smart-rename]');
    var smartPlanRun = document.querySelector('[data-smart-plan-run]');
    if (smartRenameBtn) {
      smartRenameBtn.addEventListener('click', function (event) {
        event.preventDefault();
        toolsCloseMenu();
        if (!menuTarget || !smartPlanUrl || !toolsSource) return;
        smartPlans = [];
        var status = document.querySelector('[data-smart-plan-status]');
        var err = document.querySelector('[data-smart-plan-error]');
        var list = document.querySelector('[data-smart-plan-list]');
        var actions = document.querySelector('[data-smart-plan-actions]');
        if (status) status.textContent = 'Asking Grok to plan renames…';
        if (err) { err.hidden = true; err.textContent = ''; }
        if (list) { list.hidden = true; list.innerHTML = ''; }
        if (actions) actions.hidden = true;
        if (smartPlanRun) smartPlanRun.disabled = true;
        overlayOpen('smart-rename-plan');
        fetch(smartPlanUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            src: toolsSource.value,
            dir: menuTarget.getAttribute('data-tools-folder') || ''
          })
        }).then(function (r) { return r.json(); }).then(function (data) {
          if (!data || !data.ok) {
            if (status) status.textContent = '';
            if (err) {
              err.hidden = false;
              err.textContent = (data && data.error) || 'Could not plan Smart Rename.';
            }
            return;
          }
          smartPlans = data.plans || [];
          if (status) {
            status.textContent = smartPlans.length
              ? 'Review the planned renames, then tap Rename.'
              : 'Grok did not return any plans.';
          }
          if (list) {
            list.innerHTML = '';
            smartPlans.forEach(function (plan) {
              var li = document.createElement('li');
              if (plan.skip) li.className = 'is-skip';
              li.textContent = smartPlanLine(plan);
              list.appendChild(li);
            });
            list.hidden = smartPlans.length === 0;
          }
          var runnable = smartPlans.some(function (plan) { return plan.find && !plan.skip; });
          if (actions) actions.hidden = !runnable;
          if (smartPlanRun) smartPlanRun.disabled = !runnable;
        }).catch(function () {
          if (status) status.textContent = '';
          if (err) { err.hidden = false; err.textContent = 'Could not plan Smart Rename.'; }
        });
      });
    }

    if (smartPlanRun) {
      smartPlanRun.addEventListener('click', function () {
        if (!smartRunUrl || !toolsSource) return;
        var runnable = smartPlans.filter(function (plan) { return plan.find && !plan.skip; });
        if (!runnable.length) return;
        smartPlanRun.disabled = true;
        fetch(smartRunUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            src: toolsSource.value,
            plans: runnable
          })
        }).then(function (r) { return r.json(); }).then(function (data) {
          smartPlanRun.disabled = false;
          overlayClose(document.querySelector('[data-overlay="smart-rename-plan"]'));
          var summary = document.querySelector('[data-smart-result-summary]');
          var list = document.querySelector('[data-smart-result-list]');
          if (!data || !data.ok) {
            if (summary) {
              summary.className = 'form-banner is-error';
              summary.textContent = (data && data.error) || 'Smart Rename failed.';
            }
            if (list) list.innerHTML = '';
            overlayOpen('smart-rename-result');
            return;
          }
          if (summary) {
            summary.className = 'form-banner is-ok';
            summary.textContent = 'Renamed ' + (data.renamed || 0)
              + (data.catalog ? ', catalog updated ' + data.catalog : '')
              + (data.skipped ? ', skipped ' + data.skipped : '')
              + (data.errors ? ', errors ' + data.errors : '') + '.';
          }
          if (list) {
            list.innerHTML = '';
            (data.folders || []).forEach(function (folder) {
              var head = document.createElement('li');
              head.textContent = (folder.label || 'Folder') + ': '
                + (folder.renamed || 0) + ' renamed'
                + (folder.skipped ? ', ' + folder.skipped + ' skipped' : '')
                + (folder.errors ? ', ' + folder.errors + ' errors' : '');
              list.appendChild(head);
              (folder.changes || []).forEach(function (row) {
                var li = document.createElement('li');
                if (!row.ok) li.className = 'is-skip';
                li.textContent = row.from + ' → ' + row.to + (row.ok ? '' : ' (' + (row.error || 'failed') + ')');
                list.appendChild(li);
              });
            });
          }
          overlayOpen('smart-rename-result');
          if (menuTarget) {
            var kids = toolsKids(menuTarget);
            if (kids) toolsLoad(menuTarget.getAttribute('data-tools-folder') || '', kids);
          }
        }).catch(function () {
          smartPlanRun.disabled = false;
          overlayClose(document.querySelector('[data-overlay="smart-rename-plan"]'));
          var summary = document.querySelector('[data-smart-result-summary]');
          if (summary) {
            summary.className = 'form-banner is-error';
            summary.textContent = 'Smart Rename failed.';
          }
          overlayOpen('smart-rename-result');
        });
      });
    }
  }
})();
