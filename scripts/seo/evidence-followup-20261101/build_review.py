#!/usr/bin/env python3
"""Build the next disjoint SEO batch using explicit model labels and product codes."""
import argparse,hashlib,json
from pathlib import Path

WOJDAK={523:('3004',15,'Pas z gumą.','pasek+guma'),524:('3017',17,'Pas z gumą.','pasek+guma'),525:('3018',19,'Model z pasem.','pasek'),526:('4038',20,'Model z pasem.','pasek'),540:('4039',20,'Model z pasem.','pasek'),541:('4005',18,'Elastyczny pas.','guma'),542:('4037',16,'Pas z gumą.','pasek+guma'),543:('4040',15,'Pas z gumą.','pasek+guma'),544:('4034',19,'Pas z gumą.','pasek + guma'),545:('8730',22,'Podwójne kieszenie.','podwójne kieszenie'),574:('7614',30,'Elastyczny pas.','guma'),575:('7640',29,'Pas z gumą.','pasek+guma')}
MEDICAL={
10432:('Materac BioFlote 2000 Reha Fund bez pompy. Konstrukcja bąbelkowa z PVC.','bioflote','SymbolBF 2000/M'),
10434:('Materac HF6001 Reha Fund bez pompy. Konstrukcja bąbelkowa z PVC.','hf6001','SymbolHF6001'),
13843:('Schulke Wound Pad – 12 jałowych gąbek poliuretanowych. Zestaw trzech kolorów o różnej strukturze porów.','wound_pad','4031463460106'),
13944:('SURGISPON – żelatynowa gąbka hemostatyczna o porowatej strukturze. Sterylizowana promieniowaniem gamma. Oferta: 1 sztuka.','surgispon','Aegis Lifesciences'),
13957:('Granuflex Bordered Convatec. Jałowy opatrunek hydrokoloidowy z szerokim obramowaniem piankowym, bez dodatkowego mocowania plastrem.','granuflex','Granuflex Bordered'),
14345:('FootWave MAMA. Profilowane wkładki ortopedyczne dla kobiet w ciąży.','mama','LADY PREGNA'),
14352:('FootWave LADY VERSA – wkładki do kozaków, półbutów i obuwia na obcasie. Profilowane korytko wkładki.','lady_versa','FootWave LADY VERSA'),
}
CRUTCHES={9715:('2XCM 1010BLU','CM-1010BLU','para'),9728:('2XCM 1011','CM-1011','para'),9729:('CM 1010BLU','CM-1010BLU','pojedyncza'),9730:('CM 1011','CM-1011','pojedyncza')}
SOURCES={
'wojdak':{'url':'https://wojdak.pl/wp-content/uploads/2023/05/Katalog-odziezy-medycznej-WOJDAK.pdf','scope':'Parsed model-specific labels only. No screenshot inspection, fabric, colour or sleeve-option inference.'},
'bioflote':{'url':'https://rehafund.pl/wp-content/uploads/rehafund-katalog-2023-update.pdf','page':110,'scope':'Mattress family bubble construction and PVC only; retail without-pump offer comes from the own product name/code. No pump specification or refund claim.'},
'hf6001':{'url':'https://sklep.rehafund.pl/file/4854721/_.pdf','scope':'HF6001 mattress material/construction only; do not import the bundled pump.'},
'wound_pad':{'url':'https://www.schuelke.com/pl-pl/products/912821/schuelke-wound-pad-g%C4%85bki%20do%20oczyszczania%20ran/','reference':'146010; GTIN 4031463460106','scope':'Sterile PUR sponges, three structures; retail 12-unit GTIN matched.'},
'surgispon':{'url':'https://aegis-lifesciences.com/surgispon/','scope':'Gelatin, porosity and gamma sterilization. Single-unit retail offer comes from own name; no manufacturer carton or clinical-benefit claim.'},
'granuflex':{'url':'https://www.convatec.com/en-ie/products/advanced-wound-care/brand-names/pc-wound-duoderm-granluflex/b9fa68b5-0165-4584-9ff9-2e7c67c4d83c/','scope':'Bordered family only; no pack quantity/dimension copied from displayed international variants.'},
'mama':{'url':'https://footwave.pl/wkladki-ortopedyczne/footwave-mama/','scope':'Current manufacturer product headline confirms MAMA and pregnancy audience. No renamed-model assertion or clinical promises from LADY PREGNA prose.'},
'lady_versa':{'url':'https://footwave.pl/wkladki-ortopedyczne/footwave-lady-versa/','scope':'LADY VERSA-specific introduction only. Do not use the mismatched LADY PREGNA feature heading.'},
}

