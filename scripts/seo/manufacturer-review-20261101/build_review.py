#!/usr/bin/env python3
"""Frozen third batch; only explicit per-product evidence, never infer variants."""
import argparse, hashlib, json, re
from pathlib import Path

BATCH='product-seo-manufacturer-review-20261101-v1'
# Facts paraphrased from the linked primary sources. Option-specific fabric,
# sleeve, dimensions and packaging are deliberately excluded.
SOURCES={
518: ('Zapięcie na napy. Dwie dolne kieszenie i kieszonka na piersi.', 'https://wojdak.pl/produkt/fartuch-damski-1062-dlugi-rekaw-rofinor-44', '1062', 'Zapinany jest na trwałe nierdzewne springi (napy)'),
528: ('Dekolt V ze stójką. Zapięcie na napy, dwie dolne kieszenie i kieszonka na piersi.', 'https://wojdak.pl/produkt/tunika-damska-2117-krotki-rekaw-rofinor-179', '2117', 'Dekolt w kształcie litery V z delikatną stójką'),
538: ('Kryte zapięcie na napy. Dwie dolne kieszenie i kieszonka na piersi.', 'https://wojdak.pl/produkt/zakiet-damski-2091-krotki-rekaw-rofinor-99', '2091', 'Kryte zapięcie na trwałe nierdzewne springi (napy)'),
460: ('Klapki medyczne damskie ze skóry ekologicznej.', 'https://eldan.pl/klapki-medyczne-damskie', 'KD MED 135', 'klapki medyczne ze skóry ekologicznej'),
}
MEDICAL={
13702: ('Granugel Convatec 15 g. Jałowy opatrunek hydrokoloidowy w postaci żelu.', 'https://www.convatec.com/pl-pl/produkty/leczenie-ran-i-ochrona-skory/rodzaj-rany/leczenie-ran-rodzaj-rany-odlezyna-stopien-i-e-ii/e1a37604-517a-46d3-97c0-7bb2404598b5/', 'Granugel', 'sterylny opatrunek hydrokoloidowy w formie żelu'),
13721: ('Medisorb A. Jałowy opatrunek wykonany z włókien alginianu wapnia. Wymaga zabezpieczenia opatrunkiem wtórnym.', 'https://medisorb.pl/produkt/medisorb-a', 'Medisorb A', 'w 100% z włókien alginianu wapnia'),
13961: ('Octenilin Schulke 350 ml. Roztwór do oczyszczania i nawilżania ran. Zawiera dichlorowodorek oktenidyny, glicerol i etyloheksyloglicerynę.', 'https://www.schuelke.com/pl-pl/products/910826/octenilin-roztw-r-do-irygacji-ran/', 'Octenilin', 'oktenidyny dichlorowodorek, etyloheksylogliceryna, glicerol, woda oczyszczona'),
14229: ('Syntetyczna opaska Scotchcast Plus. Włókno szklane z żywicą aktywowaną wodą.', 'https://www.solventum.com/en-hk/home/v/v000142703/', 'Scotchcast Plus', 'Water-activated resin'),
14321: ('Granudacyn Mölnlycke. Roztwór do płukania ran z wodą, chlorkiem sodu, kwasem podchlorawym i podchlorynem sodu.', 'https://www.molnlycke.com.au/SysSiteAssets/master-and-local-markets/regulatory-requirements/granudacyn/ifu_e00398_v0_molnlycke_granudacyn_wound_irrigation_npwt.pdf', 'Granudacyn', 'woda, sodu chlorek, kwas podchlorawy, sodu podchloryn'),
}
# Manufacturer catalogue, printed p69, sterile 4-layer table. Imported supplier
# prefixes/suffixes are NOT taken as manufacturer identifiers without the exact
# numeric reference appearing in the guarded description as well.
MEDITRADE={12116:'1139',12117:'1140',12118:'1137',12119:'1138',12157:'1139',12158:'1140',12159:'1137',12160:'1138'}
TITLES={
174:('LUSAKA 2.0 MED Scholl – klapki ciemnoniebieskie','ciemno niebieskie'),
181:('LUSAKA 2.0 MED Scholl – klapki białe','białe klapki'),
182:('LUSAKA 2.0 MED Scholl – klapki czarne','Czarne.'),
176:('NEW BOGOTA WEDGE MED Scholl – klapki czarne','czarne klapki'),
222:('NEW BOGOTA WEDGE MED Scholl – klapki czerwone','czerwone klapki'),
179:('MOLDAVA WEDGE AD MED Scholl – klapki jasnoróżowe','jasnoróżowe'),
186:('MOLDAVA WEDGE AD MED Scholl – klapki bordowe','Bordowe'),
227:('HEAVEN AD MED Scholl – sandały granatowe','Granatowe sandały'),
232:('HEAVEN AD MED Scholl – sandały czerwone','Czerwone, skórzane sandały'),
239:('RIO WEDGE AD MED Scholl – klapki jasnoniebieskie','jasnoniebieskie'),
240:('RIO WEDGE AD MED Scholl – klapki jasnozielone','jasnozielone'),
250:('ANTONIA ELASTIC MED Scholl – light taupe','kolor light taupe'),
260:('ABERDEEN MAN MED Scholl – klapki granatowe','z granatowego nubucku'),
}
BODY_CONFLICTS={**{i:'Body says non-sterile; manufacturer sterile reference matches guarded body code. Correct the body separately.' for i in MEDITRADE},13961:'Body mixes gel/solution, sterility and opening-life claims. Manufacturer GTIN 4032651217014 matches guarded body. Correct body separately.',14229:'Body claims traditional gypsum composition; manufacturer identifies fiberglass/resin. Correct body separately.'}

