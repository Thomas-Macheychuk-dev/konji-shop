#!/usr/bin/env python3
import hashlib,json,re,unittest
from pathlib import Path
ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())

class SourceCopyTests(unittest.TestCase):
    def test_frozen_targets_and_source_hashes(self):
        path=ROOT.parents[2]/'tests/Fixtures/Seo/source-cleanup-20261101.json'
        if not path.exists():path=ROOT/'source-fixture.json'
        rows={r['id']:r for r in json.loads(path.read_text())}
        self.assertEqual(13,len(M['entries']))
        self.assertEqual({(13961,8815),(14229,9701)},{(v['product_id'],v['attribute_value_id']) for v in M['detach_attribute_values']})
        for link in M['detach_attribute_values']:
            value=next(v for v in rows[link['product_id']]['product_attributes'] if v['value_id']==link['attribute_value_id'])
            self.assertEqual(value['value'],link['expected_value'])
        for e in M['entries']:
            self.assertTrue(set(e['after'])<={'description','short_description'})
            for f in ['description','short_description']:
                self.assertEqual(hashlib.sha256(rows[e['id']][f].encode()).hexdigest(),e['source_'+f+'_sha256'])

    def test_product_options_and_medical_conflicts(self):
        for e in M['entries']:
            if e['id'] in [179,240,259]:
                self.assertEqual(e['after']['short_description'],e['expected']['seo_description'])
                if e['id']==259:
                    self.assertNotRegex(e['after']['short_description'],r'granat|brąz')
            else:
                text=e['after']['description'].casefold()
                self.assertNotRegex(text,r'niejałow|hydrożel|gipsem tradycyjnym|inwazyjn|6 tygodni|wolnowiążąc')
                if e['evidence']['source']=='meditrade':
                    self.assertIn('jałowe',text)
                    self.assertIn(e['evidence']['size'],e['after']['description'])
                    self.assertIn('25 torebek' if e['evidence']['retail_pairs']==25 else 'jedna torebka',text)
                if e['id']==13961:self.assertIn('8 tygodni',text)
                if e['id']==14229:
                    self.assertIn('żywic',text);self.assertNotIn('82001',text);self.assertNotIn('1.8',text)
        self.assertEqual('e5f06d854dc2d2ba824d3b670869f3988c36121d8f020519f8383a4ca9016e7f',M['prior_receipts'][1]['sha256'])

if __name__=='__main__':unittest.main()
