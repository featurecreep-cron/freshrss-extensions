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

**Queue mode** — Extensions directory stays read-only. Installs are staged to the data directory and applied on next container restart via an entrypoint wrapper. Every install requires a restart (a few seconds).

#### Writable mode

Bind-mount the extensions directory, then make it group-writable:

```yaml
services:
  freshrss:
    volumes:
      - ./extensions:/var/www/FreshRSS/extensions
```

On the host, from the directory containing your `docker-compose.yml`:

```bash
chmod -R g+w extensions
```

#### Queue mode

Override your container entrypoint in `docker-compose.yml`:

```yaml
services:
  freshrss:
    entrypoint: /var/www/FreshRSS/extensions/xExtension-ExtensionManager/install-queued.sh
```

Processes queued installs at startup, then execs the original entrypoint.

## Configuration

Settings → Extensions → Extension Manager → Configure. Add GitHub repository URLs (one per line) as extension sources.

## Compatibility

Requires FreshRSS 1.20+.
