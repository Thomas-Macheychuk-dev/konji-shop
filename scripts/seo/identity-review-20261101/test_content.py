#!/usr/bin/env python3
import hashlib,json,unittest
from pathlib import Path
ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())
class IdentityReviewTests(unittest.TestCase):
 def test_full_review_and_frozen_identity_scope(self):
  path=ROOT.parents[2]/'tests/Fixtures/Seo/identity-review-20261101.json'
  if not path.exists():path=ROOT/'source-fixture.json'
  rows={p['id']:p for p in json.loads(path.read_text())}
  self.assertEqual(26,len(M['identity_review']));self.assertEqual(27,len(M['entries']));self.assertEqual(13,sum(g['title_group_resolved_by_this_batch'] for g in M['identity_review']))
  self.assertEqual(27,len({e['after']['seo_title'].casefold() for e in M['entries']}))
  self.assertEqual({2828,6744,7951},{e['id'] for e in M['entries'] if 'after_name' in e})
  for e in M['entries']:
   p=rows[e['id']]
   for field in ['description','short_description']:self.assertEqual(hashlib.sha256(p[field].encode()).hexdigest(),e['source_'+field+'_sha256'])
   self.assertEqual({'seo_title','seo_description'},set(e['after']))
   self.assertTrue(e['evidence']['media_samples']);self.assertTrue(e['evidence']['variant_snapshot'])
   for text in e['after'].values():
    self.assertNotIn('GTIN',text);self.assertNotIn('EAN',text);self.assertNotIn('Kod producenta',text)
   identity=e['evidence'].get('primary_identity',{})
   if e['evidence']['source']=='source_shop_product_id':
    self.assertEqual(p['external_id'],str(identity['id']))
    code=next(a['terms'][0]['name'] for a in identity['attributes'] if a['name']=='Kod');self.assertIn(code,e['after']['seo_description'])
   if e['evidence']['source'] in ['source_selected_colour_and_mpn','source_product_id_and_mpn']:
    self.assertEqual(p['external_id'],identity['product_id']);self.assertIn(identity['source_mpn'],e['after']['seo_description'])
   if e['evidence']['source']=='source_selected_colour_and_mpn':
    self.assertIn(identity['selected_colour'],e['after']['seo_title'])
   if e['evidence']['source']=='manufacturer_colour_selector_and_visual_review':
    self.assertEqual(1,len(identity['colour_selector_links']));self.assertTrue(e['evidence']['inference']);self.assertEqual(64,len(e['evidence']['reviewed_photo_sha256']))
  entries={e['id']:e for e in M['entries']}
  self.assertNotIn(6745,entries);self.assertNotIn(12021,entries);self.assertNotIn(12022,entries)
  for i in [2828,6744,7951]:
   for wrong in ['silkgrey','lighthoney','dark brown']:self.assertNotIn(wrong,entries[i]['after_name'])
   self.assertNotIn(entries[i]['evidence']['primary_identity']['selected_colour'],entries[i]['after_image_caption'])
  for pair in [{1419,4530},{12021,12022},{12588,12624},{13026,13064}]:
   row=next(g for g in M['identity_review'] if set(g['product_ids'])==pair);self.assertTrue(row['requires_further_identity_or_business_review'])
  self.assertEqual(5,sum(g['decision']=='duplicate_confirmed_prepare_consolidation' for g in M['identity_review']))
if __name__=='__main__':unittest.main()
