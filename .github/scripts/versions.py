"""Shared helpers for reading extension versions out of the git history.

The FreshRSS community catalog regenerates itself daily from whatever
`xExtension-*/metadata.json` says on this repository's default branch, and
Extension Manager only offers an update when the catalog version compares
greater than the installed one. That makes metadata.json the functional
source of truth for every version surface, so both CI gates read it from
here rather than reimplementing the parsing.
"""

import json
import re
import subprocess

EXTENSION_GLOB = "xExtension-*"
METADATA = "metadata.json"


def git(*args: str) -> str:
    """Run a git command and return its stdout, raising on failure."""
    return subprocess.run(
        ["git", *args], check=True, capture_output=True, text=True
    ).stdout


def extension_dirs(ref: str) -> list[str]:
    """Every xExtension-* directory that exists at `ref`."""
    # --full-tree so the listing is repo-root relative no matter the cwd.
    listing = git("ls-tree", "--name-only", "--full-tree", ref)
    return sorted(
        name.rstrip("/")
        for name in listing.splitlines()
        if name.rstrip("/").startswith("xExtension-")
    )


def metadata_at(ref: str, directory: str) -> dict:
    """The parsed metadata.json of `directory` at `ref`, or {} if absent/unparsable."""
    try:
        blob = git("show", f"{ref}:{directory}/{METADATA}")
    except subprocess.CalledProcessError:
        return {}
    try:
        parsed = json.loads(blob)
    except json.JSONDecodeError:
        return {}
    return parsed if isinstance(parsed, dict) else {}


def version_at(ref: str, directory: str) -> str | None:
    """The declared version of `directory` at `ref`, or None if absent/unparsable."""
    version = metadata_at(ref, directory).get("version")
    return str(version) if version is not None else None


def versions_at(ref: str) -> dict[str, str]:
    """Every extension's declared version at `ref`, keyed by directory."""
    found = {}
    for directory in extension_dirs(ref):
        version = version_at(ref, directory)
        if version is not None:
            found[directory] = version
    return found


def parse(version: str) -> tuple[int, ...]:
    """Parse a dotted version into a comparable tuple.

    Non-numeric segments sort as 0 so that a malformed version never crashes a
    gate — it just fails to compare greater, which is the safe direction.
    """
    parts = re.split(r"[.\-+]", version)
    numbers = []
    for part in parts[:3]:
        match = re.match(r"\d+", part)
        numbers.append(int(match.group()) if match else 0)
    while len(numbers) < 3:
        numbers.append(0)
    return tuple(numbers)


def is_increase(old: str, new: str) -> bool:
    """True when `new` is strictly greater than `old`."""
    return parse(new) > parse(old)


def display_name(directory: str, ref: str = "HEAD") -> str:
    """The extension's own declared name, falling back to the directory.

    metadata.json is authoritative here too — deriving the name by splitting
    CamelCase turns YouTubeEmbed into "You Tube Embed" and misses the hyphen in
    "Right-Click Actions".
    """
    name = metadata_at(ref, directory).get("name")
    if isinstance(name, str) and name.strip():
        return name.strip()
    stem = directory[len("xExtension-"):]
    return re.sub(r"(?<=[a-z0-9])(?=[A-Z])", " ", stem)
