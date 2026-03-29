'use strict';

(function () {
  var _initRetries = 0;
  function init() {
    if (typeof context === 'undefined') {
      if (++_initRetries > 100) return;
      return setTimeout(init, 50);
    }

    // Only on article views
    if (!document.getElementById('stream')) return;

    addSidebarLink();
    highlightIfActive();
  }

  function getRecentlyReadUrl(getParam) {
    var base = './?a=normal&state=1&sort=lastUserModified&order=DESC';
    if (getParam) base += '&get=' + getParam;
    return base;
  }

  function addSidebarLink() {
    // Insert after "Favourites" and before the first category
    var tree = document.querySelector('.aside .tree');
    if (!tree) return;

    // Find the Favourites entry to insert after
    var favourites = tree.querySelector('.tree-folder.favorites');
    var insertAfter = favourites || tree.querySelector('.tree-folder.all');
    if (!insertAfter) return;

    var li = document.createElement('li');
    li.className = 'tree-folder recently-read';

    var link = document.createElement('a');
    link.href = getRecentlyReadUrl('');
    link.className = 'tree-folder-title';
    link.innerHTML = '<img class="icon" src="../themes/icons/read.svg" loading="lazy" alt=""> Recently Read';

    li.appendChild(link);

    // Insert after favourites
    insertAfter.parentNode.insertBefore(li, insertAfter.nextSibling);
  }

  function highlightIfActive() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('state') === '1' && params.get('sort') === 'lastUserModified') {
      var link = document.querySelector('.recently-read a');
      if (link) link.classList.add('active');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