def build(data, previous):
    products={p['id']:p for p in data['products']}
    prior={e['id'] for m in previous for e in m['entries']}
    targets=set(WOJDAK)|set(MEDICAL)|set(CRUTCHES)
    assert not prior&targets,'Never replay previously changed products'
    entries=[]
    for i in sorted(targets):
        r=products[i];after={k:r[k] for k in ['seo_title','seo_description']}
        if i in WOJDAK:
            model,page,suffix,label=WOJDAK[i];assert model in r['name']
            if i==544:after['seo_title']='Spodnie S4034 Wojdak'
            after['seo_description']=after['seo_title'].rstrip(' .')+'. '+suffix
            evidence={'source':'wojdak','model':model,'printed_page':page,'label':label}
        elif i in MEDICAL:
            copy,key,quote=MEDICAL[i];assert quote.casefold() in r['description'].casefold(),(i,quote)
            after['seo_description']=copy;evidence={'source':key,'own_identity_quote':quote}
        else:
            code,model,pack=CRUTCHES[i]
            assert 'SKU'+code in r['description'],(i,code)
            assert '74-96,5 cm' in r['description']
            assert model in r['default_variant_identifiers'][0]['sku']
            subject=('Kule łokciowe' if pack=='para' else 'Kula łokciowa')+' Ortho Visions '+model
            after['seo_title']=subject+(' – para' if pack=='para' else '')
            after['seo_description']=subject+'. '+('Zestaw 2 sztuk. ' if pack=='para' else '')+'Regulacja wysokości 74–96,5 cm. Konstrukcja aluminiowa.'
            evidence={'source':'own_product_body','quote':'SKU'+code,'scope':'Source product code plus existing retail pack. No load rating or colour inferred from contaminated attributes.'}
        assert after!= {k:r[k] for k in after}
        assert len(after['seo_title'])<=255 and len(after['seo_description'])<=300
        entries.append({'id':i,'expected':{k:r[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},'source_description_sha256':hashlib.sha256(r['description'].encode()).hexdigest(),'source_short_description_sha256':hashlib.sha256(r['short_description'].encode()).hexdigest(),'after':after,'evidence':evidence})
    latest=previous[-3] # manufacturer-review ledger, before colour/source-only batches
    held=[r for r in latest['held_descriptions'] if r['id'] not in set(WOJDAK)|set(MEDICAL)]
    groups=[]
    for g in latest['duplicate_review']:
        if not g['remaining_same_title_ids']:continue
        remaining=[i for i in g['remaining_same_title_ids'] if i not in CRUTCHES]
        groups.append({**g,'remaining_same_title_ids':remaining if len(remaining)>1 else [],'status':'resolved_by_explicit_product_codes' if len(remaining)<2 else g['status']})
    return {'repair':'product-seo-editorial-20261031-v1','batch':'product-seo-evidence-followup-20261101-v1','prior_backup':'storage/app/private/seo-repairs/source-cleanup-20261101.iGilON','prior_plan_sha256':'6d873cfb78350ea580f80d2c763576237cea64af6c9d28e40ff5bae81c29f827','checked_at':'2026-11-01','sources':SOURCES,'entries':entries,'held_descriptions':held,'duplicate_review':groups}

if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('source',type=Path);a.add_argument('output',type=Path);a.add_argument('--prior-root',type=Path,default=Path(__file__).parent.parent);a.add_argument('--fixture-output',type=Path);args=a.parse_args()
    names=['editorial-repair-20261031','editorial-followup-20261031','manufacturer-review-20261101','colour-correction-20261101','source-cleanup-20261101'];old=[json.loads((args.prior_root/n/'review.json').read_text()) for n in names]
    data=json.loads(args.source.read_text());m=build(data,old);args.output.write_text(json.dumps(m,ensure_ascii=False,indent=2)+'\n')
    if args.fixture_output:
        ids={e['id'] for e in m['entries']};args.fixture_output.write_text(json.dumps([{k:p[k] for k in ['id','description','short_description']} for p in data['products'] if p['id'] in ids],ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'products':len(m['entries']),'description_updates':len(m['entries']),'title_updates':len(CRUTCHES)+1,'held_descriptions':len(m['held_descriptions']),'remaining_duplicate_groups':sum(bool(g['remaining_same_title_ids']) for g in m['duplicate_review'])}))
