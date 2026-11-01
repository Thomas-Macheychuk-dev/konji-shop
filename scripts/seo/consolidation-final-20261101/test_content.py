#!/usr/bin/env python3
import hashlib,json,re,unittest
from pathlib import Path
ROOT=Path(__file__).parent

class FinalPreservationContentTest(unittest.TestCase):
 def test_reviewed_scope_and_preservation(self):
  review=json.loads((ROOT/'review.json').read_text());frozen=json.loads((ROOT.parent.parent.parent/'tests/Fixtures/Seo/consolidation-final-20261101.json').read_text());aliases=json.loads((ROOT.parent.parent.parent/'resources/seo/ortezka/reviewed-product-aliases-20261101.json').read_text());old=json.loads((ROOT.parent/'consolidation-20261101/review.json').read_text())
  self.assertEqual(review['frozen'],frozen)
  self.assertEqual([(p['target']['id'],p['source']['id']) for p in review['pairs']],[(12583,12619),(12587,12623),(12590,12626)])
  self.assertEqual(aliases['pairs'][:6],[{role:{k:p[role][k] for k in ['id','slug','external_source','external_id']} for role in ['source','target']} for p in old['pairs']])
  self.assertEqual(aliases['pairs'][6:],review['pairs'])
  self.assertEqual(len(aliases['pairs']),9)
  self.assertFalse({9983,9986,10104,10107}&{p['source']['id'] for p in aliases['pairs']})
  p={p['id']:p for p in frozen['products']};updates={u['id']:u['after'] for u in review['product_updates']}
  self.assertEqual(set(updates),{9983,9986,10104,10107,12583,12587})
  for i,colour in [(9983,'czarna'),(9986,'biała'),(10104,'czarna'),(10107,'brązowa')]:
   self.assertEqual(set(updates[i]),{'name','seo_title','seo_description'})
   self.assertTrue(updates[i]['name'].endswith('– '+colour));self.assertIn(colour,updates[i]['seo_title'])
  for i in [12583,12587]:
   self.assertEqual(set(updates[i]),{'description'});self.assertTrue(updates[i]['description'].startswith(p[i]['description']))
  self.assertIn('Wybierz największy rozmiar',updates[12587]['description'])
  self.assertIn('YcUgijz9nGU',updates[12587]['description']);self.assertIn('itS-NJZhgXc',updates[12583]['description'])
  self.assertIn('prezi.com',p[12590]['description'])
  self.assertEqual(len(frozen['product_images']),73)
  for pair in review['pairs']:
   image_keys=lambda role:sorted((i['source_url'],i['sha256']) for i in frozen['product_images'] if i['product_id']==pair[role]['id'])
   self.assertEqual(image_keys('source'),image_keys('target'))
   pdf=lambda role:{u.rsplit('/',1)[-1] for u in re.findall(r'href="([^"]+\.pdf)"',p[pair[role]['id']]['description'])}
   self.assertLessEqual(pdf('source'),pdf('target'))
  self.assertEqual(frozen['product_documents'],[]);self.assertEqual(frozen['product_attribute_value_images'],[])
  self.assertEqual(review['remove_image_ids'],[59711,59712,59713,59714,59715])
  images={i['id']:i for i in frozen['product_images']}
  self.assertTrue(all(images[i]['product_id']==10104 for i in review['remove_image_ids']))
  self.assertEqual(len([i for i in frozen['product_images'] if i['product_id']==10104 and i['id'] not in review['remove_image_ids']]),3)
  self.assertEqual((review['expected_after_active_products'],review['expected_after_duplicate_title_groups']),(14618,8))
  for line in (ROOT/'checksums.sha256').read_text().splitlines():
   expected,path=line.split('  ');self.assertEqual(hashlib.sha256((ROOT.parent.parent.parent/path).read_bytes()).hexdigest(),expected)

if __name__=='__main__':unittest.main()
