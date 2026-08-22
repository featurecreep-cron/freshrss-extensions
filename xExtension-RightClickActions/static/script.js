'use strict';

(function () {
  var DEFAULTS = {
    zones: { header: true, body: false, sidebar_feed: true, sidebar_category: true },
    actions: {
      header: { toggle_read: true, star_toggle: true, open_new_tab: true, mark_older_read: true, mark_older_unread: true, mark_newer_read: true, mark_newer_unread: true, filter_title: true, filter_feed: true },
      body: { toggle_read: true, star_toggle: true, open_new_tab: true, mark_older_read: true, mark_older_unread: true, mark_newer_read: true, mark_newer_unread: true, filter_title: true, filter_feed: true },
      sidebar_feed: { mark_all_read: true, mark_all_unread: true, open_settings: true },
      sidebar_category: { mark_all_read: true, mark_all_unread: true }
    }
  };

  var config;
  var menu = null;
  var targetFlux = null;
  var targetZone = null;
  var targetEl = null;

  // ---------- Theme detection ----------

  function isDark() {
    var bg = getComputedStyle(document.body).backgroundColor;
    if (!bg || bg === 'transparent') return false;
    var m = bg.match(/\d+/g);
    if (!m) return false;
    return (parseInt(m[0]) + parseInt(m[1]) + parseInt(m[2])) / 3 < 128;
  }

  // ---------- Target label for menu header ----------

  function getTargetLabel(zone) {
    if (zone === 'header' || zone === 'body') {
      if (!targetFlux) return '';
      var title = targetFlux.querySelector('.flux_header .title');
      var text = title ? title.textContent.trim() : '';
      if (text.length > 45) text = text.substring(0, 45) + '\u2026';
      var feed = targetFlux.querySelector('.flux_header .website .item-element');
      var feedName = feed ? feed.textContent.trim() : '';
      return text || feedName || 'Article';
    }
    if (zone === 'sidebar_feed') {
      var link = targetEl ? (targetEl.closest('.item') || targetEl).querySelector('.item-title') || targetEl : null;
      return link ? link.textContent.trim() : 'Feed';
    }
    if (zone === 'sidebar_category') {
      var cat = targetEl ? targetEl.closest('.tree-folder-title') || targetEl : null;
      return cat ? cat.textContent.trim() : 'Category';
    }
    return '';
  }

  // ---------- Action definitions per zone ----------

  // mark_older and mark_newer are single config toggles that show both read/unread menu items
  var ARTICLE_ACTIONS = [
    { key: 'toggle_read', icon: '\u2611\uFE0F', label: 'Toggle read/unread' },
    { key: 'star_toggle', icon: '\u2B50', label: 'Toggle favourite' },
    { key: 'open_new_tab', icon: '\uD83D\uDD17', label: 'Open in new tab' },
    { sep: true },
    { key: 'mark_older', action: 'mark_older_read', icon: '\u2B07\uFE0F', label: 'Mark older as read' },
    { key: 'mark_older', action: 'mark_older_unread', icon: '\u2B07\uFE0F', label: 'Mark older as unread' },
    { sep: true },
    { key: 'mark_newer', action: 'mark_newer_read', icon: '\u2B06\uFE0F', label: 'Mark newer as read' },
    { key: 'mark_newer', action: 'mark_newer_unread', icon: '\u2B06\uFE0F', label: 'Mark newer as unread' },
    { sep: true },
    { key: 'filter_title', icon: '\uD83D\uDEAB', label: 'Hide articles like this' },
    { key: 'filter_star', icon: '\u2B50', label: 'Star articles like this' },
    { key: 'filter_feed', icon: '\uD83D\uDD0D', label: 'Show this feed only' }
  ];

  var SIDEBAR_FEED_ACTIONS = [
    { key: 'mark_all_read', icon: '\u2611\uFE0F', label: 'Mark all as read' },
    { key: 'mark_all_unread', icon: '\u2610', label: 'Mark all as unread' },
    { sep: true },
    { key: 'recently_read', icon: '\uD83D\uDD52', label: 'Recently read' },
    { key: 'open_settings', icon: '\u2699\uFE0F', label: 'Feed settings' }
  ];

  var SIDEBAR_CATEGORY_ACTIONS = [
    { key: 'mark_all_read', icon: '\u2611\uFE0F', label: 'Mark all as read' },
    { key: 'mark_all_unread', icon: '\u2610', label: 'Mark all as unread' },
    { sep: true },
    { key: 'recently_read', icon: '\uD83D\uDD52', label: 'Recently read' },
    { key: 'add_subscription', icon: '\u2795', label: 'Add subscription' },
    { key: 'manage_subscriptions', icon: '\u2699\uFE0F', label: 'Manage subscriptions' },
    { sep: true },
    { key: 'expand_all', icon: '\u25BC', label: 'Expand all categories' },
    { key: 'collapse_all', icon: '\u25B6', label: 'Collapse all categories' }
  ];

  // ---------- Menu construction ----------

  function getActionsForZone(zone) {
    if (zone === 'header' || zone === 'body') return ARTICLE_ACTIONS;
    if (zone === 'sidebar_feed') return SIDEBAR_FEED_ACTIONS;
    if (zone === 'sidebar_category') return SIDEBAR_CATEGORY_ACTIONS;
    return [];
  }

  function buildMenu(zone) {
    if (menu) { menu.remove(); menu = null; }

    var m = document.createElement('div');
    m.id = 'frss-ctx-menu';
    if (isDark()) m.classList.add('frss-dark');

    // Header showing what we're acting on
    var label = getTargetLabel(zone);
    if (label) {
      var header = document.createElement('div');
      header.className = 'frss-ctx-header';
      header.textContent = label;
      m.appendChild(header);
      var headerSep = document.createElement('div');
      headerSep.className = 'frss-ctx-sep';
      m.appendChild(headerSep);
    }

    var actions = getActionsForZone(zone);
    var zoneActions = (config.actions && config.actions[zone]) || {};
    var lastWasSep = true;

    actions.forEach(function (item) {
      if (item.sep) {
        if (lastWasSep) return;
        var sep = document.createElement('div');
        sep.className = 'frss-ctx-sep';
        m.appendChild(sep);
        lastWasSep = true;
        return;
      }

      if (!zoneActions[item.key]) return;

      var row = document.createElement('div');
      row.className = 'frss-ctx-item';
      var actionId = item.action || item.key;
      row.dataset.action = actionId;

      var actionLabel = item.label;
      if ((actionId === 'filter_title' || actionId === 'filter_star') && targetFlux) {
        var text = articleTitleText(targetFlux);
        if (text.length > 40) text = text.substring(0, 40) + '\u2026';
        actionLabel = (actionId === 'filter_star' ? 'Star: ' : 'Filter: ') + text;
      }

      var iconSpan = document.createElement('span');
      iconSpan.className = 'frss-ctx-icon';
      iconSpan.textContent = item.icon;
      var labelSpan = document.createElement('span');
      labelSpan.className = 'frss-ctx-label';
      labelSpan.textContent = actionLabel;
      row.appendChild(iconSpan);
      row.appendChild(labelSpan);

      row.addEventListener('click', function (e) {
        try { handleAction(actionId, zone); } catch (err) { console.error('[ContextMenu]', err); }
        hideMenu();
        e.stopPropagation();
      });

      m.appendChild(row);
      lastWasSep = false;
    });

    // Remove trailing separator
    var last = m.lastElementChild;
    if (last && last.classList.contains('frss-ctx-sep')) last.remove();

    document.body.appendChild(m);
    menu = m;
    return m;
  }

  function showMenu(x, y, zone) {
    buildMenu(zone);
    if (!menu) return;
    menu.style.display = 'block';
    var mw = menu.offsetWidth || 240;
    var mh = menu.offsetHeight || 300;
    menu.style.left = Math.min(x, window.innerWidth - mw - 8) + 'px';
    menu.style.top = Math.min(y, window.innerHeight - mh - 8) + 'px';
  }

  function hideMenu() {
    if (menu) { menu.style.display = 'none'; }
    targetFlux = null;
    targetZone = null;
    targetEl = null;
  }

  // ---------- Article helpers ----------

  function getFluxes() {
    return Array.from(document.querySelectorAll('.flux'));
  }

  function toggleRead(flux) {
    var link = flux.querySelector('a.read');
    if (link) link.click();
  }

  function setRead(flux, makeRead) {
    var isUnread = flux.classList.contains('not_read');
    if ((makeRead && isUnread) || (!makeRead && !isUnread)) toggleRead(flux);
  }

  function toggleStar(flux) {
    var link = flux.querySelector('a.bookmark');
    if (link) link.click();
  }

  // Safe to run after the server has already starred the same articles: the
  // link carries the state it will set (is_favorite=1 while the row is
  // unstarred), and this only clicks rows that still look unstarred, so a
  // second pass sets true rather than toggling back to false.
  function setStar(flux, makeStar) {
    var isStarred = flux.classList.contains('favorite');
    if ((makeStar && !isStarred) || (!makeStar && isStarred)) toggleStar(flux);
  }

  function getOlderNewer(fluxes, idx) {
    var before = [];
    var after = [];
    var i;
    for (i = 0; i <= idx; i++) before.push(i);
    for (i = idx; i < fluxes.length; i++) after.push(i);

    var newestFirst = true;
    if (fluxes.length >= 2) {
      var firstDate = fluxes[0].querySelector('.date');
      var lastDate = fluxes[fluxes.length - 1].querySelector('.date');
      var t0 = firstDate ? firstDate.getAttribute('datetime') || firstDate.textContent : '';
      var t1 = lastDate ? lastDate.getAttribute('datetime') || lastDate.textContent : '';
      if (t0 && t1) {
        newestFirst = new Date(t0) >= new Date(t1);
      } else {
        var order = new URLSearchParams(window.location.search).get('order');
        newestFirst = order !== 'ASC';
      }
    }

    return {
      older: newestFirst ? after : before,
      newer: newestFirst ? before : after
    };
  }

  function addPermanentFilter(feedId, filter, action) {
    var csrfToken = (context.extensions && context.extensions['Right-Click Actions'] && context.extensions['Right-Click Actions'].csrf) || '';
    var fd = new FormData();
    fd.append('id', feedId);
    fd.append('filter', filter);
    fd.append('action', action || 'read');
    fd.append('_csrf', csrfToken);

    fetch('./?c=rcafilter&a=add', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) showNotification(data.message);
        else showNotification(data.error || 'Unknown error', true);
      })
      .catch(function (err) { showNotification('Error: ' + err, true); });
  }

  // FreshRSS reads a bare `intitle:two words` as intitle:two AND a loose
  // "words", so a keyword lifted from an article title — which is exactly what
  // the dialog pre-fills — has to be quoted or the saved rule matches far more
  // than the user asked for. Mirrors QuickFilterService::buildFilterString(),
  // including which operator carries which scope: intitle: reads the title,
  // intext: the stored content, a bare term either of them.
  function buildKeywordFilter(keyword, scope) {
    var escaped = keyword.replace(/"/g, '\\"');
    if (scope === 'article') return 'intext:"' + escaped + '"';
    if (scope === 'both') {
      // The bare term is the only form with no operator in front of it, so a
      // value shaped like one cannot be told apart from one after a round trip:
      // FreshRSS stores "#rust" correctly and matches the right articles, but
      // Search::__toString() drops quotes it no longer needs and the rule reads
      // back as a tag. Refused rather than silently mislabelled.
      if (/^[#!-]|^[A-Za-z]+:/.test(keyword)) {
        throw new Error('A title-or-body keyword cannot start with # or - or contain a word ' +
          'followed by a colon — FreshRSS reads those as search operators. Pick Title or ' +
          'Article body instead.');
      }
      return '"' + escaped + '"';
    }
    return 'intitle:"' + escaped + '"';
  }

  // The article body is in the stream for every entry, not just the open one —
  // app/views/helpers/index/article.phtml renders .text unconditionally inside
  // .flux_content .content — so the on-page sweep can read it without opening
  // anything. Note it reads rendered text while FreshRSS's own intext: matches
  // the stored HTML, so the server, which has the last word, can match markup
  // this cannot see.
  function articleBodyText(flux) {
    var el = flux.querySelector('.flux_content .content .text') ||
             flux.querySelector('.content .text');
    return el ? el.textContent : '';
  }

  function fluxMatches(flux, lowerKeyword, scope) {
    var inTitle = articleTitleText(flux).toLowerCase().indexOf(lowerKeyword) !== -1;
    if (scope === 'title') return inTitle;
    var inBody = articleBodyText(flux).toLowerCase().indexOf(lowerKeyword) !== -1;
    return scope === 'article' ? inBody : (inTitle || inBody);
  }

  // The title anchor is not only the title: FreshRSS nests its inline
  // <span class="author"> inside it, and other extensions inject controls in
  // there too — with QuickFilter installed, .textContent reads
  // "Wednesday assorted links Tyler Cowen☆✓". Taking just the anchor's own text
  // nodes leaves the title, whoever else has written into the element.
  function articleTitleText(flux) {
    var a = flux.querySelector('.flux_header a.item-element.title') || flux.querySelector('.title');
    if (!a) return '';
    var text = '';
    Array.prototype.forEach.call(a.childNodes, function (n) {
      if (n.nodeType === Node.TEXT_NODE) text += n.textContent;
    });
    text = text.trim();
    return text || a.textContent.trim();
  }

  var FILTER_HEADINGS = {
    read: 'Hide articles containing',
    star: 'Star articles containing'
  };

  var SCOPES = [
    ['title', 'the title'],
    ['article', 'the article body'],
    ['both', 'the title or the body']
  ];

  // A native prompt() cannot carry a second control, and the old one had to name
  // the title in its question because that was the only thing it could filter on.
  // Now that scope is a choice, the question stops claiming to know the answer.
  function openFilterDialog(action, prefill, onConfirm) {
    // handleAction() runs before hideMenu(), so targetFlux is still set while this
    // is called — but it is null by the time the dialog is confirmed. Everything
    // the callback needs is captured by the caller before we get here; nothing
    // below may read it.
    var previousFocus = document.activeElement;

    var overlay = document.createElement('div');
    overlay.className = 'rca-overlay';

    var dialog = document.createElement('div');
    dialog.className = 'rca-dialog';
    // Same theme test the context menu uses, for the same reason: FreshRSS themes
    // do not expose a variable we can read, so the background is sampled.
    if (isDark()) dialog.classList.add('frss-dark');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', FILTER_HEADINGS[action]);

    var heading = document.createElement('h3');
    heading.className = 'rca-dialog-title';
    heading.textContent = FILTER_HEADINGS[action];
    dialog.appendChild(heading);

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'rca-dialog-input';
    input.value = prefill || '';
    dialog.appendChild(input);

    var scopeLabel = document.createElement('label');
    scopeLabel.className = 'rca-dialog-label';
    scopeLabel.textContent = 'Match in';
    var scopeSelect = document.createElement('select');
    scopeSelect.className = 'rca-dialog-select';
    SCOPES.forEach(function (pair) {
      var opt = document.createElement('option');
      opt.value = pair[0];
      opt.textContent = pair[1];
      scopeSelect.appendChild(opt);
    });
    scopeLabel.appendChild(scopeSelect);
    dialog.appendChild(scopeLabel);

    var actions = document.createElement('div');
    actions.className = 'rca-dialog-actions';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'rca-dialog-btn';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', close);
    actions.appendChild(cancelBtn);

    var okBtn = document.createElement('button');
    okBtn.type = 'button';
    okBtn.className = 'rca-dialog-btn rca-dialog-btn-primary';
    okBtn.textContent = action === 'star' ? 'Star' : 'Hide';
    okBtn.addEventListener('click', confirm);
    actions.appendChild(okBtn);

    dialog.appendChild(actions);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    // Selected, not just focused: the field is pre-filled with a whole headline
    // and the usual next move is to cut it down to one word.
    input.focus();
    input.select();

    overlay.addEventListener('mousedown', function (e) {
      if (e.target === overlay) close();
    });
    dialog.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      if (e.key === 'Enter' && e.target === input) { e.preventDefault(); confirm(); }
    });

    function close() {
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    }

    // The dialog stays open on a rejected keyword. Closing it would throw away
    // what the user typed to tell them it was wrong.
    function confirm() {
      var keyword = input.value.trim();
      if (!keyword) { input.focus(); return; }
      var scope = scopeSelect.value;
      var filter;
      try {
        filter = buildKeywordFilter(keyword, scope);
      } catch (err) {
        showNotification(err.message, true);
        input.focus();
        return;
      }
      close();
      onConfirm(keyword, scope, filter);
    }
  }

  function filterOnTitle(action, fluxes) {
    var articleTitle = articleTitleText(targetFlux);
    var feedId = targetFlux.dataset.feed;
    if (!feedId) return;

    openFilterDialog(action, articleTitle, function (keyword, scope, filter) {
      addPermanentFilter(feedId, filter, action);

      // Apply it to what is already on screen. The server pass covers the rest of
      // the feed but cannot touch this page's DOM. Scoped to the same feed as the
      // saved rule — matching articles in other feeds are not what was asked for,
      // and the rule will never act on them again.
      var lower = keyword.toLowerCase();
      var matched = 0;
      fluxes.forEach(function (f) {
        if (f.dataset.feed !== feedId) return;
        if (fluxMatches(f, lower, scope)) {
          if (action === 'star') setStar(f, true);
          else setRead(f, true);
          matched++;
        }
      });
      if (matched > 0) {
        showNotification(matched + ' article' + (matched === 1 ? '' : 's') +
          (action === 'star' ? ' starred' : ' marked read') + ' on page');
      }
    });
  }

  // ---------- Sidebar helpers ----------

  function extractId(href, prefix) {
    if (!href) return null;
    var match = href.match(new RegExp(prefix + '(\\d+)'));
    return match ? match[1] : null;
  }

  function sidebarMarkRead(getParam, makeRead) {
    var csrfInput = document.querySelector('#mark-read-aside input[name="_csrf"]') ||
                    document.querySelector('input[name="_csrf"]');
    var csrf = csrfInput ? csrfInput.value : '';

    var url = './?c=entry&a=read&get=' + encodeURIComponent(getParam);
    if (!makeRead) {
      url += '&is_read=0';
    }

    var body = new URLSearchParams();
    body.append('_csrf', csrf);

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin',
    })
      .then(function (r) {
        if (r.ok || r.redirected) {
          showNotification(makeRead ? 'Marked all as read' : 'Marked all as unread');
          window.location.reload();
        } else {
          showNotification('Failed (HTTP ' + r.status + ')', true);
        }
      })
      .catch(function (err) { showNotification('Error: ' + err, true); });
  }

  // ---------- Notification ----------

  function showNotification(msg, isError) {
    var n = document.createElement('div');
    n.textContent = msg;
    Object.assign(n.style, {
      position: 'fixed', bottom: '20px', right: '20px', zIndex: '99999',
      background: isError ? '#c62828' : '#323232', color: '#fff',
      padding: '12px 24px', borderRadius: '8px', fontSize: '13px',
      fontFamily: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
      boxShadow: '0 4px 12px rgba(0,0,0,.3)',
      opacity: '0', transform: 'translateY(10px)', transition: 'opacity 0.2s, transform 0.2s'
    });
    document.body.appendChild(n);
    requestAnimationFrame(function () {
      n.style.opacity = '1';
      n.style.transform = 'translateY(0)';
    });
    setTimeout(function () {
      n.style.opacity = '0';
      n.style.transform = 'translateY(10px)';
      setTimeout(function () { n.remove(); }, 200);
    }, 3000);
  }

  // ---------- Action handler ----------

  function handleAction(action, zone) {
    if (zone === 'header' || zone === 'body') handleArticleAction(action);
    else if (zone === 'sidebar_feed') handleSidebarFeedAction(action);
    else if (zone === 'sidebar_category') handleSidebarCategoryAction(action);
  }

  function handleArticleAction(action) {
    if (!targetFlux) return;
    var fluxes = getFluxes();
    var idx = fluxes.indexOf(targetFlux);
    var ranges;

    switch (action) {
      case 'toggle_read':
        toggleRead(targetFlux);
        break;
      case 'star_toggle':
        var bookmark = targetFlux.querySelector('a.bookmark');
        if (bookmark) bookmark.click();
        break;
      case 'open_new_tab':
        var extLink = targetFlux.querySelector('.flux_content a[href^="http"]');
        var headerLink = targetFlux.querySelector('.flux_header a.item-element.title');
        var url = extLink ? extLink.getAttribute('href') : (headerLink ? headerLink.getAttribute('href') : null);
        if (url) window.open(url, '_blank');
        break;
      case 'mark_older_read':
        if (idx === -1) break;
        ranges = getOlderNewer(fluxes, idx);
        ranges.older.forEach(function (i) { setRead(fluxes[i], true); });
        showNotification(ranges.older.length + ' articles marked read');
        break;
      case 'mark_older_unread':
        if (idx === -1) break;
        ranges = getOlderNewer(fluxes, idx);
        ranges.older.forEach(function (i) { setRead(fluxes[i], false); });
        showNotification(ranges.older.length + ' articles marked unread');
        break;
      case 'mark_newer_read':
        if (idx === -1) break;
        ranges = getOlderNewer(fluxes, idx);
        ranges.newer.forEach(function (i) { setRead(fluxes[i], true); });
        showNotification(ranges.newer.length + ' articles marked read');
        break;
      case 'mark_newer_unread':
        if (idx === -1) break;
        ranges = getOlderNewer(fluxes, idx);
        ranges.newer.forEach(function (i) { setRead(fluxes[i], false); });
        showNotification(ranges.newer.length + ' articles marked unread');
        break;
      case 'filter_title':
        filterOnTitle('read', fluxes);
        break;
      case 'filter_star':
        filterOnTitle('star', fluxes);
        break;
      case 'filter_feed':
        var feedLink = targetFlux.querySelector('.flux_header a[href*="get=f_"]');
        if (feedLink) window.location.href = feedLink.href;
        break;
    }
  }

  function handleSidebarFeedAction(action) {
    if (!targetEl) return;
    var item = targetEl.closest('.item');
    var link = item ? (item.querySelector('a.item-title') || targetEl) : targetEl;
    var href = link.getAttribute('href');
    var feedGet = extractId(href, 'f_');

    switch (action) {
      case 'mark_all_read':
        if (feedGet) sidebarMarkRead('f_' + feedGet, true);
        break;
      case 'mark_all_unread':
        if (feedGet) sidebarMarkRead('f_' + feedGet, false);
        break;
      case 'recently_read':
        if (feedGet) {
          window.location.href = './?a=normal&state=1&sort=lastUserModified&order=DESC&get=f_' + feedGet;
        }
        break;
      case 'open_settings':
        var feedId = extractId(href, 'f_');
        if (feedId) window.location.href = './?c=subscription&a=feed&id=' + feedId;
        break;
    }
  }

  function handleSidebarCategoryAction(action) {
    if (!targetEl) return;
    var el = targetEl.closest('.tree-folder-title') || targetEl.closest('a') || targetEl;
    var href = el.getAttribute('href');
    var catGet = extractId(href, 'c_');

    switch (action) {
      case 'mark_all_read':
        if (catGet) sidebarMarkRead('c_' + catGet, true);
        break;
      case 'mark_all_unread':
        if (catGet) sidebarMarkRead('c_' + catGet, false);
        break;
      case 'recently_read':
        if (catGet) {
          window.location.href = './?a=normal&state=1&sort=lastUserModified&order=DESC&get=c_' + catGet;
        }
        break;
      case 'add_subscription':
        window.location.href = './?c=subscription&a=add';
        break;
      case 'manage_subscriptions':
        window.location.href = './?c=subscription';
        break;
      case 'expand_all':
        document.querySelectorAll('.tree-folder.category').forEach(function (f) {
          var items = f.querySelector('.tree-folder-items');
          if (items && !items.classList.contains('active')) {
            var toggle = f.querySelector('.tree-folder-title button.dropdown-toggle');
            if (toggle) toggle.click();
          }
        });
        showNotification('All categories expanded');
        break;
      case 'collapse_all':
        document.querySelectorAll('.tree-folder.category').forEach(function (f) {
          var items = f.querySelector('.tree-folder-items');
          if (items && items.classList.contains('active')) {
            var toggle = f.querySelector('.tree-folder-title button.dropdown-toggle');
            if (toggle) toggle.click();
          }
        });
        showNotification('All categories collapsed');
        break;
    }
  }

  // ---------- Zone detection ----------

  function detectZone(e) {
    if (e.target.closest('.flux_header')) return 'header';
    if (e.target.closest('.flux_content')) return 'body';
    if (e.target.closest('.tree-folder-title')) return 'sidebar_category';
    if (e.target.closest('.item') && e.target.closest('.tree')) return 'sidebar_feed';
    return null;
  }

  // ---------- Event listeners ----------

  var _initRetries = 0;
  function init() {
    if (typeof context === 'undefined') {
      if (++_initRetries > 100) return;
      return setTimeout(init, 50);
    }

    config = (context.extensions && context.extensions['Right-Click Actions'] && context.extensions['Right-Click Actions'].configuration) || DEFAULTS;

    document.addEventListener('contextmenu', function (e) {
      try {
        var zone = detectZone(e);
        if (!zone) return;
        if (!config.zones || !config.zones[zone]) return;

        e.preventDefault();

        if (zone === 'header' || zone === 'body') {
          targetFlux = e.target.closest('.flux');
        } else {
          targetFlux = null;
        }
        targetZone = zone;
        targetEl = e.target;

        showMenu(e.clientX, e.clientY, zone);
      } catch (err) {
        console.error('[ContextMenu] Error:', err);
      }
    });

    document.addEventListener('click', function () { hideMenu(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
  }

  // Configure page: zone toggle interactivity (no inline JS needed)
  function initConfigPage() {
    document.querySelectorAll('.rca-zone-toggle').forEach(function (toggle) {
      function updateZone() {
        var zone = toggle.dataset.zone;
        var actions = document.getElementById('actions-' + zone);
        if (!actions) return;
        if (toggle.checked) {
          actions.classList.remove('rca-disabled');
        } else {
          actions.classList.add('rca-disabled');
        }
        // Disable/enable all checkboxes within the zone
        actions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
          cb.disabled = !toggle.checked;
        });
      }
      toggle.addEventListener('change', updateZone);
      // Apply on page load too
      updateZone();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(); initConfigPage(); });
  } else {
    init();
    initConfigPage();
  }
})();
