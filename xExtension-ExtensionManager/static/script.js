'use strict';

(function () {
  var installed = {};
  var repos = [];
  var isAdmin = false;
  var isWritable = false;
  var queued = {};


  var _initRetries = 0;
  function init() {
    if (typeof context === 'undefined') {
      if (++_initRetries > 100) return;
      return setTimeout(init, 50);
    }

    var extConfig = context.extensions && context.extensions['Extension Manager'];
    if (!extConfig || !extConfig.configuration) return;

    installed = extConfig.configuration.installed || {};
    repos = extConfig.configuration.repos || [];
    isAdmin = !!extConfig.configuration.is_admin;
    isWritable = !!extConfig.configuration.writable;
    queued = extConfig.configuration.queued || {};

    if (!isExtensionsPage()) return;

    if (isAdmin) {
      addRemoveToInstalledList();
    }
    addButtonsToCommunityTable();
    if (isAdmin) {
      addRepoInput();
    }
    if (repos.length > 0) {
      loadRepoCatalogs();
    }
    if (Object.keys(queued).length > 0) {
      showQueuedBanner();
    }
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

  function apiGet(action, params) {
    var qs = new URLSearchParams(params);
    return fetch('./?c=extmgr&a=' + action + '&' + qs.toString(), {
      credentials: 'same-origin',
      redirect: 'manual',
    }).then(function (r) {
      if (r.type === 'opaqueredirect' || r.status === 0) {
        return { error: 'Request redirected — controller not found.' };
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
    var el = document.createElement('div');
    el.textContent = msg;
    el.className = 'ext-mgr-notif ' + (isError ? 'ext-mgr-notif-error' : 'ext-mgr-notif-ok');
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 4000);
  }

  function refreshQueuedBanner() {
    var existing = document.querySelector('.ext-mgr-queued-banner');
    if (existing) existing.remove();
    showQueuedBanner(true);
  }

  function appendBanner(banner, scroll) {
    var main = document.querySelector('.post') || document.querySelector('#content') || document.body;
    main.insertBefore(banner, main.firstChild);
    if (scroll) {
      banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function showQueuedBanner(scroll) {
    var installs = [];
    var removals = [];
    Object.keys(queued).forEach(function (k) {
      var entry = queued[k];
      var name = entry.name || k;
      if (entry.action === 'remove') {
        removals.push(name);
      } else {
        installs.push(name);
      }
    });

    var banner = document.createElement('div');
    banner.className = 'ext-mgr-queued-banner';

    var title = document.createElement('strong');
    title.textContent = 'Extensions queued:';
    banner.appendChild(title);

    if (installs.length > 0) {
      banner.appendChild(document.createTextNode(' Install: ' + installs.join(', ')));
    }
    if (removals.length > 0) {
      if (installs.length > 0) banner.appendChild(document.createTextNode(';'));
      banner.appendChild(document.createTextNode(' Remove: ' + removals.join(', ')));
    }
    banner.appendChild(document.createElement('br'));

    var explanation = document.createElement('span');
    explanation.className = 'ext-mgr-queued-detail';
    explanation.textContent = 'Apply with: ';
    banner.appendChild(explanation);

    var cmd = document.createElement('code');
    cmd.textContent = 'docker exec freshrss sh /var/www/FreshRSS/extensions/xExtension-ExtensionManager/install-queued.sh';
    cmd.style.cssText = 'font-size: 0.85em; user-select: all;';
    banner.appendChild(cmd);

    banner.appendChild(document.createElement('br'));
    var setupLink = document.createElement('a');
    setupLink.href = 'https://github.com/featurecreep-cron/freshrss-extensions/blob/main/xExtension-ExtensionManager/README.md#install-modes';
    setupLink.target = '_blank';
    setupLink.textContent = 'Other install modes';
    setupLink.style.cssText = 'font-size: 0.85em;';
    banner.appendChild(setupLink);

    appendBanner(banner, scroll);
  }

  function addRepoInput() {
    var container = document.createElement('div');
    container.className = 'ext-mgr-add-repo';

    var input = document.createElement('input');
    input.type = 'url';
    input.placeholder = 'https://github.com/user/repo';
    input.className = 'ext-mgr-url-input';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ext-mgr-btn ext-mgr-install';
    btn.textContent = 'Add repository';

    btn.addEventListener('click', function () {
      var url = input.value.trim();
      if (!url) return;
      if (!/^https:\/\/github\.com\/[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/.test(url)) {
        showNotification('Only GitHub repository URLs are supported', true);
        return;
      }

      // Don't add duplicates
      if (repos.indexOf(url) !== -1) {
        showNotification('Repository already added', true);
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Loading...';

      // Save the repo to config via POST to configure
      repos.push(url);
      var saveBody = new URLSearchParams();
      saveBody.append('_csrf', getCsrf());
      saveBody.append('repos', repos.join('\n'));
      fetch('./?c=extension&a=configure&e=Extension+Manager', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: saveBody.toString(),
        credentials: 'same-origin',
      }).then(function () {
        // Now load the catalog
        input.value = '';
        btn.textContent = 'Add repository';
        btn.disabled = false;
        loadSingleRepoCatalog(url);
        showNotification('Repository added');
      }).catch(function (err) {
        repos.pop();
        btn.textContent = 'Add repository';
        btn.disabled = false;
        showNotification('Failed to save: ' + err.message, true);
      });
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') btn.click();
    });

    container.appendChild(input);
    container.appendChild(btn);

    // Insert before the community extensions table or at end of content
    var main = document.querySelector('.post') || document.querySelector('#content') || document.body;
    var tables = document.querySelectorAll('table');
    for (var i = 0; i < tables.length; i++) {
      var firstHeader = tables[i].querySelector('th');
      if (firstHeader && firstHeader.textContent.trim() === 'Name') {
        tables[i].parentNode.insertBefore(container, tables[i]);
        return;
      }
    }
    main.appendChild(container);
  }

  function loadSingleRepoCatalog(repoUrl) {
    var main = document.querySelector('.post') || document.querySelector('#content') || document.body;

    var section = document.createElement('div');
    section.className = 'ext-mgr-repo-section';

    var heading = document.createElement('h3');
    heading.textContent = repoUrl.replace('https://github.com/', '');
    section.appendChild(heading);

    var loading = document.createElement('p');
    loading.textContent = 'Loading catalog...';
    loading.className = 'ext-mgr-loading';
    section.appendChild(loading);

    main.appendChild(section);

    apiGet('catalog', { url: repoUrl }).then(function (data) {
      loading.remove();
      if (data.error) {
        var err = document.createElement('p');
        err.textContent = 'Failed: ' + data.error;
        err.className = 'ext-mgr-error';
        section.appendChild(err);
        return;
      }
      var table = buildRepoTable(data.extensions, data.catalogToken);
      section.appendChild(table);
    }).catch(function (err) {
      loading.remove();
      var errEl = document.createElement('p');
      errEl.textContent = 'Error: ' + err.message;
      errEl.className = 'ext-mgr-error';
      section.appendChild(errEl);
    });
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
            if (data.queued) {
              btn.disabled = true;
              showNotification(extName + ' removal queued — run install-queued.sh to apply');
              queued[dirName] = { name: extName, action: 'remove' };
              refreshQueuedBanner();
            } else {
              li.remove();
              showNotification(extName + ' removed');
              setTimeout(function () { window.location.reload(); }, 1500);
            }
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

  function compareVersions(a, b) {
    // Strip pre-release suffix for numeric comparison (e.g., "0.3.3-diag1" → "0.3.3")
    var sa = String(a).replace(/-.*$/, '');
    var sb = String(b).replace(/-.*$/, '');
    var pa = sa.split('.').map(Number);
    var pb = sb.split('.').map(Number);
    var len = Math.max(pa.length, pb.length);
    for (var i = 0; i < len; i++) {
      var na = pa[i] || 0;
      var nb = pb[i] || 0;
      if (na > nb) return 1;
      if (na < nb) return -1;
    }
    // Same numeric version: release (no suffix) beats pre-release
    var aPre = String(a).indexOf('-') !== -1;
    var bPre = String(b).indexOf('-') !== -1;
    if (aPre && !bPre) return -1;
    if (!aPre && bPre) return 1;
    return 0;
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
      var versionTh = headerRow.querySelectorAll('th')[1];
      if (versionTh) {
        var installedTh = document.createElement('th');
        installedTh.textContent = 'Installed';
        versionTh.parentNode.insertBefore(installedTh, versionTh);
        versionTh.textContent = 'Latest';
      }
      var actionsTh = document.createElement('th');
      actionsTh.textContent = 'Actions';
      headerRow.appendChild(actionsTh);
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

      // Insert Installed column before Version
      var installedTd = document.createElement('td');
      var info = installedByName[extName];
      installedTd.textContent = info ? info.version : '';
      cells[1].parentNode.insertBefore(installedTd, cells[1]);

      var actionTd = document.createElement('td');

      if (info) {
        if (isAdmin && catalogVersion && info.version && compareVersions(catalogVersion, info.version) > 0) {
          actionTd.appendChild(makeInstallButton('Update', extUrl, extName, null, null));
        } else {
          var badge = document.createElement('span');
          badge.className = 'ext-mgr-installed-badge';
          badge.textContent = '\u2713 Installed';
          actionTd.appendChild(badge);
        }
      } else if (isAdmin && extUrl) {
        actionTd.appendChild(makeInstallButton('Install', extUrl, extName, null, null));
      }

      row.appendChild(actionTd);
    });
  }

  function loadRepoCatalogs() {
    repos.forEach(function (repoUrl) {
      loadSingleRepoCatalog(repoUrl);
    });
  }

  function buildRepoTable(extensions, catalogToken) {
    var table = document.createElement('table');
    table.className = 'ext-mgr-repo-table';

    var thead = document.createElement('thead');
    var headerRow = document.createElement('tr');
    ['Name', 'Installed', 'Latest', 'Description', 'Actions'].forEach(function (h) {
      var th = document.createElement('th');
      th.textContent = h;
      headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    var installedByName = {};
    for (var dir in installed) {
      installedByName[installed[dir].name] = {
        dir: dir,
        version: String(installed[dir].version),
      };
    }

    extensions.forEach(function (ext) {
      var tr = document.createElement('tr');

      var tdName = document.createElement('td');
      tdName.textContent = ext.name;
      tr.appendChild(tdName);

      var info = installedByName[ext.name];

      var tdInstalled = document.createElement('td');
      tdInstalled.textContent = info ? info.version : '';
      tr.appendChild(tdInstalled);

      var tdVersion = document.createElement('td');
      tdVersion.textContent = ext.version;
      tr.appendChild(tdVersion);

      var tdDesc = document.createElement('td');
      tdDesc.textContent = ext.description;
      tr.appendChild(tdDesc);

      var tdAction = document.createElement('td');

      if (ext.dir === 'xExtension-ExtensionManager') {
        var selfBadge = document.createElement('span');
        selfBadge.className = 'ext-mgr-installed-badge';
        selfBadge.textContent = '(self)';
        tdAction.appendChild(selfBadge);
      } else if (info) {
        if (isAdmin && compareVersions(String(ext.version), info.version) > 0) {
          tdAction.appendChild(makeInstallButton('Update', null, ext.name, ext.dir, catalogToken));
        } else {
          var badge = document.createElement('span');
          badge.className = 'ext-mgr-installed-badge';
          badge.textContent = '\u2713 Installed';
          tdAction.appendChild(badge);
        }
      } else if (isAdmin) {
        tdAction.appendChild(makeInstallButton('Install', null, ext.name, ext.dir, catalogToken));
      }

      tr.appendChild(tdAction);
      tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    return table;
  }

  function makeInstallButton(label, extUrl, extName, extDir, catalogToken) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ext-mgr-btn ' + (label === 'Update' ? 'ext-mgr-update' : 'ext-mgr-install');
    btn.textContent = label;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.textContent = label === 'Install' ? 'Installing...' : 'Updating...';

      var params = {};
      if (extDir && catalogToken) {
        params = { dir: extDir, catalogToken: catalogToken };
      } else if (extUrl) {
        params = { url: extUrl, name: extName };
      }

      apiCall('install', params).then(function (data) {
        if (data.success) {
          if (data.queued) {
            btn.textContent = 'Queued';
            btn.className = 'ext-mgr-btn ext-mgr-queued';
            showNotification(extName + ' queued — see banner for apply command');
            // Update queued state and show banner if not already visible
            queued[extDir || extName] = { name: extName, action: 'install' };
            refreshQueuedBanner();
          } else {
            btn.textContent = '\u2713 Done';
            btn.className = 'ext-mgr-btn ext-mgr-done';
            showNotification(extName + ' ' + (label === 'Install' ? 'installed' : 'updated'));
            setTimeout(function () { window.location.reload(); }, 1500);
          }
        } else {
          btn.textContent = label;
          btn.disabled = false;
          showManualUpdateInstructions(extName, extUrl, extDir, data.error);
        }
      }).catch(function (err) {
        btn.textContent = label;
        btn.disabled = false;
        showManualUpdateInstructions(extName, extUrl, extDir, err.message);
      });
    });
    return btn;
  }

  function showManualUpdateInstructions(extName, extUrl, extDir, serverError) {
    var dirName = extDir || ('xExtension-' + extName);
    var repoUrl = extUrl || '';

    var overlay = document.createElement('div');
    overlay.className = 'ext-mgr-overlay';

    var dialog = document.createElement('div');
    dialog.className = 'ext-mgr-dialog';
    dialog.setAttribute('role', 'dialog');

    var title = document.createElement('h3');
    title.textContent = 'Install ' + extName + ' failed';
    dialog.appendChild(title);

    if (serverError) {
      var errBox = document.createElement('pre');
      errBox.className = 'ext-mgr-dialog-error';
      errBox.textContent = serverError;
      dialog.appendChild(errBox);
    }

    var intro = document.createElement('p');
    intro.textContent = 'You can install manually via the command line:';
    dialog.appendChild(intro);

    var pre = document.createElement('pre');
    var extPath = '/path/to/freshrss/extensions/' + dirName;
    var lines = [];
    if (repoUrl) {
      lines.push('# If installed via git:');
      lines.push('cd ' + extPath);
      lines.push('git pull origin main');
      lines.push('');
      lines.push('# Or replace manually:');
      lines.push('rm -rf ' + extPath);
      lines.push('cd /path/to/freshrss/extensions/');
      lines.push('git clone ' + repoUrl + '.git ' + dirName);
    } else {
      lines.push('cd ' + extPath);
      lines.push('git pull origin main');
    }
    pre.textContent = lines.join('\n');
    dialog.appendChild(pre);

    var note = document.createElement('p');
    note.className = 'ext-mgr-dialog-note';
    note.textContent = 'Refresh FreshRSS in your browser after updating.';
    dialog.appendChild(note);

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'ext-mgr-btn';
    closeBtn.textContent = 'Close';
    closeBtn.addEventListener('click', function () { overlay.remove(); });
    dialog.appendChild(closeBtn);

    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
