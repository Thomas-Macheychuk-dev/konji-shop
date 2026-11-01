#!/usr/bin/env python3
import base64
import gzip
import importlib.util
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).parent
spec = importlib.util.spec_from_file_location('preservation_download', ROOT / 'download.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PreservationTransportTest(unittest.TestCase):
    def test_scope_and_bundle(self):
        review = json.loads((ROOT / 'review.json').read_text())
        self.assertEqual(len(review['groups']), 5)
        self.assertEqual({p['id'] for g in review['groups'] for p in g['products']},
                         {9983, 9986, 10104, 10107, 12583, 12619, 12587, 12623, 12590, 12626})
        files = module.bundle_files()
        command = module.export_command(files, 'konji-shop-prod-app-1')
        subprocess.run(['bash', '-n'], input=command, text=True, check=True)
        line = next(line for line in command.splitlines() if line.startswith('python3 - "$taskdir" '))
        package = line.split()[3]
        decoded = json.loads(gzip.decompress(base64.b64decode(package)))
        self.assertEqual({name: base64.b64decode(value) for name, value in decoded.items()}, files)
        self.assertIn("data['database_writes'] is False", command)
        self.assertIn(module.VERSION, command)

    def test_rejects_shell_injection_or_extra_bundle(self):
        files = module.bundle_files()
        with self.assertRaises(ValueError):
            module.export_command(files, 'app; touch /tmp/bad')
        with self.assertRaises(ValueError):
            module.export_command(files | {'extra.php': b'bad'}, 'app')


if __name__ == '__main__':
    unittest.main()
