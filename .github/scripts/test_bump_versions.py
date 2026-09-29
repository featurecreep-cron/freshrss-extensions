"""Tests for bump_versions.py, run against a real throwaway git repository.

The script's whole job is reading git history, so a stub would test the stub.
Each test builds main, branches develop, commits on it, and checks what the
script decides and writes.

Run: python3 -m unittest discover -s .github/scripts
"""

import json
import os
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import bump_versions  # noqa: E402


def run(*args: str) -> str:
    return subprocess.run(args, check=True, capture_output=True, text=True).stdout


class BumpVersionsTest(unittest.TestCase):
    def setUp(self) -> None:
        self._cwd = os.getcwd()
        self._tmp = tempfile.TemporaryDirectory()
        os.chdir(self._tmp.name)
        os.environ.pop("GITHUB_OUTPUT", None)
        run("git", "init", "-q", "-b", "main")
        run("git", "config", "user.name", "test")
        run("git", "config", "user.email", "test@example.com")
        run("git", "config", "commit.gpgsign", "false")
        self.extension("xExtension-Alpha", "Alpha", "0.6.5")
        self.extension("xExtension-Beta", "Beta — dashed", "1.2.0")
        self.commit("feat: initial")
        run("git", "checkout", "-q", "-b", "develop")

    def tearDown(self) -> None:
        os.chdir(self._cwd)
        self._tmp.cleanup()

    # -- helpers ---------------------------------------------------------

    def extension(self, directory: str, name: str, version: str) -> None:
        os.makedirs(directory, exist_ok=True)
        meta = {"name": name, "author": "x", "version": version, "type": "user"}
        with open(f"{directory}/metadata.json", "w", encoding="utf-8") as handle:
            handle.write(json.dumps(meta, indent=4, ensure_ascii=False) + "\n")
        self.touch(f"{directory}/extension.php", "<?php\n")

    def touch(self, path: str, content: str) -> None:
        with open(path, "a", encoding="utf-8") as handle:
            handle.write(content)

    def commit(self, message: str) -> None:
        run("git", "add", "-A")
        run("git", "commit", "-q", "-m", message)

    def change(self, directory: str, message: str, filename: str = "extension.php") -> None:
        self.touch(f"{directory}/{filename}", f"// {message}\n")
        self.commit(message)

    def version(self, directory: str) -> str:
        with open(f"{directory}/metadata.json", encoding="utf-8") as handle:
            return json.load(handle)["version"]

    def plan(self) -> dict:
        bumps, problems = bump_versions.plan("main", "HEAD")
        self.assertEqual(problems, [])
        return {d: (new, level) for d, (_, new, level) in bumps.items()}

    # -- bump level --------------------------------------------------------

    def test_fix_is_a_patch(self) -> None:
        self.change("xExtension-Alpha", "fix(alpha): handle https")
        self.assertEqual(self.plan(), {"xExtension-Alpha": ("0.6.6", "patch")})

    def test_non_conventional_subject_is_a_patch(self) -> None:
        # An outside contributor's squash title will often look like this.
        self.change("xExtension-Alpha", "Fix Extension Manager downloads")
        self.assertEqual(self.plan(), {"xExtension-Alpha": ("0.6.6", "patch")})

    def test_feat_is_a_minor_and_wins_over_fix(self) -> None:
        self.change("xExtension-Alpha", "fix: one")
        self.change("xExtension-Alpha", "feat(alpha): two")
        self.change("xExtension-Alpha", "docs: three")
        self.assertEqual(self.plan(), {"xExtension-Alpha": ("0.7.0", "minor")})

    def test_bang_is_a_major(self) -> None:
        self.change("xExtension-Beta", "feat(beta)!: drop the old setting")
        self.assertEqual(self.plan(), {"xExtension-Beta": ("2.0.0", "major")})

    def test_breaking_footer_is_a_major(self) -> None:
        self.change("xExtension-Beta", "fix: rename\n\nBREAKING CHANGE: key moved")
        self.assertEqual(self.plan(), {"xExtension-Beta": ("2.0.0", "major")})

    def test_breaking_on_zero_major_is_a_minor(self) -> None:
        # 1.0.0 is a maintainer's call, never a side effect of a `!`.
        self.change("xExtension-Alpha", "feat(alpha)!: drop the old setting")
        self.assertEqual(self.plan(), {"xExtension-Alpha": ("0.7.0", "minor")})

    def test_breaking_footer_on_zero_major_is_a_minor(self) -> None:
        self.change("xExtension-Alpha", "fix: rename\n\nBREAKING CHANGE: key moved")
        self.assertEqual(self.plan(), {"xExtension-Alpha": ("0.7.0", "minor")})

    def test_hand_set_one_point_oh_is_kept(self) -> None:
        self.change("xExtension-Alpha", "feat!: stable at last")
        self.extension("xExtension-Alpha", "Alpha", "1.0.0")
        self.commit("chore: declare 1.0.0")
        self.assertEqual(self.plan(), {})

    def test_feat_in_another_extension_does_not_leak(self) -> None:
        self.change("xExtension-Alpha", "fix: alpha")
        self.change("xExtension-Beta", "feat: beta")
        self.assertEqual(
            self.plan(),
            {"xExtension-Alpha": ("0.6.6", "patch"), "xExtension-Beta": ("1.3.0", "minor")},
        )

    # -- what counts as a change -------------------------------------------

    def test_markdown_only_needs_no_bump(self) -> None:
        self.change("xExtension-Alpha", "feat: docs", filename="README.md")
        self.assertEqual(self.plan(), {})

    def test_changes_outside_extensions_need_no_bump(self) -> None:
        os.makedirs(".github", exist_ok=True)
        self.touch(".github/ci.yml", "x\n")
        self.commit("feat(ci): something")
        self.assertEqual(self.plan(), {})

    def test_merge_from_main_does_not_count(self) -> None:
        # develop is re-synced from main with a merge commit; main's own
        # commits must not be read as unreleased work.
        run("git", "checkout", "-q", "main")
        self.change("xExtension-Alpha", "feat: already released")
        run("git", "checkout", "-q", "develop")
        run("git", "merge", "-q", "--no-edit", "main")
        self.assertEqual(self.plan(), {})

    # -- existing bumps -------------------------------------------------------

    def test_manual_bump_at_or_above_target_is_kept(self) -> None:
        self.change("xExtension-Alpha", "fix: small")
        self.extension("xExtension-Alpha", "Alpha", "0.8.0")
        self.commit("chore: maintainer wants a minor")
        self.assertEqual(self.plan(), {})

    def test_second_run_is_idempotent(self) -> None:
        self.change("xExtension-Alpha", "fix: small")
        self.assertEqual(bump_versions.main_with(["main", "HEAD", "--write"]), 0)
        self.commit(bump_versions.summary({"xExtension-Alpha": ("0.6.5", "0.6.6", "patch")}, "HEAD"))
        self.assertEqual(self.plan(), {})

    def test_feat_after_a_pending_patch_raises_it_to_minor(self) -> None:
        self.change("xExtension-Alpha", "fix: small")
        bump_versions.main_with(["main", "HEAD", "--write"])
        self.commit("chore(release): bump Alpha 0.6.6")
        self.change("xExtension-Alpha", "feat: bigger")
        self.assertEqual(self.plan(), {"xExtension-Alpha": ("0.7.0", "minor")})

    def test_new_extension_is_left_alone(self) -> None:
        self.extension("xExtension-Gamma", "Gamma", "0.1.0")
        self.commit("feat: add gamma")
        self.assertEqual(self.plan(), {})

    def test_malformed_version_is_refused_not_guessed(self) -> None:
        run("git", "checkout", "-q", "main")
        self.extension("xExtension-Alpha", "Alpha", "0.6")
        self.commit("chore: short version")
        run("git", "checkout", "-q", "develop")
        run("git", "merge", "-q", "--no-edit", "main")
        self.change("xExtension-Alpha", "fix: x")
        bumps, problems = bump_versions.plan("main", "HEAD")
        self.assertEqual(bumps, {})
        self.assertEqual(len(problems), 1)
        self.assertEqual(bump_versions.main_with(["main", "HEAD", "--write"]), 1)
        self.assertEqual(self.version("xExtension-Alpha"), "0.6")

    # -- writing ---------------------------------------------------------------

    def test_write_changes_only_the_version_byte_for_byte(self) -> None:
        self.change("xExtension-Beta", "fix: beta")
        with open("xExtension-Beta/metadata.json", encoding="utf-8") as handle:
            before = handle.read()
        bump_versions.main_with(["main", "HEAD", "--write"])
        with open("xExtension-Beta/metadata.json", encoding="utf-8") as handle:
            after = handle.read()
        self.assertEqual(after, before.replace('"1.2.0"', '"1.2.1"'))

    def test_without_write_nothing_changes(self) -> None:
        self.change("xExtension-Alpha", "fix: x")
        bump_versions.main_with(["main", "HEAD"])
        self.assertEqual(self.version("xExtension-Alpha"), "0.6.5")

    def test_outputs_for_the_workflow(self) -> None:
        self.change("xExtension-Alpha", "fix: x")
        self.change("xExtension-Beta", "feat: y")
        out = os.path.join(self._tmp.name, "out")
        os.environ["GITHUB_OUTPUT"] = out
        try:
            bump_versions.main_with(["main", "HEAD", "--write"])
        finally:
            del os.environ["GITHUB_OUTPUT"]
        with open(out, encoding="utf-8") as handle:
            lines = handle.read().splitlines()
        self.assertIn("bumped=true", lines)
        self.assertIn("summary=chore(release): bump Alpha 0.6.6, Beta — dashed 1.3.0", lines)


if __name__ == "__main__":
    unittest.main()
