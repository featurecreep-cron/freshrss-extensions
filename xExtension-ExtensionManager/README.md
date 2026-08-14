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

### Switching branches

In a section whose branch is not the one the installed copy came from, the action is **Switch to `<branch>`** instead of Install or Update. It replaces the installed copy with that section's build and records the new origin, so testing from `develop` and returning to `main` is a single click in each direction.

The switch is offered on the difference in origin, not on version order — you can move back to a branch that is behind the one you are on, which is the usual case after testing something on `develop`. Because that can be a downgrade, the button is coloured differently from Install and Update.

An extension installed before source tracking existed has no recorded origin. Those keep the old behaviour — Update when a newer version is available — since an install that cannot be placed is not evidence that it came from somewhere else.

## Updating Extension Manager itself

Extension Manager cannot copy a new version over the directory it is running from: FreshRSS boots every extension on every request, and a recursive copy has a visible half-written state that would fatal the whole site rather than just this page. So its own update is two steps, each named for what it does.

**Download update** fetches the new version and builds it alongside the running one, then verifies it before it is allowed anywhere near activation:

- the manifest parses and its `entrypoint` resolves to `ExtensionManagerExtension`
- every `.php` file is parsed with `token_get_all(..., TOKEN_PARSE)` — a truncated download or a bad commit is caught here, not at the next request's `include`
- `Controllers/`, `static/` and `views/` are present

A staged copy holds its manifest as `metadata.json.pending`, so FreshRSS's extension scan cannot see it. Nothing about the running install has changed at this point, and a staged copy that is never applied is inert.

**Apply update** swaps them at the start of the next request, before any controller or view is loaded, then reloads into the new version. The swap is renames only:

```
live    -> .extmgr-rollback-<timestamp>   (and its manifest renamed away)
staged  -> live
staged manifest -> metadata.json          (last: the new version becomes visible)
```

At no point do two directories carry a valid manifest, so the class is never declared twice. Every intermediate state is "Extension Manager is momentarily missing", which FreshRSS renders without complaint and the next request repairs. The previous version stays on disk as `.extmgr-rollback-<timestamp>` until the next update.

Applying is deliberately separate from staging so that merely browsing never swaps the code out from under you. A staged copy you have changed your mind about is removed with **Discard**, which deletes it and leaves the running version untouched.

**What this cannot do:** if a staged copy is broken in a way the verifier does not catch, the code that would roll it back is the code that is broken. That is why verification happens before the swap and why the previous version is kept — recovery is one `mv`, not a reinstall.

If the extensions directory is not writable, the update is queued instead and `install-queued.sh` performs the same swap out of band.

## Compatibility

Requires FreshRSS 1.20+.
