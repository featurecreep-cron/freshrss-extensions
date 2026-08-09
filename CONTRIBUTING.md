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

Because of that, **any change to files inside `xExtension-Name/` must bump that
extension's `metadata.json` version in the same pull request.** Markdown files
and `metadata.json` itself are exempt. The Version Gate check enforces this; if a
change genuinely ships nothing to users, a maintainer can apply the
`no-version-bump` label to skip it.

Use semver per extension: patch for fixes, minor for new behavior or settings.

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
- Include the FreshRSS version and browser you tested with
- Bump the extension's version in `metadata.json`
