'use strict';

(function () {
  var DEFAULTS = { refresh_interval: 2, title_mode: 'all' };
  var updatingTitle = false;
  var titleUpdatePending = false;
  var MAX_INIT_RETRIES = 100;
  // Two CONSECUTIVE failures = dead session, not a blip — and it stays safely
  // under fail2ban-style thresholds (default maxretry 5, paranoid floor 3).
  var MAX_AUTH_FAILURES = 2;

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

  var intervalId = null;
  var authFailures = 0;

  // Stop polling and hand the tab to the auth flow. A fetch cannot complete an
  // interactive login or an auth-portal redirect, so a full navigation is the
  // only re-auth mechanism. If the session is recoverable the reload
  // re-authenticates transparently and polling resumes on re-init; if not, the
  // tab lands on the login page and the poller is gone with it.
  function stopAndReauth() {
    if (intervalId) {
      clearInterval(intervalId);
      intervalId = null;
    }
    window.location.reload();
  }

  function refreshSidebar(config) {
    // A hidden tab must never poll: an unattended tab with an expired session
    // otherwise accumulates 401s until fail2ban-style jails ban the client IP.
    if (document.hidden) return;

    // Use FreshRSS's lightweight JSON endpoint (~400 bytes vs ~200KB HTML)
    fetch('./?c=javascript&a=nbUnreadsPerFeed', {
      credentials: 'same-origin',
      redirect: 'manual',
    })
      .then(function (r) {
        // redirect: 'manual' turns redirects into opaque responses (type 'opaqueredirect', status 0)
        // This catches auth expiry — FreshRSS redirects to login when session dies
        if (r.type === 'opaqueredirect' || r.status === 0) {
          stopAndReauth();
          return null;
        }
        // A fronting auth layer (proxy/SSO) answers with a bare 401/403 instead
        // of FreshRSS's redirect; a single one may be a transient portal
        // bounce, so it only counts toward MAX_AUTH_FAILURES.
        if (r.status === 401 || r.status === 403) {
          authFailures += 1;
          if (authFailures >= MAX_AUTH_FAILURES) stopAndReauth();
          return null;
        }
        if (!r.ok) return null;
        var contentType = r.headers.get('content-type') || '';
        if (contentType.indexOf('json') === -1) {
          // Got HTML instead of JSON — session expired
          stopAndReauth();
          return null;
        }
        authFailures = 0;
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

    intervalId = setInterval(function () { refreshSidebar(config); }, intervalMs);

    // Hidden ticks are skipped, so refresh immediately on return to the tab —
    // the badge is only ever stale while nobody can see it.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && intervalId) refreshSidebar(config);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
