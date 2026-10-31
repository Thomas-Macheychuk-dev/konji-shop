#!/usr/bin/env python3
import json,unittest
from pathlib import Path
from unittest.mock import patch
import build_review as review


def fixture():
    row={'id':900001,'name':'Model ABC','slug':'model-abc','external_source':'fixture','external_id':'ABC',
         'seo_title':'Model ABC','seo_description':'Existing basic description.',
         'description':'Konstrukcja: dwie kieszenie.','short_description':'Model ABC','description_truncated':False}
    previous={'entries':[],'held_descriptions':[{'id':row['id']}],'duplicate_review':[]}
    return row,previous


class FollowupEvidenceTests(unittest.TestCase):
    def test_quote_must_belong_to_the_same_product(self):
        row,previous=fixture()
        with patch.dict(review.COPY,{row['id']:('Dwie kieszenie.','Fakt z innego produktu')},clear=True),patch.dict(review.EXTERNAL,{},clear=True),patch.dict(review.TITLES,{},clear=True):
            with self.assertRaises(AssertionError):review.build({'products':[row]},previous,'source')

    def test_previously_approved_product_cannot_be_replayed(self):
        row,previous=fixture();previous['entries']=[{'id':row['id']}]
        with patch.dict(review.COPY,{row['id']:('Dwie kieszenie.','dwie kieszenie')},clear=True),patch.dict(review.EXTERNAL,{},clear=True),patch.dict(review.TITLES,{},clear=True):
            with self.assertRaisesRegex(AssertionError,'replay'):review.build({'products':[row]},previous,'source')

    def test_copy_preserves_identity_and_uses_actual_source_hash(self):
        import hashlib
        row,previous=fixture()
        with patch.dict(review.COPY,{row['id']:('Dwie kieszenie.','dwie kieszenie')},clear=True),patch.dict(review.EXTERNAL,{},clear=True),patch.dict(review.TITLES,{},clear=True):
            manifest=review.build({'products':[row]},previous,'source')
        entry=manifest['entries'][0]
        self.assertEqual(row['external_id'],entry['expected']['external_id'])
        self.assertEqual(hashlib.sha256(row['description'].encode()).hexdigest(),entry['source_description_sha256'])
        self.assertEqual('Model ABC. Dwie kieszenie.',entry['after']['seo_description'])

    def test_manufacturer_variant_does_not_define_all_fabric_or_sleeve_options(self):
        manifest=json.loads((Path(__file__).parent/'review.json').read_text());entries={e['id']:e for e in manifest['entries']}
        for i in [517,522]:
            text=entries[i]['after']['seo_description'].casefold()
            self.assertNotIn('%',text);self.assertNotIn('rofinor',text)
            self.assertNotIn('krótki rękaw',text)
            self.assertTrue(entries[i]['evidence'][0]['url'].startswith('https://wojdak.pl/produkt/'))

    def test_distinct_models_and_dimensions_are_not_artificial_id_suffixes(self):
        manifest=json.loads((Path(__file__).parent/'review.json').read_text());entries={e['id']:e for e in manifest['entries']}
        self.assertIn('TGO-C OSN 408',entries[10226]['after']['seo_title'])
        self.assertIn('TGO-C OSN 407',entries[10232]['after']['seo_title'])
        self.assertIn('55×35×12 cm',entries[13201]['after']['seo_title'])
        self.assertIn('50×30×11 cm',entries[13246]['after']['seo_title'])

    def test_conflicting_and_test_records_remain_held(self):
        manifest=json.loads((Path(__file__).parent/'review.json').read_text());held={r['id'] for r in manifest['held_descriptions']}
        self.assertTrue({13274,12116,14177,13961,14345,14352,1876}.issubset(held))
        self.assertFalse(held & {e['id'] for e in manifest['entries']})


if __name__=='__main__':unittest.main()
