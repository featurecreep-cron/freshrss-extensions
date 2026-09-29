"""Fail a pull request that changes an extension without bumping its version.

Existing users only see an update when the catalog version compares greater
than what they have installed, so an unbumped metadata.json means the fix
reaches main, reaches the catalog, and never reaches anybody's browser.

Contributors never bump versions — the Promote workflow does, on develop, just
before it opens the develop -> main pull request. This gate is the backstop that
proves it did, and catches a pull request opened straight against main.

Usage: version_gate.py <base-ref> <head-ref>
"""

import sys

from versions import changed_files, display_name, is_increase, shipping_changes, version_at


def main() -> int:
    base, head = sys.argv[1], sys.argv[2]
    triggered = shipping_changes(changed_files(base, head))

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
    print("Promotion bumps these automatically: run the Promote workflow rather")
    print("than targeting main directly. A maintainer can apply the")
    print("'no-version-bump' label if this genuinely ships nothing to users.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
