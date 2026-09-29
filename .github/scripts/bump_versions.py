"""Bump the metadata.json version of every extension that changed since main.

Nobody who sends a pull request should have to know that the FreshRSS catalog
only offers an update when metadata.json compares greater. So contributors
never touch versions: the Promote workflow runs this on develop, commits the
result, and only then opens the develop -> main pull request.

The bump level comes from the conventional-commit types of the commits that
touched each extension since main, as documented in docs/releasing.md:

    breaking change (`type!:` or a `BREAKING CHANGE:` footer)  -> major
    feat                                                        -> minor
    anything else, including a subject that is not conventional -> patch

Patch is the floor, not a guess: an extension whose shipped files changed
always gets at least a patch, or the change never reaches existing installs.

A version already raised on develop is kept when it is at least the computed
target, so a maintainer can bump further by hand. A lower one is raised to the
target, which is also what makes a second run safe: a feat landing after a
patch bump turns the pending patch into a minor instead of stacking another.

Usage: bump_versions.py <base-ref> <head-ref> [--write]

Without --write it prints the plan and changes nothing. With --write it
rewrites each metadata.json in the working tree. Emits `bumped=true|false` and
`summary` (a one-line commit subject) to $GITHUB_OUTPUT when set.
"""

import json
import os
import re
import sys

from versions import (
    METADATA,
    changed_files,
    display_name,
    git,
    parse,
    shipping_changes,
    version_at,
)

LEVELS = ("patch", "minor", "major")

# type(scope)!: subject — scope optional, `!` marks a breaking change.
CONVENTIONAL = re.compile(r"^(?P<type>[a-z]+)(?:\([^)]*\))?(?P<breaking>!)?:\s")
BREAKING_FOOTER = re.compile(r"^BREAKING[ -]CHANGE:", re.MULTILINE)
STRICT_SEMVER = re.compile(r"^\d+\.\d+\.\d+$")

# Subject prefix of the commit the Promote workflow makes. plan_release.py
# drops these from release notes; here they need no special case, because a
# chore only ever asks for a patch and so can never raise the level.
BUMP_SUBJECT_PREFIX = "chore(release):"


def commit_level(subject: str, body: str) -> str:
    """The bump a single commit asks for."""
    match = CONVENTIONAL.match(subject)
    if (match and match.group("breaking")) or BREAKING_FOOTER.search(body):
        return "major"
    if match and match.group("type") == "feat":
        return "minor"
    return "patch"


def commits_touching(base: str, head: str, directory: str) -> list[tuple[str, str]]:
    """(subject, body) of every non-merge commit on head, not on base, touching directory."""
    # NUL between fields and RS between records: subjects and bodies can hold
    # anything else, including tabs and newlines.
    log = git(
        "log", "--no-merges", "--format=%s%x00%b%x1e",
        f"{base}..{head}", "--", directory,
    )
    commits = []
    for record in log.split("\x1e"):
        record = record.strip("\n")
        if not record:
            continue
        subject, _, body = record.partition("\x00")
        commits.append((subject, body))
    return commits


def required_level(commits: list[tuple[str, str]]) -> str:
    """The largest bump any commit asks for; patch when there are none."""
    levels = [commit_level(subject, body) for subject, body in commits]
    return max(levels, key=LEVELS.index, default="patch")


def bumped(version: str, level: str) -> str:
    major, minor, patch = parse(version)
    if level == "major":
        return f"{major + 1}.0.0"
    if level == "minor":
        return f"{major}.{minor + 1}.0"
    return f"{major}.{minor}.{patch + 1}"


def plan(base: str, head: str) -> tuple[dict[str, tuple[str, str, str]], list[str]]:
    """Work out every bump.

    Returns ({directory: (old, new, level)}, [problems]). An extension appears
    in the plan only when its metadata.json has to change.
    """
    bumps: dict[str, tuple[str, str, str]] = {}
    problems: list[str] = []

    for directory in sorted(shipping_changes(changed_files(base, head))):
        old = version_at(base, directory)
        current = version_at(head, directory)

        if current is None:
            continue  # removed on head; nothing left to version
        if old is None:
            print(f"{display_name(directory, head)}: new extension at {current}; kept.")
            continue
        if not STRICT_SEMVER.match(old) or not STRICT_SEMVER.match(current):
            # parse() reads a malformed version as zeros, which is safe for a
            # comparison and wrong for arithmetic. Refuse rather than write a
            # version derived from a guess.
            problems.append(
                f"{directory}: version {old!r} on base or {current!r} on head "
                "is not MAJOR.MINOR.PATCH; fix it by hand"
            )
            continue

        level = required_level(commits_touching(base, head, directory))
        target = bumped(old, level)
        name = display_name(directory, head)

        if parse(current) >= parse(target):
            print(f"{name}: already {current} (needs at least {target}); kept.")
            continue

        print(f"{name}: {old} -> {target} ({level})")
        bumps[directory] = (current, target, level)

    return bumps, problems


def write_version(directory: str, version: str) -> None:
    path = os.path.join(directory, METADATA)
    with open(path, encoding="utf-8") as handle:
        metadata = json.load(handle)
    metadata["version"] = version
    # Matches every existing metadata.json byte for byte: four-space indent,
    # literal non-ASCII (CompactReader's description has an em dash), and a
    # trailing newline. Key order survives because dicts keep insertion order.
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(json.dumps(metadata, indent=4, ensure_ascii=False) + "\n")


def summary(bumps: dict[str, tuple[str, str, str]], head: str) -> str:
    parts = [f"{display_name(d, head)} {new}" for d, (_, new, _) in sorted(bumps.items())]
    return f"{BUMP_SUBJECT_PREFIX} bump " + ", ".join(parts)


def emit(**values: str) -> None:
    output = os.environ.get("GITHUB_OUTPUT")
    if not output:
        return
    with open(output, "a", encoding="utf-8") as handle:
        for key, value in values.items():
            handle.write(f"{key}={value}\n")


def main_with(argv: list[str]) -> int:
    args = [arg for arg in argv if arg != "--write"]
    if len(args) != 2:
        print("usage: bump_versions.py <base-ref> <head-ref> [--write]", file=sys.stderr)
        return 2
    base, head = args
    write = "--write" in argv

    bumps, problems = plan(base, head)

    if problems:
        print()
        for problem in problems:
            print(f"::error::{problem}")
        return 1

    if not bumps:
        print("No version needs to change.")
        emit(bumped="false")
        return 0

    if write:
        for directory, (_, new, _) in bumps.items():
            write_version(directory, new)

    emit(bumped="true", summary=summary(bumps, head))
    return 0


def main() -> int:
    return main_with(sys.argv[1:])


if __name__ == "__main__":
    sys.exit(main())
