'use strict';

(function () {
  var installed = {};

  var _initRetries = 0;
  function init() {
    if (typeof context === 'undefined') {
      if (++_initRetries > 100) return;
      return setTimeout(init, 50);
    }

    var extConfig = context.extensions && context.extensions['Extension Manager'];
    if (!extConfig || !extConfig.configuration) return;

    installed = extConfig.configuration.installed || {};

    if (!isExtensionsPage()) return;

    addInstallFromUrl();
    addRemoveToInstalledList();
    addButtonsToCommunityTable();
  }

  function isExtensionsPage() {
    return window.location.search.includes('c=extension') &&
           !window.location.search.includes('a=configure');
  }

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

    return fetch('./?c=extmgr&a=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin',
      redirect: 'manual',
    }).then(function (r) {
      if (r.type === 'opaqueredirect' || r.status === 0) {
        return { error: 'Request redirected — controller not found. Try disabling and re-enabling Extension Manager.' };
      }
      var contentType = r.headers.get('content-type') || '';
      if (contentType.indexOf('json') === -1) {
        return r.text().then(function (text) {
          return { error: 'Unexpected response (not JSON): ' + text.substring(0, 200) };
        });
      }
      return r.json();
    });
  }

  function showNotification(msg, isError) {
    // Use FreshRSS's built-in notification system
    var notif = document.getElementById('notification');
    if (notif) {
      notif.className = isError ? 'notification error' : 'notification good';
      notif.querySelector('.notification-text, p, span')
        ? (notif.querySelector('.notification-text, p, span').textContent = msg)
        : (notif.textContent = msg);
      notif.classList.remove('closed');
      setTimeout(function () { notif.classList.add('closed'); }, 4000);
      return;
    }
    // Fallback
    var el = document.createElement('div');
    el.textContent = msg;
    el.className = 'ext-mgr-notif ' + (isError ? 'ext-mgr-notif-error' : 'ext-mgr-notif-ok');
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 4000);
  }

  function addInstallFromUrl() {
    var container = document.createElement('div');
    container.className = 'ext-mgr-url-install';

    var input = document.createElement('input');
    input.type = 'url';
    input.placeholder = 'https://github.com/user/repo';
    input.className = 'ext-mgr-url-input';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ext-mgr-btn ext-mgr-install';
    btn.textContent = 'Install from URL';

    btn.addEventListener('click', function () {
      var url = input.value.trim();
      if (!url) return;
      if (!/^https:\/\/github\.com\/[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+/.test(url)) {
        showNotification('Only GitHub repository URLs are supported', true);
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Installing...';
      apiCall('install', { url: url }).then(function (data) {
        if (data.success) {
          btn.textContent = '\u2713 Done';
          btn.className = 'ext-mgr-btn ext-mgr-done';
          input.value = '';
          showNotification('Extension installed');
          setTimeout(function () { window.location.reload(); }, 1500);
        } else {
          btn.textContent = 'Install from URL';
          btn.disabled = false;
          showNotification(data.error || 'Failed', true);
        }
      }).catch(function (err) {
        btn.textContent = 'Install from URL';
        btn.disabled = false;
        showNotification('Error: ' + err.message, true);
      });
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') btn.click();
    });

    container.appendChild(input);
    container.appendChild(btn);

    // Insert before the community extensions table
    var tables = document.querySelectorAll('table');
    for (var i = 0; i < tables.length; i++) {
      var firstHeader = tables[i].querySelector('th');
      if (firstHeader && firstHeader.textContent.trim() === 'Name') {
        tables[i].parentNode.insertBefore(container, tables[i]);
        return;
      }
    }
    // Fallback: insert at end of main content
    var main = document.querySelector('.post') || document.querySelector('#content') || document.body;
    main.appendChild(container);
  }

  function addRemoveToInstalledList() {
    var listItems = document.querySelectorAll('ul li');
    listItems.forEach(function (li) {
      var configLink = li.querySelector('a[href*="a=configure"]');
      if (!configLink) return;

      var href = configLink.getAttribute('href');
      var match = href.match(/e=([^&]+)/);
      if (!match) return;
      var extName = decodeURIComponent(match[1]).replace(/\+/g, ' ');

      var dirName = null;
      for (var dir in installed) {
        if (installed[dir].name === extName) {
          dirName = dir;
          break;
        }
      }

      if (!dirName || dirName === 'xExtension-ExtensionManager') return;

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ext-mgr-trash';
      btn.title = 'Remove ' + extName;
      btn.innerHTML = '<img class="icon" src="../themes/icons/close.svg" loading="lazy" alt="Remove">';
      btn.addEventListener('click', function () {
        if (!confirm('Remove ' + extName + '?')) return;
        btn.disabled = true;
        apiCall('remove', { dir: dirName }).then(function (data) {
          if (data.success) {
            li.remove();
            showNotification(extName + ' removed');
            setTimeout(function () { window.location.reload(); }, 1500);
          } else {
            showNotification(data.error || 'Failed to remove', true);
            btn.disabled = false;
          }
        }).catch(function (err) {
          showNotification('Error: ' + err.message, true);
          btn.disabled = false;
        });
      });

      configLink.parentNode.insertBefore(btn, configLink.nextSibling);
    });
  }

  function addButtonsToCommunityTable() {
    var tables = document.querySelectorAll('table');
    var table = null;
    for (var i = 0; i < tables.length; i++) {
      var firstHeader = tables[i].querySelector('th');
      if (firstHeader && firstHeader.textContent.trim() === 'Name') {
        table = tables[i];
        break;
      }
    }
    if (!table) return;

    var headerRow = table.querySelector('thead tr') || table.querySelector('tr');
    if (headerRow) {
      var th = document.createElement('th');
      th.textContent = 'Actions';
      headerRow.appendChild(th);
    }

    var installedByName = {};
    for (var dir in installed) {
      installedByName[installed[dir].name] = {
        dir: dir,
        version: String(installed[dir].version),
      };
    }

    var rows = table.querySelectorAll('tbody tr');
    if (!rows.length) rows = table.querySelectorAll('tr:not(:first-child)');

    rows.forEach(function (row) {
      var cells = row.querySelectorAll('td');
      if (cells.length < 4) return;

      var nameLink = cells[0].querySelector('a');
      if (!nameLink) return;

      var extUrl = nameLink.getAttribute('href');
      var extName = nameLink.textContent.trim();
      var catalogVersion = cells[1].textContent.trim();
      var td = document.createElement('td');
      var info = installedByName[extName];

      if (info) {
        if (catalogVersion && info.version && catalogVersion !== info.version) {
          td.appendChild(makeActionButton('Update', 'ext-mgr-update', extUrl, extName));
        } else {
          var badge = document.createElement('span');
          badge.className = 'ext-mgr-installed-badge';
          badge.textContent = '\u2713 Installed';
          td.appendChild(badge);
        }
      } else if (extUrl) {
        td.appendChild(makeActionButton('Install', 'ext-mgr-install', extUrl, extName));
      }

      row.appendChild(td);
    });
  }

  function makeActionButton(label, className, extUrl, extName) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ext-mgr-btn ' + className;
    btn.textContent = label;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.textContent = label === 'Install' ? 'Installing...' : 'Updating...';
      apiCall('install', { url: extUrl }).then(function (data) {
        if (data.success) {
          btn.textContent = '\u2713 Done';
          btn.className = 'ext-mgr-btn ext-mgr-done';
          showNotification(extName + ' ' + (label === 'Install' ? 'installed' : 'updated'));
          // Reload page after delay to refresh extension list
          setTimeout(function () { window.location.reload(); }, 1500);
        } else {
          btn.textContent = label;
          btn.disabled = false;
          showNotification(data.error || 'Failed', true);
        }
      }).catch(function (err) {
        btn.textContent = label;
        btn.disabled = false;
        showNotification('Error: ' + err.message, true);
      });
    });
    return btn;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
