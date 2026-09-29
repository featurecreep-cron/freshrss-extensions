# Releasing

Maintainer notes. Contributors never touch versions, and nothing here belongs in
a pull request review.

## Why versions matter

`metadata.json` is the source of truth for every version surface, and it is the
one that actually reaches users.

The FreshRSS community catalog regenerates itself daily by cloning this
repository's default branch and reading each extension's `metadata.json`. Nobody
publishes to it by hand. Extension Manager then compares the catalog version
against the installed one and only offers an update when the catalog version is
greater. So an extension change that reaches `main` without a version bump
reaches the catalog and is never offered to anyone who already has it installed.

## Flow

1. Pull requests merge into `develop`. A pull request opened against `main`
   is moved to `develop` automatically, with a comment, so contributors never
   need to know about the split.
2. Test on `develop` for as many passes as it takes. Extension Manager can
   install an extension from the `develop` branch for exactly this.
3. When `develop` is ready, **open a pull request from `develop` to `main`**.
   That is the decision to ship. Actions → Promote → Run workflow is a shortcut
   that opens it for you.
4. On that pull request, Promote pushes a commit to `develop` bumping every
   extension whose shipped files changed since `main`. The pull request picks
   it up, CI re-runs, and auto-merge lands it once the checks are green.
5. When `main` moves, the Release workflow tags and publishes release notes.

## Why this is the only route

`main` is the default branch because the FreshRSS catalog clones the default
branch and nothing else, and Extension Manager downloads from `main`. If the two
disagreed, the catalog would advertise a version that Extension Manager then
failed to install, and offer it again forever.

`main` accepts nothing but a pull request from this repository's `develop`:

- **Promotion source** (required) fails any other head.
- **Extension version bumped** (required) fails until the bump commit lands,
  which is also what stops auto-merge from racing the bump.
- PHP lint and the release-script tests are required.
- No bypass, for admins included. A hotfix goes through `develop` like
  everything else; the promotion pull request only waits on CI.

## Bump level

Promote reads the commit messages that touched each extension since `main`:

| Commit | Bump |
|---|---|
| a `!` after the type (`feat!:`, `fix(scope)!:`) or a `BREAKING CHANGE:` footer | major |
| `feat:` | minor |
| anything else, including a message that isn't a conventional commit | patch |

Markdown-only changes don't count. When squash-merging a contributor's pull
request, the squash title is the message that counts, so give it the right type.

For a bigger bump than that, raise the version on `develop` by hand before
promoting; Promote keeps any version already at or above what it would have
written. A malformed version (anything but `MAJOR.MINOR.PATCH`) stops the run
rather than being guessed at.

The Version Gate check on pull requests to `main` is the backstop: it fails if an
extension changed without its version increasing. If a change genuinely ships
nothing to users, apply the `no-version-bump` label.

## Releases

Releases are cut automatically when `main` moves. The Release workflow compares
every `metadata.json` against the last tag. If no version increased, nothing
shipped and no release is cut. If any did, it tags a new umbrella version —
minor if any extension took a minor or major bump, patch otherwise — and
publishes notes grouped by extension.

The tag is a changelog, not an install source. Installs come from the catalog or
from cloning the repository, so a release never gates whether a fix reaches
users; the version bump does.
