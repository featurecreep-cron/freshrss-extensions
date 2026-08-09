"""Decide whether main has earned a release, and write the notes if it has.

The repository tag is a changelog surface, not an install surface — the catalog
tracks the default branch directly. Its only job is to stay truthful about what
shipped, which it failed at for 76 days between v0.2.0 and v0.3.0. So the tag is
derived from the extension versions rather than remembered by a person: any
extension whose metadata.json version increased since the last tag earns a
release, and the notes are generated from the commits that touched it.

Usage: plan_release.py [--output <github-output-file>]

Prints the plan to stdout. Emits `release=true|false`, `tag`, and `notes_file`
to the GitHub output file when one is given.
"""

import os
import subprocess
import sys

from versions import display_name, git, is_increase, parse, versions_at

NOTES_PATH = "release-notes.md"
REPO_URL = "https://github.com/featurecreep-cron/freshrss-extensions"


def last_tag() -> str | None:
    try:
        return git("describe", "--tags", "--abbrev=0", "--match", "v*").strip()
    except subprocess.CalledProcessError:
        return None


def next_tag(previous: str | None, changed: dict) -> str:
    """Umbrella version for the bundle.

    A bundle has no single semantic version, so the rule is the least
    surprising one available: the umbrella takes a minor bump when any
    extension is new or took a minor or major bump, and a patch bump when
    every change was a patch.
    """
    if previous is None:
        return "v0.1.0"
    major, minor, patch = parse(previous.lstrip("v"))
    any_feature = any(
        old is None or parse(new)[:2] > parse(old)[:2]
        for old, new in changed.values()
    )
    return f"v{major}.{minor + 1}.0" if any_feature else f"v{major}.{minor}.{patch + 1}"


def commits_for(base: str | None, path: str | None) -> list[tuple[str, str]]:
    """(sha, subject) for commits since `base` touching `path` (all paths if None)."""
    args = ["log", "--no-merges", "--format=%h\t%s"]
    args.append(f"{base}..HEAD" if base else "HEAD")
    if path:
        args += ["--", path]
    entries = []
    for line in git(*args).splitlines():
        if "\t" in line:
            sha, subject = line.split("\t", 1)
            entries.append((sha, subject))
    return entries


def extension_commit_shas(base: str | None) -> set[str]:
    """Commits since `base` that touched any extension directory."""
    return {sha for sha, _ in commits_for(base, "xExtension-*")}


def build_notes(previous: str | None, changed: dict, unchanged: dict) -> str:
    lines = [
        "Every version below is already live in the FreshRSS community catalog, "
        "which regenerates daily from this repository's default branch. This tag "
        "is the changelog for what landed there.",
        "",
    ]

    for directory in sorted(changed):
        old, new = changed[directory]
        # An extension added since the last tag has no previous version to
        # arrow from, so it is announced rather than diffed.
        heading = f"{new} — new extension" if old is None else f"{old} → {new}"
        lines.append(f"## {display_name(directory)} {heading}")
        lines.append("")
        for sha, subject in commits_for(previous, directory):
            lines.append(f"- {subject} ([`{sha}`]({REPO_URL}/commit/{sha}))")
        lines.append("")

    in_extensions = extension_commit_shas(previous)
    repo_commits = [
        (sha, subject)
        for sha, subject in commits_for(previous, None)
        if sha not in in_extensions
    ]
    if repo_commits:
        lines.append("## Repository")
        lines.append("")
        for sha, subject in repo_commits:
            lines.append(f"- {subject} ([`{sha}`]({REPO_URL}/commit/{sha}))")
        lines.append("")

    if unchanged:
        listed = ", ".join(
            f"{display_name(d)} {v}" for d, v in sorted(unchanged.items())
        )
        lines.append(f"## Unchanged in this release\n\n{listed}\n")

    lines.append(
        "## Install\n\n"
        f"Install through [Extension Manager]({REPO_URL}/tree/main/xExtension-ExtensionManager) "
        "or the FreshRSS extension catalog, both of which track this repository "
        "directly. Or copy the `xExtension-*` folders you want into your FreshRSS "
        "`extensions/` directory."
    )
    return "\n".join(lines) + "\n"


def emit(output: str | None, **values: str) -> None:
    if not output:
        return
    with open(output, "a", encoding="utf-8") as handle:
        for key, value in values.items():
            handle.write(f"{key}={value}\n")


def main() -> int:
    output = None
    if "--output" in sys.argv:
        output = sys.argv[sys.argv.index("--output") + 1]
    output = output or os.environ.get("GITHUB_OUTPUT")

    previous = last_tag()
    before = versions_at(previous) if previous else {}
    now = versions_at("HEAD")

    changed = {
        directory: (before.get(directory), version)
        for directory, version in now.items()
        if directory not in before or is_increase(before[directory], version)
    }
    unchanged = {d: v for d, v in now.items() if d not in changed}

    if not changed:
        print(f"No extension version increased since {previous or 'the initial commit'}.")
        print("No release earned.")
        emit(output, release="false")
        return 0

    tag = next_tag(previous, changed)

    print(f"Previous tag: {previous or '(none)'}")
    for directory, (old, new) in sorted(changed.items()):
        print(f"  {display_name(directory)}: {old or '(new)'} -> {new}")
    print(f"New tag: {tag}")

    notes = build_notes(previous, changed, unchanged)
    with open(NOTES_PATH, "w", encoding="utf-8") as handle:
        handle.write(notes)

    emit(output, release="true", tag=tag, notes_file=NOTES_PATH)
    return 0


if __name__ == "__main__":
    sys.exit(main())
