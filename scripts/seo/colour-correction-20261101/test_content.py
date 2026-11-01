#!/usr/bin/env python3
import hashlib,json,unittest
from pathlib import Path
ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())

class ColourRegressionTests(unittest.TestCase):
    def test_current_metadata_is_the_applied_prior_batch_not_the_old_export(self):
        path=ROOT.parent/'manufacturer-review-20261101/review.json'
        if not path.exists():path=ROOT.parent/'seo-manufacturer-review/review.json'
        previous={e['id']:e for e in json.loads(path.read_text())['entries']}
        fixtures=ROOT.parents[2]/'tests/Fixtures/Seo/colour-correction-20261101.json'
        if not fixtures.exists():fixtures=ROOT/'source-fixture.json'
        source={p['id']:p for p in json.loads(fixtures.read_text())}
        for e in M['entries']:
            if e['id'] in previous:
                self.assertEqual(e['expected']['seo_title'],previous[e['id']]['after']['seo_title'])
                self.assertEqual(e['expected']['seo_description'],previous[e['id']]['after']['seo_description'])
            for field in ['description','short_description']:
                self.assertEqual(hashlib.sha256(source[e['id']][field].encode()).hexdigest(),e['source_'+field+'_sha256'])

    def test_colour_conflicts_are_removed_without_touching_titles(self):
        self.assertEqual({179,240,259},{e['id'] for e in M['entries']})
        for e in M['entries']:
            if e['id']==259:
                for colour in ['granat','brąz']: self.assertNotIn(colour,e['after']['seo_description'].casefold())
                self.assertIn('Memory Cushion',e['after']['seo_description'])
                self.assertEqual(e['expected']['seo_title'],e['after']['seo_title'])
                continue
            colour,wrong={179:('jasnoróżowe','jasnoniebieskim'),240:('jasnozielone','różowe')}[e['id']]
            self.assertIn(colour,e['after']['seo_title'].casefold())
            self.assertIn(colour,e['after']['seo_description'].casefold())
            self.assertNotIn(wrong,e['after']['seo_description'].casefold())
            self.assertEqual(e['expected']['seo_title'],e['after']['seo_title'])
            self.assertEqual({'seo_title','seo_description'},set(e['after']))

if __name__=='__main__':unittest.main()
