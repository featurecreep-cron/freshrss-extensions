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

FreshRSS keeps the extensions directory read-only at runtime. Extension Manager needs write access to install extensions, so you need to pick one of two approaches.

**Writable mode** — Bind-mount and `chmod` the extensions directory. Installs happen immediately. This means the web server process can write arbitrary PHP into a directory FreshRSS auto-loads and executes. A vulnerability in FreshRSS or any extension becomes a code execution vector.

**Queue mode** — Extensions directory stays read-only. Installs are staged to the data directory. Run a single command to apply them. No compose changes, no restart.

#### Writable mode

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

To run automatically when the container is created, add a `post_start` hook (Compose v2.30+):

```yaml
services:
  freshrss:
    post_start:
      - command: sh /var/www/FreshRSS/extensions/xExtension-ExtensionManager/install-queued.sh
        user: root
```

Note: `post_start` fires on container creation (`docker compose up`, image updates, `--force-recreate`), not on `docker compose restart`. For queued extensions to be applied after a restart, run the `docker exec` command above.

## Configuration

Settings → Extensions → Extension Manager → Configure. Add GitHub repository URLs (one per line) as extension sources.

## Compatibility

Requires FreshRSS 1.20+.
