#!/usr/bin/env python3
import csv
import importlib.util
import io
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).parent
spec = importlib.util.spec_from_file_location('legacy_category_suggest', ROOT / 'suggest.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class LegacyCategorySuggestionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sources = json.loads(module.SOURCES.read_text(encoding='utf-8'))
        cls.rows = module.suggest(cls.sources)

    def test_frozen_scope(self):
        self.assertEqual(len(self.sources['legacy']), 234)
        self.assertEqual(len({c['legacy_id'] for c in self.sources['legacy']}), 234)
        self.assertEqual(len(self.sources['konji']), 1119)
        self.assertTrue(all(c['path'].endswith('-cat-%d' % c['legacy_id']) for c in self.sources['legacy']))

    def test_committed_review_is_reproducible(self):
        out = io.StringIO()
        writer = csv.DictWriter(out, fieldnames=module.COLUMNS, lineterminator='\r\n')
        writer.writeheader()
        writer.writerows(self.rows)
        self.assertEqual(module.REVIEW.read_bytes().decode('utf-8'), out.getvalue())

    def test_suggestions_are_existing_categories(self):
        slugs = {c['slug'] for c in self.sources['konji']}
        for row in self.rows:
            for key in ['suggested_slug', 'alternative_1_slug', 'alternative_2_slug']:
                self.assertTrue(row[key] == '' or row[key] in slugs)
            self.assertEqual(row['suggested_slug'] == '', row['confidence'] == 'none')
        self.assertEqual([r['priority'] for r in self.rows], list(range(1, 235)))

    def test_generic_names_need_agreeing_context(self):
        by_path = {r['legacy_path']: r for r in self.rows}
        self.assertEqual(by_path['/akcesoria-cat-100']['suggested_path'], 'Odzież medyczna i zawodowa > Akcesoria')
        # "Dla dzieci" also names deep subcategories of unrelated departments; that is never a confident match.
        dla_dzieci = next(r for r in self.rows if r['legacy_name'] == 'Dla dzieci' and r['legacy_parents'] == '')
        self.assertNotEqual(dla_dzieci['confidence'], 'high')
        damska = next(r for r in self.rows if r['legacy_name'] == 'Damska' and r['legacy_parents'] == 'Odzież medyczna')
        self.assertIn(damska['confidence'], {'low', 'none'})

    def test_polish_folding(self):
        self.assertEqual(module.fold('Wózki – ŁÓŻKA'), 'wozki lozka')
        self.assertEqual(module.stems('Ortezy stawu łokciowego'), module.stems('orteza stawu łokciowego'))


if __name__ == '__main__':
    unittest.main()
