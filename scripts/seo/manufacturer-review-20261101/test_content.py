#!/usr/bin/env python3
import hashlib,json,unittest
from pathlib import Path
from unittest.mock import patch
import build_review as review

ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())

class ManufacturerEvidenceTests(unittest.TestCase):
    def test_no_prior_changes_are_replayed_and_both_receipts_are_required(self):
        previous_ids=set()
        for item in M['previous_batches']:
            path=ROOT.parent/item['manifest'].split('/')[0]/'review.json'
            # Local build layout differs from the repository layout.
            if not path.exists():
                path=ROOT.parent/({'editorial-repair-20261031':'seo-editorial-repair','editorial-followup-20261031':'seo-editorial-followup'}[item['manifest'].split('/')[0]])/'review.json'
            previous_ids.update(e['id'] for e in json.loads(path.read_text())['entries'])
        self.assertFalse(previous_ids & {e['id'] for e in M['entries']})
        self.assertEqual(2,len(M['previous_batches']))
        self.assertEqual('882ffe06d2236e163b9b1c6a64d65708fd569e331f6a36d3c4b4050f4d7f9857',M['previous_batches'][1]['plan_sha256'])

    def test_manufacturer_options_do_not_become_product_wide_claims(self):
        entries={e['id']:e for e in M['entries']}
        for i in [518,528,538]:
            text=entries[i]['after']['seo_description'].casefold()
            for excluded in ['rofinor','%','krótki rękaw','długi rękaw']:
                self.assertNotIn(excluded,text)
        self.assertNotIn('82001',entries[14229]['after']['seo_description'])
        self.assertNotIn('tradycyjn',entries[14229]['after']['seo_description'])
        self.assertNotIn('hydrożel',entries[13961]['after']['seo_description'])
        self.assertNotIn('5 szt',entries[13721]['after']['seo_description'])

    def test_meditrade_references_need_both_same_product_body_and_sku(self):
        row={'id':12116,'name':'Kompres Meditrade','seo_title':'Kompres Meditrade', 'seo_description':'Old.',
             'description':'MED1139-1 65% wiskoza i 35% poliester','default_variant_identifiers':[{'sku':'MED1139-1'}],
             'slug':'kompres','external_source':'vaya','external_id':'946','short_description':'','description_truncated':False}
        previous={'entries':[],'held_descriptions':[{'id':12116}],'duplicate_review':[]}
        # Keep only the selected product so a mismatched code cannot be masked
        # by another record or a default variant from another product.
        with patch.dict(review.SOURCES,{},clear=True),patch.dict(review.MEDICAL,{},clear=True),patch.dict(review.MEDITRADE,{12116:'1139'},clear=True),patch.dict(review.TITLES,{},clear=True):
            result=review.build({'products':[row]}, {'entries':[]},previous,'source')
            self.assertEqual([12116],[e['id'] for e in result['entries']])
            row['default_variant_identifiers'][0]['sku']='MED1789'
            with self.assertRaises(AssertionError):review.build({'products':[row]}, {'entries':[]},previous,'source')
            row['default_variant_identifiers'][0]['sku']='MED1139-1';row['description']='MED1789 65% wiskoza i 35% poliester'
            with self.assertRaises(AssertionError):review.build({'products':[row]}, {'entries':[]},previous,'source')

    def test_real_duplicates_and_conflicts_are_not_fabricated_into_unique_records(self):
        entries={e['id']:e for e in M['entries']}
        self.assertTrue({164,165,257,266,259,13274,14177,14345,14352,1876}.isdisjoint(entries))
        self.assertTrue({13274,14177,14345,14352,1876}<={r['id'] for r in M['held_descriptions']})
        self.assertEqual(set(review.MEDITRADE)|{13961,14229},{r['id'] for r in M['body_description_followup']})
        self.assertEqual(61,sum(bool(g['remaining_same_title_ids']) for g in M['duplicate_review']))

if __name__=='__main__': unittest.main()
