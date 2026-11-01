#!/usr/bin/env python3
import hashlib,json,re,unittest
from pathlib import Path
ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())
class ConsolidationEvidenceTests(unittest.TestCase):
 def test_disjoint_exact_body_pairs_and_explicit_scope(self):
  fixture=ROOT.parents[2]/'tests/Fixtures/Seo/consolidation-20261101.json'
  if not fixture.exists():fixture=ROOT/'source-fixture.json'
  rows={p['id']:p for p in json.loads(fixture.read_text())};ids=[]
  self.assertEqual(6,len(M['pairs']));self.assertEqual(26,len(M['held_groups']))
  aliases=ROOT.parents[2]/'resources/seo/ortezka/reviewed-product-aliases-20261101.json'
  if not aliases.exists():aliases=ROOT/'aliases.json'
  mapping=json.loads(aliases.read_text())['pairs'];self.assertGreaterEqual(len(mapping),6)
  for pair,alias in zip(M['pairs'],mapping):
   a,b=[rows[pair[k]['id']] for k in ['target','source']];ids.extend([a['id'],b['id']]);self.assertEqual(a['description'],b['description']);self.assertEqual(a['product_attributes'],b['product_attributes'])
   self.assertEqual({i['source_url'] for i in a['image_evidence']},{i['source_url'] for i in b['image_evidence']});self.assertTrue(pair['manufacturer_image_samples'])
   for role,p in [('target',a),('source',b)]:
    self.assertEqual('vermeiren',p['external_source']);self.assertEqual(0,p['active_variant_count']);self.assertEqual(1,len(p['variants']))
    self.assertEqual('draft',p['variants'][0]['status']);self.assertIsNone(p['variants'][0]['price_gross_amount'])
    for f in ['description','short_description']:self.assertEqual(hashlib.sha256(p[f].encode()).hexdigest(),pair[role][f+'_sha256'])
    self.assertFalse(any(k for k in pair[role]['references'] if any(w in k for w in ['order','cart','withdrawal'])))
    self.assertEqual(p['slug'],alias[role]['slug']);self.assertEqual(p['id'],alias[role]['id'])
  self.assertEqual(12,len(set(ids)))
 def test_runtime_bundle_hashes(self):
  script=(ROOT/'patch-runtime.sh').read_text();hashes=re.search(r'new_hashes=\(([^)]*)\)',script).group(1).split()
  for path,h in zip(['ReviewedProductAliases.php','aliases.json','ProductShowController.php'],hashes):
   candidates={'ReviewedProductAliases.php':'app/Support/Seo/ReviewedProductAliases.php','aliases.json':'resources/seo/ortezka/reviewed-product-aliases-20261101.json','ProductShowController.php':'app/Http/Controllers/ProductShowController.php'}
   p=ROOT.parents[2]/candidates[path]
   if not p.exists():p=ROOT/path
   content=p.read_bytes()
   if path=='aliases.json':
    # Later approved batches append aliases; reconstruct this original six-pair bundle.
    data=json.loads(content);data['pairs']=data['pairs'][:6];content=(json.dumps(data,ensure_ascii=False,indent=2)+'\n').encode()
   self.assertEqual(h,hashlib.sha256(content).hexdigest())
  self.assertIn('ALL_CONTAINER_PREFLIGHT=PASS',script);self.assertIn('restore_sources',script)
  self.assertIn('df806bf23d22c0420fda442811b5bf9137bd5c6ed2e64023898eac496cf58bee',script)
if __name__=='__main__':unittest.main()
