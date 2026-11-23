#!/usr/bin/env python3
"""Stage a tested release ZIP for WordPress.org; publish only with --commit."""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import xml.etree.ElementTree as ET
import zipfile

REPOSITORY = "https://plugins.svn.wordpress.org/pressqueue"


def package_files(archive, version, checksum):
    """Validate the archive before writing anything into an SVN checkout."""
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        raise ValueError("Use a stable version such as 0.1.2.")
    if hashlib.sha256(archive.read_bytes()).hexdigest() != checksum:
        raise ValueError("Release ZIP checksum does not match.")
    files = {}
    with zipfile.ZipFile(archive) as source:
        for entry in source.infolist():
            path = PurePosixPath(entry.filename)
            if path.is_absolute() or ".." in path.parts or "\\" in entry.filename:
                raise ValueError("Unsafe path in release ZIP.")
            if not path.parts or path.parts[0] != "pressqueue":
                raise ValueError("Every ZIP entry must be inside pressqueue/.")
            if stat.S_ISLNK(entry.external_attr >> 16):
                raise ValueError("Release ZIP must not contain symbolic links.")
            if entry.is_dir():
                continue
            relative = str(PurePosixPath(*path.parts[1:]))
            if relative in files or relative == ".":
                raise ValueError("Duplicate or empty file path in release ZIP.")
            if any(part.startswith(".") for part in path.parts[1:]):
                raise ValueError("Private or hidden file in release ZIP.")
            if path.parts[1] in ("tests", "tools", "build", "docs", "node_modules"):
                raise ValueError("Development files must not be shipped.")
            files[relative] = source.read(entry)
    for required in ("pressqueue.php", "readme.txt", "composer.json", "vendor/autoload.php", "LICENSE"):
        if required not in files:
            raise ValueError("Required package file missing: " + required)
    header = re.search(rb"(?m)^\s*\* Version:\s*(\S+)", files["pressqueue.php"])
    stable = re.search(rb"(?m)^Stable tag:\s*(\S+)", files["readme.txt"])
    if not header or not stable or header[1].decode() != version or stable[1].decode() != version:
        raise ValueError("Plugin version and readme Stable tag must match the release.")
    if json.loads(files["composer.json"])["version"] != version:
        raise ValueError("Packaged Composer version must match the release.")
    return files


def run_svn(*args, cwd=None, password=None):
    command = [os.environ.get("SVN_BINARY", "svn"), *args, "--non-interactive", "--no-auth-cache"]
    if password is not None:
        command += ["--username", "webtq", "--password-from-stdin"]
    result = subprocess.run(command, cwd=cwd, input=password + "\n" if password else None,
                            text=True, capture_output=True, check=False,
                            env={**os.environ, "LC_ALL": "C"})
    if result.returncode:
        # SVN does not print the stdin password. Never include environment values.
        raise RuntimeError(result.stderr.strip() or "SVN command failed.")
    return result.stdout


def verify_source(files, version, root, tracked=None):
    """Compare both directions against Git's tracked release files, excluding vendor."""
    if tracked is None:
        output = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root)
        tracked = output.decode().split('\0')
    expected_paths = {'pressqueue.php', 'uninstall.php', 'readme.txt', 'LICENSE'}
    expected_paths.update(name for name in tracked if name.startswith(('src/', 'assets/', 'languages/'))
                          and not any(part.startswith('.') for part in PurePosixPath(name).parts))
    expected_paths.update(('THIRD_PARTY_NOTICES.txt', 'composer.json'))
    packaged = {name for name in files if not name.startswith('vendor/')}
    if packaged != expected_paths:
        raise ValueError('Release file list differs from Git: ' + ', '.join(sorted(packaged ^ expected_paths)))
    for name in expected_paths - {'composer.json'}:
        source_name = 'THIRD_PARTY_NOTICES.md' if name == 'THIRD_PARTY_NOTICES.txt' else name
        expected, actual = (root / source_name).read_bytes(), files[name]
        if name.endswith('.txt'):
            expected, actual = expected.replace(b'\r\n', b'\n'), actual.replace(b'\r\n', b'\n')
        if actual != expected:
            raise ValueError('Release package differs from its Git commit: ' + name)
    composer = json.loads((root / 'composer.json').read_text())
    composer['version'] = version
    if json.loads(files['composer.json']) != composer:
        raise ValueError('Packaged Composer metadata differs from Git.')


