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

  var catalog = document.querySelector('[data-catalog]');
  if (catalog) {
    function catalogSave() {
      var raw = JSON.stringify({
        view: catalog.getAttribute('data-view') || 'poster',
        columns: (catalog.getAttribute('data-cols') || 'title').trim().split(/\s+/),
        columns_known: Array.prototype.map.call(catalog.querySelectorAll('[data-col-id]'), function (box) {
          return box.getAttribute('data-col-id');
        }).filter(Boolean),
        sort: catalog.getAttribute('data-sort') || 'title',
        dir: catalog.getAttribute('data-dir') || 'asc',
        kinds: (catalog.getAttribute('data-kinds') || 'movie show').trim().split(/\s+/),
        categories: (catalog.getAttribute('data-categories') || '').trim().split(/\s+/).filter(Boolean),
        categories_known: Array.prototype.map.call(catalog.querySelectorAll('[data-category-filter]'), function (box) {
          return box.getAttribute('data-category-filter');
        }).filter(Boolean)
      });
      try { localStorage.setItem('media_catalog', raw); } catch (e) {}
      document.cookie = 'media_catalog=' + encodeURIComponent(raw) + ';path=/;max-age=31536000;SameSite=Lax';
    }

    function setView(view) {
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
        cards.sort(bySort).forEach(function (card) { grid.appendChild(card); });
      }
    }

    function kindAllowed(el) {
      return true;
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

    function setExpanded(id, open) {
      var btn = catalog.querySelector('[data-expand="' + id + '"]');
      if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      catalog.querySelectorAll('[data-parent="' + String(id).replace(/"/g, '\\"') + '"]').forEach(function (row) {
        if (!open) {
          row.hidden = true;
          var nested = row.getAttribute('data-group');
          if (nested) setExpanded(nested, false);
          return;
        }
        row.hidden = !rowAllowed(row);
      });
    }

    var searchCommitted = '';

    function applySearch() {
      var input = document.querySelector('[data-search-input]');
      var query = isDesktopShell()
        ? (input ? input.value.trim().toLowerCase() : '')
        : searchCommitted;
      var visible = 0;
      var view = catalog.getAttribute('data-view') || 'poster';

      catalog.querySelectorAll('[data-group]').forEach(function (head) {
        var id = head.getAttribute('data-group');
        var kids = catalog.querySelectorAll('[data-parent="' + id + '"]');
        var headHit = !query || (head.getAttribute('data-search') || '').toLowerCase().indexOf(query) !== -1;
        var kidHit = false;
        kids.forEach(function (kid) {
          var hit = !query || (kid.getAttribute('data-search') || '').toLowerCase().indexOf(query) !== -1;
          if (hit) kidHit = true;
          kid.hidden = !(hit && rowAllowed(kid));
        });
        var show = rowAllowed(head) && (headHit || kidHit);
        head.hidden = !show;
        if (show && query && kidHit) setExpanded(id, true);
        else if (show && !query) {
          var open = (head.querySelector('[data-expand]') || {}).getAttribute &&
            (head.querySelector('[data-expand]').getAttribute('aria-expanded') === 'true');
          setExpanded(id, !!open);
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
      var empty = document.querySelector('[data-search-empty]');
      if (empty) empty.hidden = !((query || (catalog.getAttribute('data-kinds') || '') === '') && visible === 0);
    }

    catalog.querySelectorAll('[data-catalog-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setView(btn.getAttribute('data-catalog-view') || 'poster');
      });
    });

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
          catalog.setAttribute('data-kinds', 'movie show');
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
            catalogSave();
            window.location.reload();
            return;
          }
        }
      }
    } catch (e) {}
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

  function scanStateLabel(state, data) {
    if (scanIsStopping(data) && state === 'running') return 'Stopping';
    if (state === 'running') return 'In progress';
    if (state === 'done') return 'Done';
    if (state === 'stopped') return 'Stopped';
    if (state === 'error') return 'Failed';
    return 'Ready';
  }

  function scanTitleLabel(state, data) {
    if (scanIsStopping(data) && state === 'running') return 'Stopping';
    if (state === 'running') return 'Scanning';
    if (state === 'done') return 'Scan finished';
    if (state === 'stopped') return 'Scan stopped';
    if (state === 'error') return 'Scan failed';
    return 'Video scan';
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

  function setLiveActive(active, message) {
    if (scanLive) {
      scanLive.hidden = !active;
      scanLive.setAttribute('aria-label', active ? (message || 'Scan in progress') : 'Scan idle');
    }
    document.body.classList.toggle('scan-running', active);
    var barSpin = document.querySelector('[data-scan-bar-spin]');
    if (barSpin) barSpin.hidden = !active;
    var launchWrap = document.querySelector('[data-scan-launch-wrap]');
    if (launchWrap) launchWrap.hidden = active;
    var grokLaunch = document.querySelector('[data-grok-launch-wrap]');
    if (grokLaunch) grokLaunch.hidden = active;
  }

  function setOverlayKind(kind) {
    var tmdbStats = document.querySelector('[data-overlay-stats="tmdb"]');
    var grokStats = document.querySelector('[data-overlay-stats="grok"]');
    if (tmdbStats) tmdbStats.hidden = kind === 'grok';
    if (grokStats) grokStats.hidden = kind !== 'grok';
    if (kind !== 'grok') {
      document.querySelectorAll('[data-grok-wait-actions]').forEach(function (el) {
        el.classList.remove('is-visible');
      });
    }
    var link = document.querySelector('[data-scan-overlay-link]');
    if (link && scanLive) {
      if (kind === 'grok') {
        link.href = scanLive.getAttribute('data-grok-page-url') || link.href;
        link.textContent = 'Open Grok page';
      } else {
        link.href = scanLive.getAttribute('data-scan-page-url') || link.href;
        link.textContent = 'Open scan page';
      }
    }
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

    lastScanKind = 'tmdb';
    setLiveActive(active, message || (stopping ? 'Stopping scan' : 'Scan in progress'));
    setOverlayKind('tmdb');

    setText('[data-scan-kicker]', scanStateLabel(state, data));
    setText('[data-scan-title]', scanTitleLabel(state, data));
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
      } else if (pending > 0) {
        if (phase === 'lookup' || phase === 'stopping') {
          var resolved = Math.min(pending, found + unmatched);
          pct = Math.min(100, Math.round((resolved / pending) * 100));
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
        stopBtn.textContent = 'Stop scan';
        scanStopRequested = false;
      } else if (stopping || scanStopRequested) {
        stopBtn.hidden = false;
        stopBtn.disabled = true;
        stopBtn.textContent = 'Stopping…';
      } else {
        stopBtn.hidden = false;
        stopBtn.disabled = false;
        stopBtn.textContent = 'Stop scan';
      }
    }

    setText('[data-scan-overlay-kicker]', scanStateLabel(state, data));
    setText('[data-scan-overlay-title]', stopping ? 'Stopping scan' : (running ? 'Scan in progress' : scanTitleLabel(state, data)));
    setText('[data-scan-overlay-message]', message || (running ? 'Working…' : 'No scan is running.'));
    setText('[data-scan-overlay-found]', String(found));
    setText('[data-scan-overlay-pending]', String(pending));
    setText('[data-scan-overlay-lookups]', String(lookups));
    setText('[data-scan-overlay-unmatched]', String(unmatched));
    setText('[data-scan-overlay-unidentified]', String(unidentified));
    setText('[data-scan-overlay-files]', total > 0 ? (processed + ' / ' + total) : (processed > 0 ? String(processed) : '—'));
    setText('[data-scan-overlay-elapsed]', running || state === 'done' || state === 'stopped'
      ? elapsedLabel(data.started_at)
      : '—');

    lastScanState = state;
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
    setOverlayKind('grok');

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
    var showWait = running && !stopping && !grokStopRequested;
    document.querySelectorAll('[data-grok-wait-actions]').forEach(function (el) {
      el.classList.toggle('is-visible', showWait);
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

  function pumpScan(start) {
    var runUrl = scanRunUrl();
    if (!runUrl || scanPumping) return;
    if (start) {
      var mode = typeof start === 'string' ? start : ((scanPanel && scanPanel.getAttribute('data-scan-start')) || 'retry');
      if (mode === '1' || mode === 'true') mode = 'retry';
      if (mode !== 'unidentified') mode = 'retry';
      runUrl += (runUrl.indexOf('?') >= 0 ? '&' : '?') + 'start=1&mode=' + encodeURIComponent(mode);
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
        paintLive(data);
        var state = data && data.state;
        var kind = data && data.kind;
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
            if (lastScanState === 'running' && lastScanKind === 'tmdb') pumpScan();
          }, 400);
        }
      })
      .catch(function () {
        scanPumping = false;
        setTimeout(function () {
          if (lastScanState === 'running' && lastScanKind === 'tmdb') pumpScan();
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
        if (grokStopRequested || (data && data.cancel_requested) || state === 'stopped') {
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
        if ((data.kind || 'tmdb') === 'grok') {
          if (data.paused) return;
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
      if (activityBusy() && lastScanKind === 'grok') {
        overlayOpen('scan');
        return;
      }
      var mode = btn.getAttribute('data-scan-launch') || 'retry';
      if (scanPanel) scanPanel.setAttribute('data-scan-start', mode);
      lastScanKind = 'tmdb';
      lastScanState = 'running';
      setLiveActive(true, 'Starting TMDB scan…');
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
      if (grokStopRequested) return;
      pumpGrok(false);
    });
  });

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
      setText('[data-scan-kicker]', 'Stopping');
      setText('[data-scan-title]', 'Stopping');
      setText('[data-scan-message]', 'Stop requested. Finishing the current step…');
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
})();