def build(data, first, previous, source_sha):
    products={p['id']:p for p in data['products']}
    prior={e['id'] for m in [first,previous] for e in m['entries']}
    targets=set(SOURCES)|set(MEDICAL)|set(MEDITRADE)|set(TITLES)
    assert not prior & targets, 'Never replay previously applied products'
    held={r['id'] for r in previous['held_descriptions']}
    desc_ids=set(SOURCES)|set(MEDICAL)|set(MEDITRADE)
    assert desc_ids<=held
    entries=[]
    for i in sorted(targets):
        r=products[i]; after={k:r[k] for k in ['seo_title','seo_description']}; evidence=[]
        if i in SOURCES or i in MEDICAL:
            fact,url,model,quote=(SOURCES|MEDICAL)[i]
            assert model.casefold() in r['name'].casefold(), (i,model)
            after['seo_description']=fact if i in MEDICAL else r['seo_title'].rstrip(' .')+'. '+fact
            evidence.append({'field':'manufacturer_page','url':url,'manufacturer_model':model,'quote':quote,'checked_at':'2026-11-01','scope':'Family/model construction only; no variant option or stock claim.'})
        if i in MEDITRADE:
            ref=MEDITRADE[i]
            assert 'MED'+ref in r['description'], (i,ref)
            assert any(re.fullmatch('MED'+ref+r'(?:-1)?',v['sku']) for v in r['default_variant_identifiers'])
            assert '65% wiskoza i 35% poliester' in r['description']
            subject=re.sub(r'\s*\|\s*Sklep$', '',r['seo_title']).rstrip(' .')
            after['seo_description']=subject+'. Skład włókniny: 65% wiskozy i 35% poliestru.'
            evidence.append({'field':'description','quote':'65% wiskoza i 35% poliester','fact':'Material percentages from this product, not a clinical claim.'})
            evidence.append({'field':'manufacturer_catalogue','url':'https://www.meditrade.de/wp-content/uploads/Meditrade_DE_Katalog_2020.pdf','printed_page':69,'reference':ref,'table':'einfach steril; 4-fach; zu 2 Stück verpackt','checked_at':'2026-11-01','scope':'Sterile reference reconciles contradictory non-sterile body heading. Retail pack quantity remains as supplied.'})
        if i==13961: assert '4032651217014' in r['description']
        if i in TITLES:
            title,quote=TITLES[i]; assert quote.casefold() in r['description'].casefold(), (i,quote)
            after['seo_title']=title
            evidence.append({'field':'description','quote':quote,'fact':title})
        if i==13721: after['seo_title']='Medisorb A – opatrunek z alginianu wapnia'
        assert after!= {k:r[k] for k in after}
        assert all(after.values()) and len(after['seo_title'])<=255 and len(after['seo_description'])<=300
        entries.append({'id':i,'expected':{k:r[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},'source_description_sha256':hashlib.sha256(r['description'].encode()).hexdigest(),'source_short_description_sha256':hashlib.sha256(r['short_description'].encode()).hexdigest(),'source_description_truncated':r['description_truncated'],'after':after,'evidence':evidence})
    groups=[]
    for g in previous['duplicate_review']:
        remaining=g.get('remaining_same_title_ids',[])
        if not remaining: continue
        unresolved=[i for i in remaining if i not in TITLES]
        groups.append({**g,'changed_product_ids':sorted(set(g.get('changed_product_ids',[])) | (set(remaining)&set(TITLES))),'remaining_same_title_ids':unresolved if len(unresolved)>1 else [],'status':'resolved_by_explicit_product_evidence' if len(unresolved)<2 else ('partially_distinguished' if set(remaining)&set(TITLES) else g['status'])})
    return {'repair':'product-seo-editorial-20261031-v1','batch':BATCH,'source_sha256':source_sha,'previous_batches':[
        {'manifest':'editorial-repair-20261031/review.json','backup':'storage/app/private/seo-repairs/editorial-20261031.laIBDn','plan_sha256':'0d90883956719cee4cfbf3805e3de024ea74c8e807d01669e113c54aef8ec762'},
        {'manifest':'editorial-followup-20261031/review.json','backup':'storage/app/private/seo-repairs/editorial-followup-20261031.DIgHOK','plan_sha256':'882ffe06d2236e163b9b1c6a64d65708fd569e331f6a36d3c4b4050f4d7f9857'}],
        'entries':entries,'held_descriptions':[r for r in previous['held_descriptions'] if r['id'] not in desc_ids], 'duplicate_review':groups,'body_description_followup':[{'id':i,'issue':s} for i,s in sorted(BODY_CONFLICTS.items())]}

if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('source');a.add_argument('first');a.add_argument('previous');a.add_argument('output');args=a.parse_args()
    raw=Path(args.source).read_bytes();m=build(json.loads(raw),json.loads(Path(args.first).read_text()),json.loads(Path(args.previous).read_text()),hashlib.sha256(raw).hexdigest())
    Path(args.output).write_text(json.dumps(m,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'targets':len(m['entries']),'descriptions':len(SOURCES)+len(MEDICAL)+len(MEDITRADE),'titles':len(TITLES)+1,'held':len(m['held_descriptions']),'duplicate_groups':sum(bool(g['remaining_same_title_ids']) for g in m['duplicate_review'])}))