def replace_tree(target, files):
    """Replace only files within the task-owned SVN checkout."""
    for child in target.iterdir():
        if child.is_dir():
            shutil.rmtree(child)
        else:
            child.unlink()
    for name, data in files.items():
        destination = target / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(data)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--zip", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--sha256", required=True)
    parser.add_argument("--assets", type=Path, required=True)
    parser.add_argument("--workspace", type=Path, required=True, help="New, empty directory")
    parser.add_argument("--commit", action="store_true", help="Publish to WordPress.org")
    args = parser.parse_args()
    files = package_files(args.zip, args.version, args.sha256)
    assets = {}
    for path in args.assets.iterdir():
        if path.is_symlink() or not path.is_file() or not re.fullmatch(
            r"(?:banner-\d+x\d+|icon(?:-\d+x\d+)?|screenshot-\d+)\.(?:png|jpg|svg)", path.name
        ):
            raise ValueError("Unexpected directory asset: " + path.name)
        assets[path.name] = path.read_bytes()
    if not assets:
        raise ValueError("Directory assets are missing.")
    if args.workspace.exists() and any(args.workspace.iterdir()):
        raise ValueError("Workspace must be empty. Preserve previous release attempts.")
    args.workspace.mkdir(parents=True, exist_ok=True)
    checkout = args.workspace / "svn"
    run_svn("checkout", REPOSITORY, str(checkout))
    # Published version tags are immutable. An uncertain commit must be reconciled.
    if (checkout / "tags" / args.version).exists():
        raise ValueError("Version already exists in SVN. Check its files; do not overwrite it.")
    previous = checkout / "trunk" / "pressqueue.php"
    if previous.exists():
        header = re.search(rb"(?m)^\s*\* Version:\s*(\S+)", previous.read_bytes())
        if not header or not re.fullmatch(rb"[0-9]+\.[0-9]+\.[0-9]+", header[1]):
            raise ValueError("Current SVN version cannot be read; inspect the repository first.")
        if tuple(map(int, args.version.split('.'))) <= tuple(map(int, header[1].decode().split('.'))):
            raise ValueError("The new version must be newer than the current SVN release.")
    replace_tree(checkout / "trunk", files)
    replace_tree(checkout / "assets", assets)
    status = ET.fromstring(run_svn("status", "--xml", cwd=checkout))
    for entry in status.findall(".//entry"):
        item = entry.find("wc-status").get("item")
        if item == "missing":
            run_svn("delete", "--force", entry.get("path") + '@', cwd=checkout)
    run_svn("add", "--force", "trunk", "assets", cwd=checkout)
    for name in assets:
        mime = {"png": "image/png", "jpg": "image/jpeg", "svg": "image/svg+xml"}[name.rsplit(".", 1)[1]]
        run_svn("propset", "svn:mime-type", mime, "assets/" + name, cwd=checkout)
    run_svn("copy", "trunk", "tags/" + args.version, cwd=checkout)
    summary = {"version": args.version, "sha256": args.sha256, "package_files": len(files),
               "directory_assets": len(assets), "repository": REPOSITORY,
               "checkout": str(checkout.resolve()), "published": False}
    (args.workspace / "receipt.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2), flush=True)
    if not args.commit:
        print("Staged only. Review svn status/diff in the checkout before publishing.")
        return
    password = os.environ.get("SVN_PASSWORD")
    if not password:
        raise ValueError("Set SVN_PASSWORD in the process environment to publish.")
    output = run_svn("commit", "-m", "Release PressQueue " + args.version,
                     cwd=checkout, password=password)
    match = re.search(r"Committed revision (\d+)\.", output)
    if not match:
        raise RuntimeError("Commit response unclear. Check remote SVN before retrying.")
    summary.update(published=True, svn_revision=int(match[1]))
    (args.workspace / "receipt.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, RuntimeError, OSError, zipfile.BadZipFile) as error:
        sys.exit(str(error))
