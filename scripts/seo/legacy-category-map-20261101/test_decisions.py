#!/usr/bin/env python3
import copy
import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).parent
spec = importlib.util.spec_from_file_location('legacy_category_decisions', ROOT / 'decisions.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class LegacyCategoryDecisionsTest(unittest.TestCase):
    def setUp(self):
        self.sources, self.rows = module.load()

    def test_every_legacy_category_resolves(self):
        resolved = module.resolve(self.sources, self.rows)
        self.assertEqual(len(resolved), 234)
        gone = {r['path'] for r in resolved.values() if r['target_slug'] is None}
        self.assertEqual(gone, {'/na-dzien-babci-i-dziadka-cat-243', '/prezenty-na-dzien-kobiet-cat-244'})

    def test_parent_follows_nearest_decided_ancestor(self):
        resolved = module.resolve(self.sources, self.rows)
        by_path = {r['path']: r for r in resolved.values()}
        # Stetoskopy has no counterpart; it and its children fall back to Urządzenia medyczne.
        self.assertEqual(by_path['/stetoskopy-pediatryczne-cat-190']['target_slug'], 'sprzet-medyczny')
        self.assertEqual(by_path['/stetoskopy-pediatryczne-cat-190']['via'], [188, 7])
        # The old "1 Klasa kompresji" level was never live, so legginsy skip it to Produkty uciskowe.
        self.assertEqual(by_path['/legginsy-cat-280']['target_slug'], 'kompresja')

    def test_rejects_invalid_decisions(self):
        cases = [
            lambda rows: rows[0].update(decision='maybe'),
            lambda rows: rows[0].update(decision='map', approved_target_slug='no-such-category'),
            lambda rows: rows[0].update(decision='gone', approved_target_slug='kompresja'),
            lambda rows: rows.pop(),
            lambda rows: rows.append(dict(rows[0])),
            lambda rows: rows[0].update(legacy_path='/changed-cat-1'),
        ]
        for mutate in cases:
            rows = copy.deepcopy(self.rows)
            mutate(rows)
            with self.assertRaises(ValueError):
                module.resolve(self.sources, rows)

    def test_root_category_cannot_defer_to_parent(self):
        rows = copy.deepcopy(self.rows)
        root = next(r for r in rows if r['legacy_parents'] == '')
        root.update(decision='parent', approved_target_slug='')
        with self.assertRaisesRegex(ValueError, 'no old ancestor'):
            module.resolve(self.sources, rows)


if __name__ == '__main__':
    unittest.main()
