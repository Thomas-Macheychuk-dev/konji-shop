#!/usr/bin/env python3
import csv
import importlib.util
import io
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).parent
spec = importlib.util.spec_from_file_location('legacy_fallback_build', ROOT / 'build.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
REPO = module.REPO


class LegacyFallbackMapTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.entries, cls.skipped, cls.unresolved = module.build()

    def test_committed_map_and_review_are_reproducible(self):
        self.assertEqual(module.MAP.read_bytes().decode('utf-8'), module.render_map(self.entries))
        out = io.StringIO()
        w = csv.writer(out, lineterminator='\n')
        w.writerow(['legacy_path', 'target', 'kind', 'reason'])
        w.writerows([p, v, k, r] for p, (v, k, r) in sorted(self.entries.items()))
        self.assertEqual(module.REVIEW.read_bytes().decode('utf-8'), out.getvalue())

    def test_scope(self):
        summary = module.summary(self.entries, self.skipped)
        self.assertEqual((summary['category_redirects'], summary['category_gone']), (232, 2))
        self.assertEqual(summary['product_ids'], 8640)
        self.assertEqual(summary['rules'], 14636)
        self.assertEqual(len(self.unresolved), 279)
        self.assertEqual(module.UNRESOLVED.read_bytes().decode('utf-8').count('\n'), 280)

    def test_never_shadows_exact_rules_or_app_routes(self):
        keys = set(self.entries)
        self.assertFalse(keys & module.exact_keys())
        self.assertFalse(keys & module.placeholder_paths())
        self.assertTrue(all(re.search(r'-(id|cat)-\d+$', k) for k in keys))
        self.assertFalse({v for v, _, _ in self.entries.values()} & keys)

    def test_targets_are_active_categories_and_only_categories_are_gone(self):
        sources, _ = module.decisions.load()
        active = {c['slug'] for c in sources['konji']}
        for path, (value, kind, _) in self.entries.items():
            if value == module.GONE:
                self.assertEqual(kind, 'category', path)
            else:
                self.assertTrue(value.startswith('/categories/') and value[len('/categories/'):] in active, path)
        gone = {p for p, (v, _, _) in self.entries.items() if v == module.GONE}
        self.assertEqual(gone, {'/na-dzien-babci-i-dziadka-cat-243', '/prezenty-na-dzien-kobiet-cat-244'})

    def test_breadcrumb_beats_name_rules(self):
        # Befado shoes under "Dla dzieci" follow the category decision, not the shoe keyword rule.
        reasons = {r for p, (v, k, r) in self.entries.items() if k == 'product'}
        self.assertTrue(any('breadcrumb category' in r for r in reasons))
        self.assertTrue(any('name rule cpap' in r for r in reasons))
        rules = module.json.loads(module.RULES.read_text(encoding='utf-8'))['rules']
        self.assertEqual(module.rule_for_name('Maska nosowa Wisp firmy Philips Respironics', rules)['id'], 'cpap')
        self.assertEqual(module.rule_for_name('MASKA ANTYSMOGOWA AŻUROWA', rules)['id'], 'protection')
        self.assertIsNone(module.rule_for_name('PODPÓRKA POD PLECY', rules))

    def test_runtime_gate_pins_the_committed_map(self):
        gate = (REPO / 'docker/nginx/verify-legacy-fallback-map.sh').read_text(encoding='utf-8')
        summary = module.summary(self.entries, self.skipped)
        self.assertIn(f'EXPECTED_SHA={summary["map_sha256"]}\n', gate)
        self.assertIn(f'EXPECTED_RULES={summary["rules"]}\n', gate)

    def test_nginx_wiring(self):
        conf = (REPO / 'docker/nginx/production.conf').read_text(encoding='utf-8')
        exact = 'include /etc/nginx/legacy-seo/runtime/10-product-redirects.conf;'
        fallback = 'include /etc/nginx/legacy-seo/runtime/30-fallback-redirects.conf;'
        self.assertEqual(len(re.findall(re.escape(exact) + r'\n *' + re.escape(fallback), conf)), 5)
        self.assertEqual(conf.count(fallback), 5)
        docker = (REPO / 'Dockerfile').read_text(encoding='utf-8')
        installed = 'COPY docker/nginx/generated/legacy-seo-category-fallback-map.conf /etc/nginx/conf.d/02-legacy-seo-category-fallback-map.conf'
        self.assertIn(installed, docker)
        # Maps are built in parse order; the fallback map must follow the file that sets map_hash_bucket_size.
        self.assertLess('00-legacy-seo-product-map.conf', '02-legacy-seo-category-fallback-map.conf')
        start = (REPO / 'docker/nginx/start-production.sh').read_text(encoding='utf-8')
        self.assertIn('/usr/local/bin/verify-legacy-fallback-map\n', start)
        self.assertIn('${LEGACY_SEO_FALLBACK_REDIRECTS_ENABLED:-false}', start)
        compose = (REPO / 'docker-compose.prod.yml').read_text(encoding='utf-8')
        self.assertIn('LEGACY_SEO_FALLBACK_REDIRECTS_ENABLED: "${LEGACY_SEO_FALLBACK_REDIRECTS_ENABLED:-false}"', compose)
        self.assertIn('LEGACY_SEO_FALLBACK_REDIRECTS_ENABLED=false', (REPO / '.env.production.example').read_text(encoding='utf-8'))


if __name__ == '__main__':
    unittest.main()
