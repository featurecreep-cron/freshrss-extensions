'use strict';

(function () {
  var feedId = 0;
  var filters = [];       // array of {type, value, action, search}
  var filterMap = {};      // { authors: {name: action}, tags: {tag: action}, keywords: {kw: action} }
  var showTags = 'n';
  var firstRun = false;
  var pendingRequest = Promise.resolve();

  // ========== Init ==========

  var _initRetries = 0;
  function init() {
    if (typeof context === 'undefined') {
      if (++_initRetries > 100) return;
      return setTimeout(init, 50);
    }

    var cfg = context.extensions && context.extensions['QuickFilter'];
    if (!cfg) return;

    feedId = cfg.feedId || 0;
    filters = cfg.filters || [];
    showTags = cfg.showTags || 'n';
    firstRun = cfg.firstRun || false;

    if (!isArticleView()) return;

    buildFilterMap();
    applyInlineControls();
    observeNewArticles();

    if (firstRun) {
      showOnboardingBanner();
    }

    addFilterManagerButton();
  }

  function isArticleView() {
    return !!document.getElementById('stream');
  }

  // ========== Filter Map ==========

  function buildFilterMap() {
    filterMap = { authors: {}, tags: {}, keywords: {} };
    filters.forEach(function (f) {
      if (f.type === 'author') {
        filterMap.authors[f.value.toLowerCase()] = { action: f.action, search: f.search };
      } else if (f.type === 'tag') {
        filterMap.tags[f.value.toLowerCase()] = { action: f.action, search: f.search };
      } else if (f.type === 'keyword') {
        filterMap.keywords[f.value.toLowerCase()] = { action: f.action, search: f.search };
      }
    });
  }

  // ========== AJAX ==========

  function getCsrf() {
    var input = document.querySelector('input[name="_csrf"]');
    return input ? input.value : '';
  }

  function apiCall(action, params) {
    var body = new URLSearchParams();
    body.append('_csrf', getCsrf());
    for (var key in params) {
      body.append(key, params[key]);
    }
    return fetch('./?c=quickfilter&a=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin',
      redirect: 'manual',
    }).then(function (r) {
      if (!r.ok) return r.json().then(function (d) { return Promise.reject(d); });
      return r.json();
    });
  }

  function apiGet(action, params) {
    var qs = new URLSearchParams(params);
    return fetch('./?c=quickfilter&a=' + action + '&' + qs.toString(), {
      credentials: 'same-origin',
    }).then(function (r) {
      if (!r.ok) return r.json().then(function (d) { return Promise.reject(d); });
      return r.json();
    });
  }

  // Serialize write operations to prevent race conditions
  function serializedApiCall(action, params) {
    pendingRequest = pendingRequest.then(function () {
      return apiCall(action, params);
    }, function () {
      // Previous call failed — still execute this one (don't break chain)
      return apiCall(action, params);
    });
    return pendingRequest;
  }

  // ========== Inline Controls ==========

  function applyInlineControls() {
    var articles = document.querySelectorAll('.flux');
    articles.forEach(function (article) {
      processArticle(article);
    });
  }

  function processArticle(article) {
    if (article.dataset.qfProcessed) return;
    article.dataset.qfProcessed = '1';

    // Author controls — attach to all .author elements
    var authorEls = article.querySelectorAll('.author');
    authorEls.forEach(function (el) {
      var authorName = extractAuthorName(el);
      if (!authorName) return;
      wrapAuthorWithControls(el, authorName);
    });

    // Tag controls (only if tags are displayed)
    if (showTags !== 'n') {
      var tagEls = article.querySelectorAll('.link-tag');
      tagEls.forEach(function (el) {
        var tagText = el.textContent.trim().replace(/^#/, '');
        if (!tagText) return;
        wrapTagWithControls(el, tagText);
      });
    }

    // Title keyword highlighting
    highlightTitleKeywords(article);
  }

  /**
   * Extract the actual author name from an .author element.
   * Handles "By: Author Name" prefix and link-wrapped names.
   */
  function extractAuthorName(el) {
    // If the author element contains a link, use the link text
    var link = el.querySelector('a');
    if (link) {
      return link.textContent.trim();
    }
    // Otherwise use the element's own text, stripping "By:" prefix
    var text = el.textContent.trim();
    text = text.replace(/^By:\s*/i, '');
    return text || '';
  }

  function wrapAuthorWithControls(el, authorName) {
    if (el.querySelector('.qf-controls')) return;

    var key = authorName.toLowerCase();
    var existing = filterMap.authors[key];

    var controls = createFilterControls(authorName, 'author', existing);
    el.appendChild(controls);

    // Apply color to author name
    if (existing) {
      el.classList.add(existing.action === 'star' ? 'qf-active-positive' : 'qf-active-negative');
    }
  }

  function wrapTagWithControls(el, tagName) {
    var parent = el.parentElement;
    if (!parent || parent.querySelector('.qf-controls')) return;

    var key = tagName.toLowerCase();
    var existing = filterMap.tags[key];

    var controls = createFilterControls(tagName, 'tag', existing);
    parent.appendChild(controls);

    if (existing) {
      el.classList.add(existing.action === 'star' ? 'qf-active-positive' : 'qf-active-negative');
    }
  }

  function createFilterControls(value, type, existing) {
    var container = document.createElement('span');
    container.className = 'qf-controls';
    if (!firstRun && !existing) {
      container.classList.add('qf-hover-only');
    }

    var starBtn = document.createElement('button');
    starBtn.type = 'button';
    starBtn.className = 'qf-btn qf-star';
    starBtn.title = 'Star articles with ' + type + ' "' + value + '"';
    starBtn.innerHTML = '&#9734;'; // ☆
    starBtn.setAttribute('aria-label', 'Star articles with ' + type + ' ' + value);

    var hideBtn = document.createElement('button');
    hideBtn.type = 'button';
    hideBtn.className = 'qf-btn qf-hide';
    hideBtn.title = 'Auto-read articles with ' + type + ' "' + value + '"';
    hideBtn.innerHTML = '&#10003;'; // ✕
    hideBtn.setAttribute('aria-label', 'Auto-read articles with ' + type + ' ' + value);

    if (existing) {
      if (existing.action === 'star') {
        starBtn.classList.add('qf-active');
        starBtn.innerHTML = '&#9733;'; // ★
      } else {
        hideBtn.classList.add('qf-active');
      }
    }

    starBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      toggleFilter(type, value, 'star', starBtn, hideBtn);
    });

    hideBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      toggleFilter(type, value, 'read', hideBtn, starBtn);
    });

    container.appendChild(starBtn);
    container.appendChild(hideBtn);
    return container;
  }

  function toggleFilter(type, value, action, activeBtn, otherBtn) {
    if (feedId <= 0) {
      showNotification('Select a single feed to use QuickFilter', true);
      return;
    }

    // Disable buttons during operation to prevent duplicate clicks
    activeBtn.disabled = true;
    otherBtn.disabled = true;

    var key = value.toLowerCase();
    var mapObj = type === 'author' ? filterMap.authors :
                 type === 'tag' ? filterMap.tags : filterMap.keywords;
    var existing = mapObj[key];

    if (existing && existing.action === action) {
      // Remove filter (toggle off)
      activeBtn.classList.remove('qf-active');
      if (action === 'star') activeBtn.innerHTML = '&#9734;';
      delete mapObj[key];

      serializedApiCall('remove', {
        feedId: feedId,
        type: type,
        value: value,
        action: action,
      }).then(function (data) {
        if (data.filters) updateFiltersFromServer(data.filters);
        showNotification('Filter removed');
      }).catch(function (err) {
        // Rollback
        mapObj[key] = existing;
        activeBtn.classList.add('qf-active');
        if (action === 'star') activeBtn.innerHTML = '&#9733;';
        showNotification(err.error || 'Failed to remove filter', true);
      }).then(function () {
        activeBtn.disabled = false;
        otherBtn.disabled = false;
      });
    } else {
      // Remove opposite filter if exists
      if (existing && existing.action !== action) {
        otherBtn.classList.remove('qf-active');
        if (existing.action === 'star') otherBtn.innerHTML = '&#9734;';
      }

      // Add filter (optimistic)
      activeBtn.classList.add('qf-active');
      if (action === 'star') activeBtn.innerHTML = '&#9733;';

      // If replacing opposite action, remove old first
      var chain = Promise.resolve();
      if (existing && existing.action !== action) {
        chain = serializedApiCall('remove', {
          feedId: feedId,
          type: type,
          value: value,
          action: existing.action,
        });
      }

      chain.then(function () {
        return serializedApiCall('add', {
          feedId: feedId,
          type: type,
          value: value,
          action: action,
        });
      }).then(function (data) {
        if (data.filters) updateFiltersFromServer(data.filters);
        showNotification(action === 'star' ? 'Starring ' + type + ': ' + value : 'Auto-reading ' + type + ': ' + value);
      }).catch(function (err) {
        // Rollback
        activeBtn.classList.remove('qf-active');
        if (action === 'star') activeBtn.innerHTML = '&#9734;';
        showNotification(err.error || 'Failed to add filter', true);
      }).then(function () {
        activeBtn.disabled = false;
        otherBtn.disabled = false;
      });

      mapObj[key] = { action: action, search: '' }; // search filled on server response
    }

    // Update visual classes on all matching elements
    updateVisualClasses(type, key);
  }

  function updateFiltersFromServer(serverFilters) {
    filters = serverFilters;
    buildFilterMap();
  }

  function updateVisualClasses(type, key) {
    var selector = type === 'author' ? '.author' : '.link-tag';
    document.querySelectorAll(selector).forEach(function (el) {
      var text = type === 'author' ? extractAuthorName(el) : el.textContent.trim().replace(/^#/, '');
      if (text.toLowerCase() !== key) return;

      el.classList.remove('qf-active-positive', 'qf-active-negative');
      var mapObj = type === 'author' ? filterMap.authors : filterMap.tags;
      var f = mapObj[key];
      if (f) {
        el.classList.add(f.action === 'star' ? 'qf-active-positive' : 'qf-active-negative');
      }
    });
  }

  // ========== Title Keyword Highlighting ==========

  function highlightTitleKeywords(article) {
    var keywords = Object.keys(filterMap.keywords);
    if (keywords.length === 0) return;

    var titleEl = article.querySelector('.title .item-element');
    if (!titleEl) return;

    var text = titleEl.textContent;
    var html = titleEl.innerHTML;

    keywords.forEach(function (kw) {
      var info = filterMap.keywords[kw];
      var regex = new RegExp('(' + escapeRegex(kw) + ')', 'gi');
      var cls = info.action === 'star' ? 'qf-highlight-positive' : 'qf-highlight-negative';
      html = html.replace(regex, '<span class="' + cls + '">$1</span>');
    });

    if (html !== titleEl.innerHTML) {
      titleEl.innerHTML = html;
    }
  }

  function escapeRegex(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  // ========== MutationObserver for lazy-loaded articles ==========

  function observeNewArticles() {
    var stream = document.getElementById('stream');
    if (!stream) return;

    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType === 1 && node.classList && node.classList.contains('flux')) {
            processArticle(node);
          }
          // Also check children for batch-added nodes
          if (node.nodeType === 1 && node.querySelectorAll) {
            node.querySelectorAll('.flux').forEach(function (article) {
              processArticle(article);
            });
          }
        });
      });
    });

    observer.observe(stream, { childList: true, subtree: true });
  }

  // ========== Filter Manager Panel ==========

  var panelEl = null;

  function addFilterManagerButton() {
    var navMenu = document.querySelector('.nav_menu');
    if (!navMenu) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'qf-manager-btn';
    btn.title = 'Manage filters';
    btn.innerHTML = '&#9661;'; // ▽ filter
    btn.setAttribute('aria-label', 'Open filter manager');
    btn.addEventListener('click', function () {
      if (feedId <= 0) {
        showNotification('Select a single feed to manage filters', true);
        return;
      }
      openFilterManager();
    });

    navMenu.appendChild(btn);
  }

  function openFilterManager() {
    if (panelEl) {
      closeFilterManager();
      return;
    }

    panelEl = document.createElement('div');
    panelEl.className = 'qf-panel';
    panelEl.setAttribute('role', 'dialog');
    panelEl.setAttribute('aria-modal', 'true');
    panelEl.setAttribute('aria-label', 'Filter Manager');

    var header = document.createElement('div');
    header.className = 'qf-panel-header';

    var title = document.createElement('h3');
    title.textContent = 'Filters';
    header.appendChild(title);

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'qf-panel-close';
    closeBtn.innerHTML = '&times;';
    closeBtn.title = 'Close';
    closeBtn.addEventListener('click', closeFilterManager);
    header.appendChild(closeBtn);

    panelEl.appendChild(header);

    var body = document.createElement('div');
    body.className = 'qf-panel-body';
    panelEl.appendChild(body);

    document.body.appendChild(panelEl);

    // Escape to close
    document.addEventListener('keydown', panelKeyHandler);

    // Populate
    renderFilterList(body);
    renderAddForm(body);
  }

  function closeFilterManager() {
    if (panelEl) {
      panelEl.remove();
      panelEl = null;
    }
    document.removeEventListener('keydown', panelKeyHandler);
  }

  function panelKeyHandler(e) {
    if (e.key === 'Escape') closeFilterManager();
  }

  function renderFilterList(container) {
    var section = document.createElement('div');
    section.className = 'qf-filter-list';

    if (filters.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'qf-empty';
      empty.textContent = 'No filters on this feed.';
      section.appendChild(empty);
    } else {
      var list = document.createElement('ul');
      list.className = 'qf-rules';

      filters.forEach(function (f) {
        var li = document.createElement('li');
        li.className = 'qf-rule';

        var icon = document.createElement('span');
        icon.className = 'qf-rule-icon';
        icon.innerHTML = f.action === 'star' ? '&#9733;' : '&#10003;';
        icon.classList.add(f.action === 'star' ? 'qf-positive' : 'qf-negative');
        li.appendChild(icon);

        var desc = document.createElement('span');
        desc.className = 'qf-rule-desc';
        desc.textContent = f.type + ': ' + f.value;
        li.appendChild(desc);

        var actions = document.createElement('span');
        actions.className = 'qf-rule-actions';

        var runBtn = document.createElement('button');
        runBtn.type = 'button';
        runBtn.className = 'qf-btn-small';
        runBtn.textContent = 'Run on existing';
        runBtn.addEventListener('click', function () {
          openPreview(f.type, f.value, f.action);
        });
        actions.appendChild(runBtn);

        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'qf-btn-small qf-btn-danger';
        delBtn.textContent = 'Delete';
        delBtn.addEventListener('click', function () {
          if (!confirm('Remove filter: ' + f.type + ' "' + f.value + '"?')) return;
          serializedApiCall('remove', {
            feedId: feedId,
            type: f.type,
            value: f.value,
            action: f.action,
          }).then(function (data) {
            if (data.filters) {
              updateFiltersFromServer(data.filters);
              refreshPanel();
              applyInlineControls();
            }
            showNotification('Filter removed');
          }).catch(function (err) {
            showNotification(err.error || 'Failed to remove', true);
          });
        });
        actions.appendChild(delBtn);

        li.appendChild(actions);
        list.appendChild(li);
      });

      section.appendChild(list);
    }

    container.appendChild(section);
  }

  function renderAddForm(container) {
    var form = document.createElement('div');
    form.className = 'qf-add-form';

    var heading = document.createElement('h4');
    heading.textContent = '+ Add filter';
    heading.className = 'qf-add-heading';
    form.appendChild(heading);

    var formFields = document.createElement('div');
    formFields.className = 'qf-form-fields';
    formFields.style.display = 'none';

    heading.style.cursor = 'pointer';
    heading.addEventListener('click', function () {
      var isVisible = formFields.style.display !== 'none';
      formFields.style.display = isVisible ? 'none' : 'block';
      if (!isVisible) loadDropdownData(formFields);
    });

    // Type selector
    var typeRow = createFormRow('Type');
    var typeSelect = document.createElement('select');
    typeSelect.className = 'qf-select';
    ['author', 'tag', 'keyword'].forEach(function (t) {
      var opt = document.createElement('option');
      opt.value = t;
      opt.textContent = t.charAt(0).toUpperCase() + t.slice(1);
      typeSelect.appendChild(opt);
    });
    typeRow.appendChild(typeSelect);
    formFields.appendChild(typeRow);

    // Value input (switches between dropdown and text)
    var valueRow = createFormRow('Value');
    var valueSelect = document.createElement('select');
    valueSelect.className = 'qf-select qf-value-select';
    var valueInput = document.createElement('input');
    valueInput.type = 'text';
    valueInput.className = 'qf-input qf-value-input';
    valueInput.placeholder = 'Enter keyword (min 3 chars)';
    valueInput.style.display = 'none';
    valueRow.appendChild(valueSelect);
    valueRow.appendChild(valueInput);
    formFields.appendChild(valueRow);

    // Action selector
    var actionRow = createFormRow('Action');
    var actionSelect = document.createElement('select');
    actionSelect.className = 'qf-select';
    var starOpt = document.createElement('option');
    starOpt.value = 'star';
    starOpt.textContent = '\u2606 Star';
    var readOpt = document.createElement('option');
    readOpt.value = 'read';
    readOpt.textContent = '\u2715 Mark as read';
    actionSelect.appendChild(starOpt);
    actionSelect.appendChild(readOpt);
    actionRow.appendChild(actionSelect);
    formFields.appendChild(actionRow);

    // Apply to existing checkbox
    var applyRow = document.createElement('div');
    applyRow.className = 'qf-form-row';
    var applyLabel = document.createElement('label');
    var applyCheckbox = document.createElement('input');
    applyCheckbox.type = 'checkbox';
    applyCheckbox.className = 'qf-apply-existing';
    applyLabel.appendChild(applyCheckbox);
    applyLabel.appendChild(document.createTextNode(' Apply to existing articles'));
    var matchCount = document.createElement('span');
    matchCount.className = 'qf-match-count';
    applyLabel.appendChild(matchCount);
    applyRow.appendChild(applyLabel);
    formFields.appendChild(applyRow);

    // Buttons
    var btnRow = document.createElement('div');
    btnRow.className = 'qf-form-row qf-form-actions';

    var previewBtn = document.createElement('button');
    previewBtn.type = 'button';
    previewBtn.className = 'qf-btn-small';
    previewBtn.textContent = 'Preview';
    previewBtn.addEventListener('click', function () {
      var type = typeSelect.value;
      var value = type === 'keyword' ? valueInput.value.trim() : valueSelect.value;
      if (!value) { showNotification('Select a value', true); return; }
      openPreview(type, value, actionSelect.value);
    });
    btnRow.appendChild(previewBtn);

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'qf-btn-small qf-btn-primary';
    saveBtn.textContent = 'Save';
    saveBtn.addEventListener('click', function () {
      var type = typeSelect.value;
      var value = type === 'keyword' ? valueInput.value.trim() : valueSelect.value;
      var action = actionSelect.value;
      if (!value) { showNotification('Select a value', true); return; }
      if (type === 'keyword' && value.length < 3) { showNotification('Keyword must be at least 3 characters', true); return; }

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      serializedApiCall('add', {
        feedId: feedId,
        type: type,
        value: value,
        action: action,
      }).then(function (data) {
        if (data.filters) {
          updateFiltersFromServer(data.filters);

          // Apply to existing if checked
          if (applyCheckbox.checked) {
            openPreview(type, value, action, true);
          }

          refreshPanel();
          applyInlineControls();
        }
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (!applyCheckbox.checked) {
          showNotification('Filter added');
        }
      }).catch(function (err) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        showNotification(err.error || 'Failed to add filter', true);
      });
    });
    btnRow.appendChild(saveBtn);
    formFields.appendChild(btnRow);

    // Type change handler
    typeSelect.addEventListener('change', function () {
      if (typeSelect.value === 'keyword') {
        valueSelect.style.display = 'none';
        valueInput.style.display = '';
      } else {
        valueSelect.style.display = '';
        valueInput.style.display = 'none';
        populateValueDropdown(typeSelect.value, valueSelect);
      }
    });

    form.appendChild(formFields);
    container.appendChild(form);
  }

  function createFormRow(label) {
    var row = document.createElement('div');
    row.className = 'qf-form-row';
    var lbl = document.createElement('label');
    lbl.className = 'qf-label';
    lbl.textContent = label;
    row.appendChild(lbl);
    return row;
  }

  var cachedFeedData = null;

  function loadDropdownData(formFields) {
    if (cachedFeedData) {
      populateValueDropdown('author', formFields.querySelector('.qf-value-select'));
      return;
    }
    apiGet('feedData', { feedId: feedId }).then(function (data) {
      cachedFeedData = data;
      populateValueDropdown('author', formFields.querySelector('.qf-value-select'));
    }).catch(function () {
      showNotification('Failed to load feed data', true);
    });
  }

  function populateValueDropdown(type, selectEl) {
    if (!cachedFeedData) return;
    selectEl.innerHTML = '';

    var items = type === 'author' ? cachedFeedData.authors : cachedFeedData.tags;
    if (!items || items.length === 0) {
      var opt = document.createElement('option');
      opt.textContent = 'No ' + type + 's found';
      opt.disabled = true;
      selectEl.appendChild(opt);
      return;
    }

    items.forEach(function (item) {
      var opt = document.createElement('option');
      opt.value = item;
      opt.textContent = item;
      selectEl.appendChild(opt);
    });
  }

  function refreshPanel() {
    if (!panelEl) return;
    var body = panelEl.querySelector('.qf-panel-body');
    if (body) {
      body.innerHTML = '';
      renderFilterList(body);
      renderAddForm(body);
    }
  }

  // ========== Preview ==========

  function openPreview(type, value, action, autoApply) {
    apiGet('preview', { feedId: feedId, type: type, value: value }).then(function (data) {
      showPreviewDialog(type, value, action, data, autoApply);
    }).catch(function (err) {
      showNotification(err.error || 'Failed to load preview', true);
    });
  }

  function showPreviewDialog(type, value, action, data, autoApply) {
    var overlay = document.createElement('div');
    overlay.className = 'qf-overlay';

    var dialog = document.createElement('div');
    dialog.className = 'qf-dialog';
    dialog.setAttribute('role', 'dialog');

    var title = document.createElement('h3');
    title.textContent = data.count + ' articles match: ' + type + ' "' + value + '"';
    dialog.appendChild(title);

    if (data.articles && data.articles.length > 0) {
      var list = document.createElement('ul');
      list.className = 'qf-preview-list';
      data.articles.forEach(function (a) {
        var li = document.createElement('li');
        li.textContent = a.title + ' — ' + (a.author || 'unknown') + ' · ' + a.date;
        list.appendChild(li);
      });
      dialog.appendChild(list);
    }

    var btnRow = document.createElement('div');
    btnRow.className = 'qf-dialog-actions';

    if (data.count > 0) {
      var applyBtn = document.createElement('button');
      applyBtn.type = 'button';
      applyBtn.className = 'qf-btn-small qf-btn-primary';
      applyBtn.textContent = 'Apply to ' + data.count + ' articles';
      applyBtn.addEventListener('click', function () {
        applyBtn.disabled = true;
        applyBtn.textContent = 'Applying...';
        applyRetroactive(type, value, action, 0, function (total) {
          applyBtn.textContent = 'Applied to ' + total + ' articles';
          setTimeout(function () { overlay.remove(); }, 1500);
        });
      });
      btnRow.appendChild(applyBtn);
    }

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'qf-btn-small';
    cancelBtn.textContent = data.count > 0 ? 'Cancel' : 'Close';
    cancelBtn.addEventListener('click', function () { overlay.remove(); });
    btnRow.appendChild(cancelBtn);

    dialog.appendChild(btnRow);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    // Auto-apply if triggered from save with checkbox
    if (autoApply && data.count > 0) {
      var btn = dialog.querySelector('.qf-btn-primary');
      if (btn) btn.click();
    }
  }

  function applyRetroactive(type, value, action, offset, onComplete) {
    serializedApiCall('apply', {
      feedId: feedId,
      type: type,
      value: value,
      action: action,
      offset: offset,
    }).then(function (data) {
      if (data.hasMore) {
        applyRetroactive(type, value, action, data.offset, onComplete);
      } else {
        onComplete(data.offset);
      }
    }).catch(function (err) {
      showNotification(err.error || 'Failed to apply', true);
    });
  }

  // ========== Onboarding ==========

  function showOnboardingBanner() {
    if (localStorage.getItem('qf-onboarded')) return;

    var banner = document.createElement('div');
    banner.className = 'qf-banner';
    banner.innerHTML = '<span>QuickFilter: use the &#9734;/&#10003; icons next to authors and tags to create filters.</span>';

    var dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'qf-banner-dismiss';
    dismiss.innerHTML = '&times;';
    dismiss.addEventListener('click', function () {
      banner.remove();
      localStorage.setItem('qf-onboarded', '1');
    });
    banner.appendChild(dismiss);

    var nav = document.querySelector('.nav_menu');
    if (nav && nav.parentNode) {
      nav.parentNode.insertBefore(banner, nav.nextSibling);
    }
  }

  // ========== Notifications ==========

  function showNotification(msg, isError) {
    var notif = document.getElementById('notification');
    if (notif) {
      notif.className = isError ? 'notification error' : 'notification good';
      var textEl = notif.querySelector('.notification-text, p, span');
      if (textEl) textEl.textContent = msg;
      else notif.textContent = msg;
      notif.classList.remove('closed');
      setTimeout(function () { notif.classList.add('closed'); }, 3000);
      return;
    }
    var el = document.createElement('div');
    el.textContent = msg;
    el.className = 'qf-notif ' + (isError ? 'qf-notif-error' : 'qf-notif-ok');
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 3000);
  }

  // ========== Start ==========

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
