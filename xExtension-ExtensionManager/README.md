# Extension Manager

Install, update, and remove FreshRSS extensions from the settings page.

## Features

- Browse and install extensions from GitHub repositories
- One-click install, update, and remove
- Two install modes: immediate or queued (see below)
- Automatic rollback on failed installs

## Installation

Drop `xExtension-ExtensionManager` into your FreshRSS `extensions/` directory. Enable in Settings → Extensions.

### Install modes

#### Writable mode

> **Warning:** This makes the extensions directory writable by the web server. A vulnerability in FreshRSS or any extension becomes a code execution vector.

Bind-mount the internal extensions directory to a host path and make it group-writable. If you don't already have a bind mount for extensions, copy the existing ones out first:

```bash
mkdir -p ./freshrss-extensions
docker cp freshrss:/var/www/FreshRSS/extensions/. ./freshrss-extensions/
chmod -R g+w ./freshrss-extensions
```

```yaml
services:
  freshrss:
    volumes:
      - ./freshrss-extensions:/var/www/FreshRSS/extensions
```

#### Queue mode

No setup required. When the extensions directory is read-only, Extension Manager automatically queues installs and removals. Apply them with:

```bash
docker exec freshrss sh /var/www/FreshRSS/extensions/xExtension-ExtensionManager/install-queued.sh
```

Refresh FreshRSS in your browser after running.

## Configuration

Settings → Extensions → Extension Manager → Configure. Add GitHub repository URLs (one per line) as extension sources.

### Tracking a branch

Append `/tree/<branch>` to install from somewhere other than the repository's default branch:

```
https://github.com/user/repo
https://github.com/user/repo/tree/develop
https://github.com/user/repo/tree/fix/some-bug
```

That is the URL GitHub shows in the address bar when you switch branches, so it can be pasted directly. Branch names containing slashes work.

The same repository may be listed more than once on different branches. Each gets its own section on the Extensions page, labelled with the branch, so it is clear which one an Install button will use.

Only one copy of a given extension can be installed at a time. When the installed copy came from a different branch than the section you are looking at, the Installed column shows that branch next to the version — so a build from `develop` is never mistaken for one from `main`.

A branch that does not exist is reported as an error. It is never silently replaced with the default branch, because that would install code you did not ask for and report success.

## Compatibility

Requires FreshRSS 1.20+.
