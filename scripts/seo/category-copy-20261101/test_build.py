#!/usr/bin/env python3
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).parent
spec = importlib.util.spec_from_file_location('category_copy_build', ROOT / 'build.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class CategoryCopyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.entries = json.loads(module.ENTRIES.read_text(encoding='utf-8'))
        cls.sources = json.loads(module.SOURCES.read_text(encoding='utf-8'))
        cls.php = module.COPY_FILE.read_text(encoding='utf-8')

    def test_generated_copy_passes_every_rule(self):
        self.assertEqual(module.problems(self.entries, self.sources, module.reviewed_slugs(self.php)), [])

    def test_copy_file_is_in_sync_with_entries(self):
        self.assertEqual(module.render(self.php, self.entries), self.php)

    def test_reviewed_entries_are_kept(self):
        self.assertEqual(len(module.reviewed_slugs(self.php)), 59)
        self.assertIn("    'peruki' => [", self.php.split(module.MARKER)[0])

    def test_rules_catch_claims_and_site_structure(self):
        source = dict(self.sources[0])
        entry = dict(self.entries[0])
        for bad in ['Orteza skutecznie łagodzi ból.', 'Kategoria położona w ścieżce A > B.', 'Darmowa dostawa w Ortezka.pl.']:
            broken = dict(entry, description=entry['description'] + ' ' + bad)
            self.assertTrue(module.problems([broken], [source], set()), bad)


if __name__ == '__main__':
    unittest.main()
