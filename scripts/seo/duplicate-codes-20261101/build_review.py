#!/usr/bin/env python3
"""Use explicit own-description catalogue codes, never database IDs or guessed colours."""
import argparse,hashlib,json
from pathlib import Path
ROOT=Path(__file__).parent
TITLES={
9447:('Opaski na kolana KS-18 – para','SKUKS-18'),9448:('Opaski na kolana KS-16 – para','SKUKS-16'),
9449:('Stabilizator kolana 1-02-G – 2 szt.','SKU1 02 G'),9735:('Stabilizator kolana 1-03-G – 2 szt.','SKU1 03 G'),9736:('Stabilizator kolana 1-03-O – 2 szt.','SKU1 03 O'),9737:('Stabilizator kolana 1-02-O – 2 szt.','SKU1 02 O'),
9551:('Stabilizator łokcia 3613-UNI','SKU3613 UNI'),9552:('Stabilizator łokcia WS-03-UNI','SKUWS-03 UNI'),9553:('Stabilizator łokcia WS-12','SKUWS-12'),
9562:('Szyna na palec F-03','SKUF-03'),9563:('Szyna na palec OSN-FS5','SKUOSN-FS5'),
9590:('Korektor postawy A-38-UNI','SKUA-38 UNI'),9751:('Korektor postawy 1-07-UNI','SKU1 07 UNI'),
9612:('Opaski na kolana 4632, rozmiar XL – para','SKU2x4632 XL'),9613:('Opaski na kolana 4632, rozmiar L – para','SKU2x4632 L'),
9625:('Stolik przyłóżkowy CM-3003GIN','SKUCM 3003GIN'),9636:('Stolik przyłóżkowy CM-3003WHT','SKUCM 3003WHT'),
9630:('Bandaż elastyczny Tynor M-03','SKUM-03'),9631:('Bandaż elastyczny Tynor M-02','SKUM-02'),9632:('Bandaż elastyczny Tynor M-04','SKUM-04'),
9713:('Laska składana CM-1001BLK','SKUCM 1001BLK'),9714:('Laska składana CM-1001TAL','SKUCM 1001TAL'),
9738:('Stabilizator kolana 4-01-UNI-O','SKU4 01 UNI O'),9739:('Stabilizator kolana 4-01-UNI-G','SKU4 01 UNI G'),
9743:('Stabilizator łokcia 3-02-UNI-G','SKU3 02 UNI G'),9744:('Stabilizator łokcia 3-02-UNI-O','SKU3 02 UNI O'),
9749:('Stabilizator barku 4-04-UNI-O','SKU4 04 UNI O'),9750:('Stabilizator barku 4-04-UNI-G','SKU4 04 UNI G'),
9752:('Opaski na nadgarstki 4-08-UNI-G – 2 szt.','SKU4 08 UNI G'),9753:('Opaski na nadgarstki 4-08-UNI-O – 2 szt.','SKU4 08 UNI O'),
9754:('Opaski na nadgarstki 3-01-UNI-O – 2 szt.','SKU3 01 UNI O'),9755:('Opaski na nadgarstki 3-01-UNI-G – 2 szt.','SKU3 01 UNI G'),
9758:('Pas brzuszny neoprenowy 4-07-UNI-O','SKU4 07 UNI O'),9759:('Pas brzuszny neoprenowy 4-07-UNI-G','SKU4 07 UNI G'),
9760:('Pas brzuszny neoprenowy 4-06-UNI-G','SKU4 06 UNI G'),9761:('Pas brzuszny neoprenowy 4-06-UNI-O','SKU4 06 UNI O'),
9766:('Rękawiczki sportowe 9-04 – para','SKU9 04'),9767:('Rękawiczki sportowe 9-07 – para','SKU9 07'),
}

def build(data, source_path):
    assert data['database_writes'] is False and data['summary']['duplicate_title_groups']==59
    products={p['id']:p for p in data['products']};entries=[];fixtures=[]
    for i,(title,quote) in sorted(TITLES.items()):
        p=products[i]
        assert p['external_source']=='medreha' and not p['description_truncated'] and not p['short_description_truncated']
        assert quote in p['description'],(i,quote)
        for f in ['description','short_description']:
            assert hashlib.sha256(p[f].encode()).hexdigest()==p[f+'_sha256']
        entries.append({'id':i,'expected':{k:p[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},'source_description_sha256':p['description_sha256'],'source_short_description_sha256':p['short_description_sha256'],'after':{'seo_title':title,'seo_description':p['seo_description']},'evidence':{'source':'own_product_description','quote':quote,'scope':'Explicit catalogue code, independently visible in active variant SKUs. Code suffixes are preserved without colour/material/clinical interpretation. No claim that SKU differences alone prove different physical products.'}})
        fixtures.append({'id':i,'description':p['description'],'short_description':p['short_description']})
    ledger=[]
    for g in data['groups']:
        changed=set(g['product_ids'])&set(TITLES)
        assert not changed or changed==set(g['product_ids'])
        ledger.append({'product_ids':g['product_ids'],'original_title':g['title'],'status':'resolved_by_explicit_description_codes' if changed else 'held_for_identity_review','changed_product_ids':sorted(changed),'identity_signal':g['identity_signal'],'option_signal':g['option_signal']})
    assert sum(g['status']=='resolved_by_explicit_description_codes' for g in ledger)==17
    m={'repair':'product-seo-editorial-20261031-v1','batch':'product-seo-duplicate-codes-20261101-v1','prior_backup':'storage/app/private/seo-repairs/evidence-followup-20261101.NJPKen','prior_plan_sha256':'1ce76a8015190eabb03e6c6a46b89d6984e1ae6115f6fd9388394d5a1de2d897','checked_at':'2026-11-01','source_export_sha256':hashlib.sha256(source_path.read_bytes()).hexdigest(),'scope':'Titles only. Existing SEO descriptions preserved. No product consolidation, canonical/URL/status/price/variant/category changes. 29 unresolved basic descriptions remain outside this batch.','entries':entries,'duplicate_review':ledger}
    for name,value in [('review.json',m),('source-fixture.json',fixtures)]:
        (ROOT/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
    print('PRODUCTS',len(entries),'RESOLVED_GROUPS',17,'REMAINING_GROUPS',42)

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('export',type=Path);args=parser.parse_args()
    build(json.loads(args.export.read_text()),args.export)
