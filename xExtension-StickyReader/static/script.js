'use strict';

(function () {
  var DEFAULTS = { scroll_anchor: true, scroll_target: 'control_bar', title_feed_name: true, hide_feed_column: true, lock_sidebar: false, hide_sub_management: false };
  var config;

  // ========== Scroll Anchoring ==========

  var scrollPadding = 0;
  var scrollContainer = null; // window or #rv-content

  // restructureLayout() creates #rv-content at every viewport width, but the CSS
  // that makes it a scrolling column is gated behind FreshRSS's 841px breakpoint
  // (see style.css). Below that the div is an inert wrapper and the page scrolls
  // on the window as usual, so decide by whether it actually scrolls rather than
  // by whether it exists — otherwise scroll anchoring silently drives an element
  // with nowhere to go.
  function activeScrollContainer() {
    if (scrollContainer && scrollContainer.scrollHeight > scrollContainer.clientHeight) {
      return scrollContainer;
    }
    return null;
  }

  function getScrollTop() {
    var container = activeScrollContainer();
    return container ? container.scrollTop : window.scrollY;
  }

  function doScrollBy(delta) {
    var container = activeScrollContainer();
    if (container) container.scrollBy({ top: delta, behavior: 'instant' });
    else window.scrollBy({ top: delta, behavior: 'instant' });
  }

  function findBackground(el) {
    var current = el;
    while (current && current !== document.documentElement) {
      var bg = getComputedStyle(current).backgroundColor;
      if (bg && bg !== 'transparent' && bg !== 'rgba(0, 0, 0, 0)') return bg;
      current = current.parentElement;
    }
    return '';
  }

  function setupStickyBars() {
    var target = config.scroll_target || 'control_bar';
    var header = document.querySelector('.header');
    var nav = document.querySelector('.nav_menu');
    var headerHeight = header ? header.offsetHeight : 0;
    var navHeight = nav ? nav.offsetHeight : 0;

    document.documentElement.classList.remove('rv-scroll-active', 'rv-sticky-header', 'rv-no-sticky');
    document.documentElement.classList.add('rv-scroll-active');

    if (nav) {
      var bg = findBackground(nav);
      if (bg) nav.style.backgroundColor = bg;
    }

    if (target === 'search_bar') {
      // Solution 4: restructure DOM so header stays, content scrolls
      document.documentElement.classList.add('rv-sticky-header');
      restructureLayout();
      scrollPadding = navHeight; // header always visible, just need nav padding
    } else if (target === 'control_bar') {
      scrollPadding = navHeight;
    } else {
      document.documentElement.classList.add('rv-no-sticky');
      scrollPadding = 0;
    }
  }

  function restructureLayout() {
    var html = document.documentElement;
    var body = document.body;
    var header = document.querySelector('.header');
    var global = document.getElementById('global');
    var aside = document.querySelector('.aside');
    var nav = document.querySelector('.nav_menu');
    var stream = document.getElementById('stream');

    if (!global || !nav || !stream) return;
    // Don't restructure twice
    if (document.getElementById('rv-content')) {
      scrollContainer = document.getElementById('rv-content');
      return;
    }

    // Create content wrapper for nav + stream
    var content = document.createElement('div');
    content.id = 'rv-content';
    global.insertBefore(content, nav);
    content.appendChild(nav);
    content.appendChild(stream);

    // Also move any siblings that were between nav and stream (datalists, templates)
    // They're already moved since we moved nav and stream

    scrollContainer = content;
  }

  function scrollHeaderToTop(header) {
    if (!header) return;
    var currentTop = header.getBoundingClientRect().top;
    // In restructured layout, the content area starts at the top of #rv-content
    // scrollPadding = height of sticky nav within the content
    var container = activeScrollContainer();
    var contentTop = container ? container.getBoundingClientRect().top : 0;
    var targetTop = contentTop + scrollPadding;
    var delta = currentTop - targetTop;
    if (Math.abs(delta) > 2) {
      doScrollBy(delta);
    }
  }

  function initScrollAnchor() {
    document.addEventListener('click', function (e) {
      var header = e.target.closest('.flux_header');
      if (!header) return;
      var flux = header.closest('.flux');
      if (!flux) return;

      var wasActive = flux.classList.contains('active');
      var savedTop = header.getBoundingClientRect().top;

      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          var isNowActive = flux.classList.contains('active');

          if (isNowActive && !wasActive) {
            scrollHeaderToTop(header);
          } else if (!isNowActive && wasActive) {
            // Closing — anchor header position
            var newTop = header.getBoundingClientRect().top;
            var delta = newTop - savedTop;
            if (Math.abs(delta) > 2) {
              doScrollBy(delta);
            }
          }
        });
      });
    }, true);

    // Keyboard navigation
    document.addEventListener('keydown', function (e) {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
      if (e.key !== 'j' && e.key !== 'k' && e.key !== 'n' && e.key !== 'p') return;

      var prevActive = document.querySelector('.flux.active');

      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          var active = document.querySelector('.flux.active');
          if (active && active !== prevActive) {
            scrollHeaderToTop(active.querySelector('.flux_header'));
          }
        });
      });
    }, true);
  }

  // ========== Feed/Category Name in Control Bar ==========

  var feedNameEl = null;

  function initTitleBar() {
    var navMenu = document.querySelector('.nav_menu');
    if (!navMenu) return;

    // Tells the stylesheet it may restructure the toolbar into a flex row.
    // Without a feed name there is nothing to align against, so the nav is left
    // as FreshRSS renders it — centred, and free to wrap when space runs out.
    document.documentElement.classList.add('rv-has-feed-name');

    feedNameEl = document.createElement('div');
    feedNameEl.className = 'rv-feed-name';

    var spacer = document.createElement('div');
    spacer.className = 'rv-spacer';

    var toggleAside = navMenu.querySelector('#nav_menu_toggle_aside');
    var insertBefore = toggleAside ? toggleAside.nextSibling : navMenu.firstChild;
    navMenu.insertBefore(feedNameEl, insertBefore);
    navMenu.insertBefore(spacer, feedNameEl.nextSibling);

    updateFeedName();

    window.addEventListener('popstate', function () {
      setTimeout(updateFeedName, 100);
    });

    document.addEventListener('click', function (e) {
      var link = e.target.closest('.tree a.item-title, .tree a.tree-folder-title, .aside .all a');
      if (!link) return;
      setTimeout(updateFeedName, 300);
    });
  }

  function updateFeedName() {
    var params = new URLSearchParams(window.location.search);
    var get = params.get('get') || '';
    var name = '';

    if (get.match(/^f_\d+$/)) {
      var feedLink = document.querySelector('.aside a.item-title[href*="get=' + get + '"]');
      if (feedLink) name = feedLink.textContent.trim();
    } else if (get.match(/^c_\d+$/)) {
      var catLink = document.querySelector('.aside a.tree-folder-title[href*="get=' + get + '"]');
      if (catLink) name = catLink.textContent.trim();
    } else if (get === 's') {
      name = 'Favourites';
    } else if (get === 'i') {
      name = 'Important';
    } else if (get === 'T') {
      name = 'Labels';
    } else if (!get || get === '') {
      name = 'All articles';
    }

    if (!name && get) {
      var streamHeader = document.querySelector('#stream .header .item.title');
      if (streamHeader) name = streamHeader.textContent.trim();
    }

    if (feedNameEl) {
      feedNameEl.textContent = name || '';
      feedNameEl.style.display = name ? '' : 'none';
    }
  }

  // ========== Hide Feed Column ==========

  function initFeedColumnHide() {
    detectSingleFeed();
    window.addEventListener('popstate', detectSingleFeed);

    document.addEventListener('click', function (e) {
      var link = e.target.closest('.tree a.item-title, .tree a.tree-folder-title, .aside .all a');
      if (!link) return;
      setTimeout(detectSingleFeed, 300);
    });
  }

  function detectSingleFeed() {
    var params = new URLSearchParams(window.location.search);
    var get = params.get('get') || '';
    if (get.match(/^f_\d+$/)) {
      document.documentElement.classList.add('view-single-feed');
    } else {
      document.documentElement.classList.remove('view-single-feed');
    }
  }

  // ========== Init ==========

  function isArticleView() {
    return !!document.getElementById('stream');
  }

  var _initRetries = 0;
  function init() {
    if (typeof context === 'undefined') {
      if (++_initRetries > 100) return;
      return setTimeout(init, 50);
    }

    if (!isArticleView()) return;

    config = (context.extensions && context.extensions['Sticky Reader'] && context.extensions['Sticky Reader'].configuration) || DEFAULTS;

    if (config.scroll_anchor) {
      setupStickyBars();
      initScrollAnchor();
    }
    if (config.title_feed_name) initTitleBar();
    if (config.hide_feed_column) initFeedColumnHide();
    if (config.hide_sub_management) document.documentElement.classList.add('rv-hide-sub-mgmt');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
