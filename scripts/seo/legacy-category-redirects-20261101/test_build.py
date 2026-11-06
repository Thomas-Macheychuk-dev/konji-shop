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
        cls.products, cls.categories = module.build_id_maps(cls.entries)

    def test_committed_map_and_review_are_reproducible(self):
        self.assertEqual(module.MAP.read_bytes().decode('utf-8'), module.render_map(self.entries, self.products, self.categories))
        out = io.StringIO()
        w = csv.writer(out, lineterminator='\n')
        w.writerow(['legacy_path', 'target', 'kind', 'reason'])
        w.writerows([p, v, k, r] for p, (v, k, r) in sorted(self.entries.items()))
        self.assertEqual(module.REVIEW.read_bytes().decode('utf-8'), out.getvalue())

    def test_scope(self):
        summary = module.summary(self.entries, self.skipped, self.products, self.categories)
        self.assertEqual((summary['category_redirects'], summary['category_gone']), (232, 2))
        self.assertEqual(summary['product_ids'], 8838)
        self.assertEqual(summary['rules'], 14946)
        self.assertEqual(len(self.unresolved), 81)
        self.assertEqual(module.UNRESOLVED.read_bytes().decode('utf-8').count('\n'), 82)

    def test_never_shadows_exact_rules_or_app_routes(self):
        keys = set(self.entries)
        self.assertFalse(keys & module.exact_keys())
        self.assertFalse(keys & module.placeholder_paths())
        self.assertTrue(all(re.search(r'-(id|cat)-\d+$', k) or (kind == 'page' and k.startswith('/brand/'))
                            for k, (_, kind, _) in self.entries.items()))
        self.assertFalse({v for v, _, _ in self.entries.values()} & keys)

    def test_targets_are_active_categories_and_only_categories_are_gone(self):
        sources, _ = module.decisions.load()
        active = {c['slug'] for c in sources['konji']}
        for path, (value, kind, _) in self.entries.items():
            if value == module.GONE:
                self.assertEqual(kind, 'category', path)
            elif value.startswith('/products/'):
                self.assertIn(' manual product', self.entries[path][2], path)
            else:
                self.assertTrue(value.startswith('/categories/') and value[len('/categories/'):] in active, path)
        gone = {p for p, (v, _, _) in self.entries.items() if v == module.GONE}
        self.assertEqual(gone, {'/na-dzien-babci-i-dziadka-cat-243', '/prezenty-na-dzien-kobiet-cat-244'})

    def test_manual_decisions_cover_only_unresolved_products(self):
        manual = module.manual_decisions()
        self.assertTrue(manual)
        for lid, row in manual.items():
            self.assertIn(row['basis'], ('product', 'category'), lid)
            self.assertTrue(row['target'].startswith({'product': '/products/', 'category': '/categories/'}[row['basis']]), lid)
            self.assertTrue(any(r.endswith(f'legacy product {lid} manual {row["basis"]}') for _, _, r in self.entries.values()), lid)
        self.assertFalse({str(p['legacy_id']) for p in self.unresolved} & set(manual))

    def test_brand_pages_go_to_active_categories(self):
        pages = {p: v for p, (v, k, _) in self.entries.items() if k == 'page'}
        self.assertEqual(len(pages), len(module.MANUAL_PATHS.read_text(encoding='utf-8').splitlines()) - 1)
        self.assertEqual(pages['/brand/179-clinic-dress'], '/categories/odziez-medyczna')
        self.assertTrue(all(v.startswith('/categories/') for v in pages.values()))

    def test_breadcrumb_beats_name_rules(self):
        # Befado shoes under "Dla dzieci" follow the category decision, not the shoe keyword rule.
        reasons = {r for p, (v, k, r) in self.entries.items() if k == 'product'}
        self.assertTrue(any('breadcrumb category' in r for r in reasons))
        self.assertTrue(any('name rule cpap' in r for r in reasons))
        rules = module.json.loads(module.RULES.read_text(encoding='utf-8'))['rules']
        self.assertEqual(module.rule_for_name('Maska nosowa Wisp firmy Philips Respironics', rules)['id'], 'cpap')
        self.assertEqual(module.rule_for_name('MASKA ANTYSMOGOWA AŻUROWA', rules)['id'], 'protection')
        self.assertIsNone(module.rule_for_name('PODPÓRKA POD PLECY', rules))

    def test_id_maps_repeat_the_path_decisions_for_every_alias(self):
        for path, (target, kind, _) in self.entries.items():
            if kind == 'product':
                self.assertEqual(self.products[module.LEGACY_PRODUCT.match(path).group(1)][0], target, path)
            elif kind == 'category':
                self.assertEqual(self.categories[module.LEGACY_CATEGORY.match(path).group(1)][0], target, path)
        exact = module.EXACT_MAPS[0].read_text(encoding='utf-8')
        self.assertIn('"/oppo-2383-orteza-nadgarstka-miekka-z-dociagiem-id-9554" "/products/stabilizator-kciuka-i-nadgarstka-2383"', exact)
        self.assertEqual(self.products['9554'][0], '/products/stabilizator-kciuka-i-nadgarstka-2383')
        self.assertTrue(all(t.startswith(('/products/', '/categories/')) for t, _ in self.products.values()))

    def test_live_shop_redirects_only_follow_known_destinations(self):
        live = [r for _, r in self.products.values() if r.startswith('live shop')]
        self.assertEqual(len(live), module.summary(self.entries, self.skipped, self.products, self.categories)['product_ids_from_live_shop'])
        for lid, (target, reason) in self.products.items():
            if reason.startswith('live shop redirects to legacy category'):
                self.assertEqual(self.categories[reason.split()[-1]][0], target, lid)

    def test_id_patterns_cannot_capture_app_routes(self):
        rendered = module.render_id_maps(self.products, self.categories)
        self.assertIn(r'"~^/[^/]+-id-([0-9]+)\|" $1;', rendered)
        self.assertIn(r'"~^/[^/]+-cat-([0-9]+)\|" $1;', rendered)
        self.assertIn(r'"~^/index\.php(\?|$)" "/";', rendered)

    def test_runtime_gate_pins_the_committed_map(self):
        gate = (REPO / 'docker/nginx/verify-legacy-fallback-map.sh').read_text(encoding='utf-8')
        summary = module.summary(self.entries, self.skipped, self.products, self.categories)
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
