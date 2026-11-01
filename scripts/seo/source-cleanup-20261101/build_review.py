#!/usr/bin/env python3
import hashlib,html,json
from pathlib import Path

ROOT=Path(__file__).parent
DATA=ROOT.parent/'upload/konji-seo-editorial-review.json'
MED={12116:('1139','10 × 10 cm',1),12117:('1140','10 × 20 cm',1),12118:('1137','5 × 5 cm',1),12119:('1138','7,5 × 7,5 cm',1),12157:('1139','10 × 10 cm',25),12158:('1140','10 × 20 cm',25),12159:('1137','5 × 5 cm',25),12160:('1138','7,5 × 7,5 cm',25)}
SOURCES={
 'meditrade':{'url':'https://www.meditrade.de/wp-content/uploads/Meditrade_Katalog_DE_2020-09.pdf','printed_page':69,'checked_at':'2026-11-01','facts':'References 1137–1140: sterile four-layer nonwoven compresses, packed in pairs, external wound care. Retail pack, percentages and precautions come from each supplied product record.'},
 'octenilin':{'url':'https://www.schuelke.com/pl-pl/products/910826/octenilin-roztw-r-do-irygacji-ran/','checked_at':'2026-11-01','reference':'121701 / GTIN 4032651217014','facts':'Sterile 350 ml solution, ingredients, eight-week opening life and external-use restrictions. No gel or allergy-tolerance claim.'},
 'scotchcast':{'url':'https://www.solventum.com/en-hk/home/v/v000142703/','checked_at':'2026-11-01','facts':'Scotchcast Plus family: fiberglass, water-activated resin, orthopaedic cast construction. No size/colour/carton details from the displayed 82001 variant.'},
}

def paragraphs(*text):return ''.join('<p>'+html.escape(t,quote=False)+'</p>' for t in text)

def build(data, prior_manifests):
    products={p['id']:dict(p) for p in data['products']}
    for manifest in prior_manifests:
        for e in manifest['entries']:products[e['id']].update(e['after'])
    entries=[]
    for i in sorted(set(MED)|{179,240,259,13961,14229}):
        p=products[i];assert not p['description_truncated']
        if i in [179,240,259]:
            after={'short_description':p['seo_description']}
            evidence={'source':'own_product_body_and_applied_seo','note':'Use the already reviewed SEO description. Product 259 remains colour-neutral.'}
        elif i in MED:
            ref,size,pairs=MED[i]
            assert 'MED'+ref in p['description']
            assert any(v['sku']=='MED'+ref+('-1' if pairs==1 else '') for v in p['default_variant_identifiers'])
            assert '65% wiskoza i 35% poliester' in p['description']
            pack='Jedna torebka zawierająca 2 kompresy.' if pairs==1 else 'Opakowanie zawierające 25 torebek po 2 kompresy (łącznie 50 sztuk).'
            after={'description':paragraphs('Jałowe kompresy włókninowe BSV Meditrade. Cztery warstwy, rozmiar '+size+'.',
                'Włóknina: 65% wiskozy i 35% poliestru. Kompresy są przeznaczone do zewnętrznego opatrywania ran.',
                pack, 'Produkt jednorazowy. Nie używać ponownie ani przy uszkodzonej torebce. Po otwarciu wykorzystać bezpośrednio. Chronić przed zalaniem i nadmiernym nasłonecznieniem.',
                'Przed użyciem zapoznać się z etykietą i instrukcją producenta.')}
            evidence={'source':'meditrade','manufacturer_ref':ref,'supplier_code':'MED'+ref+('-1' if pairs==1 else ''),'retail_pairs':pairs,'size':size}
        elif i==13961:
            assert '4032651217014' in p['description']
            after={'description':paragraphs('Octenilin Schulke to sterylny roztwór do oczyszczania i nawilżania ran. Butelka 350 ml.',
                'Zawiera wodę oczyszczoną, glicerol, etyloheksyloglicerynę oraz dichlorowodorek oktenidyny.',
                'Według producenta roztwór zachowuje właściwości przez 8 tygodni po pierwszym otwarciu. Po użyciu zamknąć butelkę.',
                'Wyłącznie do użytku zewnętrznego. Nie połykać, nie wstrzykiwać ani nie podawać we wlewie. Nie używać przy podejrzeniu uczulenia na składnik preparatu.',
                'Stosować zgodnie z instrukcją producenta, z uwzględnieniem podanych w niej ograniczeń i przeciwwskazań.', 'Indeks produktu: NN-MSH-DDRA-001.')}
            evidence={'source':'octenilin','gtin':'4032651217014'}
        else:
            assert 'Scotchcast Plus' in p['name']
            after={'description':paragraphs('Scotchcast Plus to syntetyczna opaska do wykonywania ortopedycznych opatrunków unieruchamiających.',
                'Materiał stanowi włókno szklane z żywicą aktywowaną wodą. Opaska ma porowaty splot i jest lekka.',
                'Oferta dotyczy 1 sztuki. Rozmiar i kolor należy dobrać wśród wariantów produktu.',
                'Produkt do stosowania przez personel medyczny zgodnie z instrukcją producenta.', 'Indeks produktu: NN-M3M-GS05-001.')}
            evidence={'source':'scotchcast','scope':'Family construction only; retail single unit comes from the existing product name.'}
        entries.append({'id':i,'expected':{k:p[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},
            'source_description_sha256':hashlib.sha256(p['description'].encode()).hexdigest(),
            'source_short_description_sha256':hashlib.sha256(p['short_description'].encode()).hexdigest(),
            'after':after,'evidence':evidence})
    return {'repair':'product-source-cleanup-20261101-v1','prior_receipts':[
        {'backup':'storage/app/private/seo-repairs/manufacturer-review-20261101.PJiAHp','sha256':'65e966fb030abbe992e4484d12d57ea9f28baf4f0a064f37d23908eba3f68298'},
        {'backup':'storage/app/private/seo-repairs/colour-correction-20261101.lJGpbE','sha256':'e5f06d854dc2d2ba824d3b670869f3988c36121d8f020519f8383a4ca9016e7f'}],
        'sources':SOURCES,'entries':entries,'detach_attribute_values':[{'product_id':i,'attribute_value_id':v['value_id'],'attribute_id':v['attribute_id'],'expected_value':v['value']} for i in [13961,14229] for v in products[i]['product_attributes'] if v['attribute_id']==498]}

if __name__=='__main__':
    data=json.loads(DATA.read_text());old=[json.loads((ROOT.parent/n/'review.json').read_text()) for n in ['seo-editorial-repair','seo-editorial-followup','seo-manufacturer-review','seo-colour-correction']]
    manifest=build(data,old);(ROOT/'review.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    ids={e['id'] for e in manifest['entries']}
    (ROOT/'source-fixture.json').write_text(json.dumps([{k:p[k] for k in ['id','description','short_description','product_attributes']} for p in data['products'] if p['id'] in ids],ensure_ascii=False,indent=2)+'\n')
    print('PRODUCTS=13 LONG=10 SHORT=3 SEO_FIELDS_CHANGED=0')
