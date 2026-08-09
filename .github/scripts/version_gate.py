"""Fail a pull request that changes an extension without bumping its version.

Existing users only see an update when the catalog version compares greater
than what they have installed, so an unbumped metadata.json means the fix
reaches main, reaches the catalog, and never reaches anybody's browser.

Usage: version_gate.py <base-ref> <head-ref>
"""

import sys

from versions import display_name, is_increase, version_at, git

# A version bump is only demanded for changes users would actually receive.
# Prose is exempt, and metadata.json is where the bump itself lives.
EXEMPT_SUFFIXES = (".md",)
EXEMPT_NAMES = ("metadata.json",)


def changed_files(base: str, head: str) -> list[str]:
    diff = git("diff", "--name-only", f"{base}...{head}")
    return [line for line in diff.splitlines() if line]


def triggering_extensions(paths: list[str]) -> dict[str, list[str]]:
    """Map each extension directory to the substantive files changed inside it."""
    triggered: dict[str, list[str]] = {}
    for path in paths:
        parts = path.split("/")
        if len(parts) < 2 or not parts[0].startswith("xExtension-"):
            continue
        filename = parts[-1]
        if filename.endswith(EXEMPT_SUFFIXES) or filename in EXEMPT_NAMES:
            continue
        triggered.setdefault(parts[0], []).append(path)
    return triggered


def main() -> int:
    base, head = sys.argv[1], sys.argv[2]
    triggered = triggering_extensions(changed_files(base, head))

    if not triggered:
        print("No extension code changed. Nothing to gate.")
        return 0

    failures = []
    for directory in sorted(triggered):
        old = version_at(base, directory)
        new = version_at(head, directory)

        if new is None:
            # Extension removed in this PR; there is nothing left to version.
            continue
        if old is None:
            print(f"{display_name(directory, head)}: new extension at {new} — exempt.")
            continue
        if is_increase(old, new):
            print(f"{display_name(directory, head)}: {old} -> {new} — ok.")
            continue

        failures.append((directory, old, new, triggered[directory]))

    if not failures:
        return 0

    print()
    print("Version bump missing. These extensions changed but their")
    print("metadata.json version did not increase:")
    for directory, old, new, files in failures:
        state = f"still {old}" if old == new else f"{old} -> {new} (not an increase)"
        print(f"\n  {display_name(directory, head)} ({directory}): {state}")
        for path in sorted(files):
            print(f"      {path}")
    print()
    print("Bump the version in each metadata.json above, or apply the")
    print("'no-version-bump' label if this genuinely ships nothing to users.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
