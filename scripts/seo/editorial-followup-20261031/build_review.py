#!/usr/bin/env python3
"""Build the second frozen editorial batch from the original export and prior ledger."""
import argparse,hashlib,json,re
from pathlib import Path
from reviewed_copy import COPY

EXTERNAL = {
517: {'suffix':'Zapięcie na nierdzewne napy. Dwie dolne kieszenie i kieszonka na piersi.',
      'url':'https://wojdak.pl/produkt/fartuch-damski-1071-krotki-rekaw-rofinor-11',
      'manufacturer_model':'1071', 'quote':'zapinany na nierdzewne springi (napy)', 'checked_at':'2026-10-31',
      'scope':'Common model construction; no fabric, colour or sleeve option inferred.'},
522: {'suffix':'Boczne gumy w pasie. Zapięcie na guzik i zamek. Rozcięcie z tyłu.',
      'url':'https://wojdak.pl/produkt/spodnica-3002-rofinor-279',
      'manufacturer_model':'3002', 'quote':'Posiada paski z gumami wszytymi po bokach', 'checked_at':'2026-10-31',
      'scope':'Common model construction; no fabric, colour or length option inferred.'},
}
TITLES = {
160: ('EVOFLEX Scholl – obuwie zielone', 'kolorze zielonym'),
166: ('EVOFLEX Scholl – obuwie żółte', 'kolorze żółtym'),
190: ('BAHIA FLIP-FLOP MED Scholl – bubblegum', 'kolorze różowej gumy balonowej'),
191: ('BAHIA FLIP-FLOP MED Scholl – tangerine', 'kolorze pomarańczowym (tangerine)'),
192: ('BAHIA FLIP-FLOP MED Scholl – lemon', 'kolorze lemon'),
208: ('LISBOA 2 STRAPS MED Scholl – klapki beżowe', 'kolorze beżowym'),
209: ('LISBOA 2 STRAPS MED Scholl – klapki czarne', 'kolorze czarnym'),
10226: ('Opaska elastyczna nadgarstka Timago TGO-C OSN 408', 'Kod produktuTGO-C OSN 408'),
10232: ('Opaska elastyczna nadgarstka Timago TGO-C OSN 407', 'Kod produktuTGO-C OSN 407'),
13201: ('Poduszka AloeVera Dream ARmedical – 55×35×12 cm', 'Wymiar: 55x35x12cm'),
13246: ('Poduszka AloeVera Dream ARmedical – 50×30×11 cm', 'Wymiar: 50x30x11cm'),
13203: ('Poduszka BAMBOO DREAM ARmedical – 50×30×10 cm', 'Wymiar: 50x30x10cm'),
13204: ('Poduszka BAMBOO DREAM ARmedical – 52×32×12 cm', 'Wymiar: 52x32x12cm'),
13211: ('Poduszka EXCLUSIVE DREAM ARmedical – 50×30×10 cm', 'Wymiar: 50x30x10cm'),
13212: ('Poduszka EXCLUSIVE DREAM ARmedical – 60×40×12 cm', 'Wymiar: 60x40x12cm'),
13243: ('Poduszka lędźwiowa EXCLUSIVE SUPPORT ARmedical – 34×33×12 cm', 'Wymiar: 34x33x12cm'),
13244: ('Poduszka lędźwiowa EXCLUSIVE SUPPORT ARmedical – 53×44×9 cm', 'Wymiar: 53x44x9cm'),
}


def build(data, previous, source_sha):
    products={r['id']:r for r in data['products']}
    held={r['id']:r for r in previous['held_descriptions']}
    prior={e['id'] for e in previous['entries']}
    assert not (set(COPY)|set(EXTERNAL)|set(TITLES)) & prior, 'Never replay or overwrite prior editorial changes'
    entries=[]
    for i in sorted(set(COPY)|set(EXTERNAL)|set(TITLES)):
        r=products[i];after={'seo_title':r['seo_title'],'seo_description':r['seo_description']};evidence=[]
        if i in COPY:
            assert i in held
            suffix,quote=COPY[i]
            assert quote.casefold() in r['description'].casefold(), (i,quote)
            subject=re.sub(r'\s*\|\s*Sklep$', '',r['seo_title']).strip().rstrip(' .')
            after['seo_description']=subject[0].upper()+subject[1:]+'. '+suffix
            evidence.append({'field':'description','quote':quote,'fact':suffix})
        elif i in EXTERNAL:
            assert i in held
            source=EXTERNAL[i]
            assert source['manufacturer_model'] in r['name']
            subject=r['seo_title'].strip().rstrip(' .')
            after['seo_description']=subject[0].upper()+subject[1:]+'. '+source['suffix']
            evidence.append({'field':'manufacturer_page',**source})
        if i in TITLES:
            title,quote=TITLES[i]
            assert quote.casefold() in r['description'].casefold(), (i,quote)
            after['seo_title']=title
            evidence.append({'field':'description','quote':quote,'fact':title})
        assert all(after.values()) and len(after['seo_title'])<=255 and len(after['seo_description'])<=300
        entries.append({'id':i,'expected':{k:r[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},
            'source_description_sha256':hashlib.sha256(r['description'].encode()).hexdigest(),
            'source_short_description_sha256':hashlib.sha256(r['short_description'].encode()).hexdigest(),
            'source_description_truncated':r['description_truncated'],'after':after,'evidence':evidence})
    remaining=[r for r in previous['held_descriptions'] if r['id'] not in COPY and r['id'] not in EXTERNAL]
    groups=[]
    for group in previous['duplicate_review']:
        if group['status'].startswith('resolved_'):continue
        changed=[i for i in group['product_ids'] if i in TITLES]
        unresolved=[i for i in group['product_ids'] if i not in TITLES]
        disposition='resolved_by_explicit_product_evidence' if len(unresolved)<2 else ('partially_distinguished' if changed else group['status'])
        groups.append({**group,'status':disposition,'changed_product_ids':changed,'remaining_same_title_ids':unresolved if len(unresolved)>1 else []})
    return {'repair':'product-seo-editorial-20261031-v1','batch':'product-seo-editorial-followup-20261031-v1',
        'source_sha256':source_sha,'previous_manifest_sha256':hashlib.sha256(json.dumps(previous,ensure_ascii=False,separators=(',',':')).encode()).hexdigest(),
        'previous_applied_backup':'storage/app/private/seo-repairs/editorial-20261031.laIBDn',
        'requires_previous_applied_plan_sha256':'0d90883956719cee4cfbf3805e3de024ea74c8e807d01669e113c54aef8ec762',
        'entries':entries,'held_descriptions':remaining,'duplicate_review':groups}


def main():
    a=argparse.ArgumentParser();a.add_argument('source');a.add_argument('previous');a.add_argument('output');args=a.parse_args()
    raw=Path(args.source).read_bytes();manifest=build(json.loads(raw),json.loads(Path(args.previous).read_text()),hashlib.sha256(raw).hexdigest())
    Path(args.output).write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'changed_products':len(manifest['entries']),'description_updates':len(COPY)+len(EXTERNAL),'title_updates':len(TITLES),'held_descriptions':len(manifest['held_descriptions']),'remaining_duplicate_groups':sum(bool(r['remaining_same_title_ids']) for r in manifest['duplicate_review'])}))

if __name__=='__main__': main()
