#!/usr/bin/env python3
import hashlib,json,unittest
from pathlib import Path
ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())
class DuplicateCodeTests(unittest.TestCase):
    def test_description_evidence_and_title_only_scope(self):
        fixture=ROOT.parents[2]/'tests/Fixtures/Seo/duplicate-codes-20261101.json'
        if not fixture.exists():fixture=ROOT/'source-fixture.json'
        rows={p['id']:p for p in json.loads(fixture.read_text())}
        self.assertEqual(38,len(M['entries']))
        self.assertEqual(38,len(set(e['after']['seo_title'].casefold() for e in M['entries'])))
        for e in M['entries']:
            p=rows[e['id']]
            self.assertIn(e['evidence']['quote'],p['description'])
            for f in ['description','short_description']:
                self.assertEqual(hashlib.sha256(p[f].encode()).hexdigest(),e['source_'+f+'_sha256'])
            self.assertEqual(e['expected']['seo_description'],e['after']['seo_description'])
            self.assertEqual({'seo_title','seo_description'},set(e['after']))
            title=e['after']['seo_title']
            # Every identifying code is explicitly in the source, with only separator changes.
            code=e['evidence']['quote'].removeprefix('SKU').removeprefix('2x').strip()
            compact=lambda s:''.join(c for c in s.casefold() if c.isalnum())
            self.assertIn(compact(code),compact(title.replace('rozmiar ','')))
            self.assertNotIn(str(e['id']),title)
            for guessed in ['pomarańcz','zielon','szar','czarn','wylecz','odchudz']:
                self.assertNotIn(guessed,title.casefold())
        self.assertEqual(59,len(M['duplicate_review']))
        resolved=[g for g in M['duplicate_review'] if g['changed_product_ids']]
        self.assertEqual(17,len(resolved))
        self.assertEqual(42,len(M['duplicate_review'])-len(resolved))
        for g in resolved:self.assertEqual(set(g['product_ids']),set(g['changed_product_ids']))
        held=set(i for g in M['duplicate_review'] if not g['changed_product_ids'] for i in g['product_ids'])
        self.assertTrue({9620,9940,164,165,12021,12022,13717,13718}<=held)
if __name__=='__main__':unittest.main()
