# Contributing

Contributions are welcome. Here's how to get started.

## Development setup

1. Fork this repo
2. Clone your fork
3. Copy the extension you're working on into your FreshRSS `extensions/` directory
4. Make changes and reload the FreshRSS page to test

Each `xExtension-*` directory is self-contained. No build step required — PHP and JS run directly.

## Extension structure

Every extension needs:

```
xExtension-Name/
  metadata.json     # Name, version, entrypoint, description
  extension.php     # PHP class extending Minz_Extension
  static/
    script.js       # Client-side behavior
    style.css       # Styles (optional)
  configure.phtml   # Settings UI (optional)
```

## Code style

- Plain JavaScript (no frameworks, no transpilation)
- PHP compatible with FreshRSS's minimum PHP version
- IIFE pattern for JS to avoid global scope pollution
- Use FreshRSS's built-in hooks and APIs where possible

## Testing

Test against the current stable FreshRSS release. Note which version and browser you tested with in your PR.

## Versioning

`metadata.json` is the source of truth for every version surface, and it is the
one that actually reaches users.

The FreshRSS community catalog regenerates itself daily by cloning this
repository's default branch and reading each extension's `metadata.json`. Nobody
publishes to it by hand. Extension Manager then compares the catalog version
against the installed one and only offers an update when the catalog version is
greater. So an extension change that ships without a version bump reaches `main`,
reaches the catalog, and is never offered to anyone who already has it installed.

**You don't need to touch versions.** Leave `metadata.json` alone in your pull
request; bumping is a maintainer job and it is automated.

Pull requests target `develop`. When `develop` is promoted to `main`, the
Promote workflow bumps every extension whose shipped files changed since `main`
(Markdown doesn't count), commits that to `develop`, and opens the promotion
pull request. The bump level comes from the commit messages that touched each
extension:

| Commit | Bump |
|---|---|
| a `!` after the type (`feat!:`, `fix(scope)!:`) or a `BREAKING CHANGE:` footer | major |
| `feat:` | minor |
| anything else, including a message that isn't a conventional commit | patch |

When a maintainer squash-merges a pull request, the squash title is the message
that counts, so it is worth giving it the right type.

A maintainer who wants a bigger bump than that can raise the version on
`develop` by hand; promotion keeps any version that is already at or above what
it would have written. The Version Gate check on pull requests to `main` is the
backstop: it fails if an extension changed without its version increasing. If a
change genuinely ships nothing to users, a maintainer can apply the
`no-version-bump` label.

Promotion runs on demand and every Monday, so nothing on `develop` waits more
than a week to ship.

## Releases

Releases are cut automatically and need no manual step. When `main` moves, the
Release workflow compares every `metadata.json` against the last tag. If no
version increased, nothing shipped and no release is cut. If any did, it tags a
new umbrella version — minor if any extension took a minor or major bump, patch
otherwise — and publishes notes grouped by extension.

The tag is a changelog, not an install source. Installs come from the catalog or
from cloning the repository, so a release never gates whether a fix reaches
users; the version bump does.

## Pull requests

- One extension per PR unless changes are tightly coupled
- Describe what the change does and why
- Target the `develop` branch
- Include the FreshRSS version and browser you tested with
