"""Exercise release validation without credentials or SVN writes."""

import hashlib
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

SPEC = importlib.util.spec_from_file_location('publisher', Path(__file__).with_name('publish-wordpress.py'))
PUBLISHER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PUBLISHER)


class ReleaseValidationTest(unittest.TestCase):
    def validate(self, extra=None, stable='0.1.2', checksum=None):
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / 'pressqueue.zip'
            files = {
                'pressqueue/pressqueue.php': '<?php\n * Version: 0.1.2\n',
                'pressqueue/readme.txt': 'Stable tag: ' + stable + '\n',
                'pressqueue/composer.json': json.dumps({'version': '0.1.2'}),
                'pressqueue/vendor/autoload.php': '<?php',
                'pressqueue/LICENSE': 'MIT',
            }
            files.update(extra or {})
            with zipfile.ZipFile(archive, 'w') as output:
                for name, value in files.items():
                    output.writestr(name, value)
            return PUBLISHER.package_files(archive, '0.1.2', checksum or hashlib.sha256(archive.read_bytes()).hexdigest())

    def test_valid_package(self):
        self.assertIn('vendor/autoload.php', self.validate())

    def test_rejects_unverified_package(self):
        with self.assertRaisesRegex(ValueError, 'checksum'):
            self.validate(checksum='0' * 64)

    def test_rejects_path_traversal(self):
        for name in ('pressqueue/../secret', '/pressqueue/file', 'pressqueue/..\\secret'):
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'Unsafe path'):
                self.validate({name: 'private'})

    def test_rejects_private_and_development_files(self):
        for name in ('pressqueue/.env', 'pressqueue/.vroni/note', 'pressqueue/tests/test.php'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.validate({name: 'private'})

    def test_rejects_mismatched_stable_tag(self):
        with self.assertRaisesRegex(ValueError, 'Stable tag'):
            self.validate(stable='0.1.1')

    def source_fixture(self, root):
        source = {'pressqueue.php': b'<?php', 'uninstall.php': b'<?php',
                  'readme.txt': b'Stable tag: 0.1.2', 'LICENSE': b'MIT',
                  'src/Plugin.php': b'<?php', 'languages/.gitkeep': b'',
                  'assets/OFL.txt': b'Font license\n', 'THIRD_PARTY_NOTICES.md': b'Notices',
                  'composer.json': b'{"require":{"php":">=8.1"}}'}
        for name, data in source.items():
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        packaged = {name: data for name, data in source.items()
                    if name not in ('languages/.gitkeep', 'THIRD_PARTY_NOTICES.md', 'composer.json')}
        packaged.update({'THIRD_PARTY_NOTICES.txt': b'Notices',
                         'composer.json': b'{"require":{"php":">=8.1"},"version":"0.1.2"}',
                         'vendor/autoload.php': b'<?php'})
        return source, packaged

    def test_source_check_rejects_extra_untracked_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            tracked, packaged = self.source_fixture(root)
            # Even an untracked file present in the working tree must not ship.
            (root / 'src/Debug.php').write_bytes(b'<?php')
            for name in ('src/Debug.php', 'notes.php'):
                with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'file list'):
                    PUBLISHER.verify_source({**packaged, name: b'<?php'}, '0.1.2', root, tracked)

    def test_source_check_preserves_text_and_composer_contract(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            tracked, packaged = self.source_fixture(root)
            packaged['assets/OFL.txt'] = b'Font license\r\n'
            PUBLISHER.verify_source(packaged, '0.1.2', root, tracked)
            packaged['composer.json'] = b'{"version":"0.1.2"}'
            with self.assertRaisesRegex(ValueError, 'Composer metadata'):
                PUBLISHER.verify_source(packaged, '0.1.2', root, tracked)


class LocalSvnReleaseTest(unittest.TestCase):
    def test_first_release_upgrade_and_immutable_tags(self):
        svn = os.environ.get('SVN_BINARY') or shutil.which('svn')
        admin = str(Path(svn).with_name('svnadmin')) if svn else None
        if not svn or not admin or not Path(admin).is_file():
            self.skipTest('SVN and svnadmin are required for the local release test.')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            repository = root / 'repository'
            subprocess.run([admin, 'create', str(repository)], check=True, capture_output=True)
            url = repository.as_uri()
            subprocess.run([svn, 'mkdir', url + '/trunk', url + '/tags', url + '/assets', '-m', 'Initialize'], check=True, capture_output=True)
            assets = root / 'images'
            assets.mkdir()
            (assets / 'icon.svg').write_text('<svg xmlns="http://www.w3.org/2000/svg"/>')
            for version in ('0.1.2', '0.1.3'):
                archive = root / (version + '.zip')
                files = {
                    'pressqueue/pressqueue.php': '<?php\n * Version: ' + version + '\n',
                    'pressqueue/readme.txt': 'Stable tag: ' + version + '\n',
                    'pressqueue/composer.json': json.dumps({'version': version}),
                    'pressqueue/vendor/autoload.php': '<?php',
                    'pressqueue/LICENSE': 'MIT',
                    'pressqueue/old-directory/keep.php': '<?php',
                }
                if version == '0.1.2':
                    files['pressqueue/old-directory/removed@old.php'] = '<?php'
                    files['pressqueue/deleted-directory/file.php'] = '<?php'
                with zipfile.ZipFile(archive, 'w') as output:
                    for name, value in files.items():
                        output.writestr(name, value)
                arguments = ['publisher', '--zip', str(archive), '--version', version,
                             '--sha256', hashlib.sha256(archive.read_bytes()).hexdigest(),
                             '--assets', str(assets), '--workspace', str(root / version), '--commit']
                with patch.object(PUBLISHER, 'REPOSITORY', url), patch.object(sys, 'argv', arguments), \
                        patch.dict(os.environ, {'SVN_BINARY': svn, 'SVN_PASSWORD': 'local-test'}), \
                        contextlib.redirect_stdout(io.StringIO()):
                    PUBLISHER.main()
                self.assertTrue(json.loads((root / version / 'receipt.json').read_text())['published'])
            old = subprocess.check_output([svn, 'cat', url + '/tags/0.1.2/old-directory/removed@old.php@'])
            self.assertEqual(b'<?php', old)
            for path in ('old-directory/removed@old.php@', 'deleted-directory'):
                removed = subprocess.run([svn, 'list', url + '/tags/0.1.3/' + path], capture_output=True)
                self.assertNotEqual(0, removed.returncode)
            # A repeated attempt must stop before changing the existing release.
            arguments[-2] = str(root / 'duplicate')
            with patch.object(PUBLISHER, 'REPOSITORY', url), patch.object(sys, 'argv', arguments), \
                    patch.dict(os.environ, {'SVN_BINARY': svn}):
                with self.assertRaisesRegex(ValueError, 'already exists'):
                    PUBLISHER.main()


if __name__ == '__main__':
    unittest.main()
