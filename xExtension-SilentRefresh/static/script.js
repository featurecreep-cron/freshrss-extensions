'use strict';

(function () {
  var DEFAULTS = { refresh_interval: 2, title_mode: 'all' };
  var updatingTitle = false;
  var titleUpdatePending = false;
  var MAX_INIT_RETRIES = 100;

  function getConfig() {
    if (typeof context !== 'undefined' && context.extensions && context.extensions['Silent Refresh']) {
      return context.extensions['Silent Refresh'].configuration || DEFAULTS;
    }
    return DEFAULTS;
  }

  function updateTitle(config) {
    if (updatingTitle) { titleUpdatePending = true; return; }
    updatingTitle = true;

    try {
      var count = null;
      if (config.title_mode === 'all') {
        var allEl = document.querySelector('.aside .all .title');
        if (allEl) count = allEl.getAttribute('data-unread');
      } else {
        var active = document.querySelector('.aside .tree .active > .title[data-unread]') ||
                     document.querySelector('.aside .tree-folder.active > .tree-folder-title[data-unread]');
        if (active) count = active.getAttribute('data-unread');
      }

      var bare = document.title.replace(/^\(\d+\)\s*/, '');
      if (count && count !== '0') {
        document.title = '(' + count + ') ' + bare;
      } else {
        document.title = bare;
      }
    } finally {
      updatingTitle = false;
      if (titleUpdatePending) {
        titleUpdatePending = false;
        requestAnimationFrame(function () { updateTitle(config); });
      }
    }
  }

  function refreshSidebar(config) {
    // Use FreshRSS's lightweight JSON endpoint (~400 bytes vs ~200KB HTML)
    fetch('./?c=javascript&a=nbUnreadsPerFeed', { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) return null;
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.feeds) return;

        var feeds = data.feeds;
        var totalUnread = 0;
        var categoryTotals = {};

        // Update each feed's unread count in sidebar
        document.querySelectorAll('.tree .item.feed').forEach(function (item) {
          var feedId = item.id ? item.id.replace('f_', '') : null;
          if (!feedId) return;

          var count = feeds[feedId] || 0;
          totalUnread += count;

          var titleEl = item.querySelector('.item-title');
          if (titleEl) titleEl.setAttribute('data-unread', String(count));

          // Track category totals
          var category = item.closest('.tree-folder.category');
          if (category) {
            var catTitle = category.querySelector('.tree-folder-title');
            var catHref = catTitle ? catTitle.getAttribute('href') : null;
            if (catHref) {
              categoryTotals[catHref] = (categoryTotals[catHref] || 0) + count;
            }
          }
        });

        // Update category counts
        for (var href in categoryTotals) {
          var catEl = document.querySelector('.tree-folder > a.tree-folder-title[href="' + href + '"]');
          if (catEl) {
            var catTitle = catEl.querySelector('.title') || catEl;
            catTitle.setAttribute('data-unread', String(categoryTotals[href]));
          }
        }

        // Update "All articles" count
        var allEl = document.querySelector('.aside .all .title');
        if (allEl) allEl.setAttribute('data-unread', String(totalUnread));

        updateTitle(config);
      })
      .catch(function () { /* silent fail — retry next interval */ });
  }

  function init(retries) {
    if (typeof retries === 'undefined') retries = 0;
    if (typeof context === 'undefined') {
      if (retries >= MAX_INIT_RETRIES) return;
      return setTimeout(function () { init(retries + 1); }, 50);
    }

    var config = getConfig();
    var intervalMs = Math.max(1, config.refresh_interval) * 60000;

    updateTitle(config);

    // Debounced observer for title sync
    var aside = document.querySelector('.aside');
    if (aside) {
      var debounceTimer = null;
      var observer = new MutationObserver(function () {
        if (debounceTimer) return;
        debounceTimer = requestAnimationFrame(function () {
          debounceTimer = null;
          updateTitle(config);
        });
      });
      observer.observe(aside, { attributes: true, subtree: true, attributeFilter: ['data-unread'] });
    }

    setInterval(function () { refreshSidebar(config); }, intervalMs);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
