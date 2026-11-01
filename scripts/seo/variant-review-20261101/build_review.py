#!/usr/bin/env python3
import argparse,hashlib,json
from pathlib import Path
ROOT=Path(__file__).parent
# Claims are limited to own source text and the complete frozen variant configuration.
COPY={
7988:('Treska syntetyczna na klamrze – pasma 35–60 cm','Kręcona treska z włosa syntetycznego, mocowana jedną klamrą. Cieniowane pasma o długości 35–60 cm.',['pasma cieniowane od 35 do 60 cm','na jedną klamrę']),
11925:('Duomed smooth pończochy – XXL, krótkie, czarne','Pończochy kompresyjne Duomed smooth: rozmiar XXL, długość krótka, kolor czarny, palce zamknięte. Klasa ucisku CCL2.',['RozmiarXXLDługośćkrótka']),
11933:('Genumedi PSS – rozmiar V, standard, lyons blue','Stabilizator kolana Genumedi PSS w kolorze lyons blue. Rozmiar V, obwód standard.',['Kolorlyons blueObwódstandardRozmiarV']),
11956:('MAXIS MICRO pończochy – I, czarne, CCL2','Pończochy uciskowe MAXIS MICRO: rozmiar I, kolor czarny, długość normalna, palce zamknięte. Klasa ucisku CCL2.',['MAXIS Micro']),
11958:('MAXIS MICRO rajstopy – I, krótkie, brązowe','Rajstopy uciskowe MAXIS MICRO: rozmiar I, kolor brązowy, długość krótka, palce zamknięte. Klasa ucisku CCL2.',['Rajstopy MAXIS Micro']),
11966:('medi collar soft Kidz – III, 7 cm, niebieski','Kołnierz ortopedyczny medi collar soft Kidz: rozmiar III, głębokość 7 cm, kolor niebieski.',['RozmiarIIIGłębokość7 cm']),
11998:('mediven elegance pończochy – VII, kaszmirowe, CCL2','Pończochy uciskowe mediven elegance: rozmiar VII, kolor kaszmirowy, długość normalna, palce otwarte. Klasa ucisku CCL2.',['mediven']),
12010:('mediven sheer & soft rajstopy – I, czarne, 15–20 mmHg','Rajstopy uciskowe mediven sheer & soft: rozmiar I, kolor czarny, długość normalna, palce zamknięte. Ucisk 15–20 mmHg.',['RozmiarIDługośćnormalnaCCL15 - 20 mmHG']),
13717:('Dr Max płyn do szyb 500 ml – CH-221-S500-N08',None,['IndeksCH-221-S500-N08']),
13718:('Dr Max płyn do szyb 500 ml – CH-221-S500-Z01',None,['IndeksCH-221-S500-Z01']),
14106:('Matovlies – kompresy niejałowe 100 szt., 4 warstwy','Niejałowe kompresy z włókniny Matovlies, 100 sztuk. Warianty czterowarstwowe o wymiarach 5×5, 7,5×7,5 lub 10×10 cm.',['Warstwowość 4 Jałowość Niejałowe']),
14279:('Matovlies – kompresy niejałowe 100 szt., 4 lub 6 warstw','Niejałowe kompresy z włókniny Matovlies, 100 sztuk. Warianty: 10×20 cm, 4 warstwy lub 10×10 cm, 6 warstw. Torebka papierowo-foliowa.',['Warstwowość 4, 6','Opakowanie Torebka papierowo-foliowa']),
}

def snapshot(p):
 return [{'id':v['id'],'sku':v['sku'],'external_variant_id':v['external_variant_id'],'status':v['status'],'deleted_at':v['deleted_at'],'attributes':[{'attribute':a['attribute'],'value_id':a['value_id'],'value':a['value']} for a in sorted(v['attributes'],key=lambda a:a['value_id'])]} for v in sorted(p['variants'],key=lambda v:v['id'])]

def build(path):
 data=json.loads(path.read_text());products={p['id']:p for p in data['products']};entries=[];fixtures=[]
 for i,(title,desc,quotes) in sorted(COPY.items()):
  p=products[i];assert not p['description_truncated'] and not p['short_description_truncated']
  for q in quotes:assert q in p['description'],(i,q)
  for v in p['variants']:
   for a in v['attributes']:assert not a['value_truncated']
  snap=snapshot(p)
  entries.append({'id':i,'expected':{k:p[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},'source_description_sha256':p['description_sha256'],'source_short_description_sha256':p['short_description_sha256'],'after':{'seo_title':title,'seo_description':desc or p['seo_description']},'evidence':{'source':'own_body_and_frozen_variant_options','quotes':quotes,'variant_snapshot':snap}})
  fixtures.append({'id':i,'description':p['description'],'short_description':p['short_description'],'variants':p['variants']})
 prior=json.loads(Path('seo-duplicate-codes/review.json').read_text());done={e['id'] for e in prior['entries']};ledger=[]
 for g in data['groups']:
  if set(g['product_ids'])&done:continue
  changed=sorted(set(g['product_ids'])&COPY.keys())
  reason='variant_specific_offer' if any(i in changed for i in range(11925,12011)) else 'explicit_source_code' if 13717 in changed else 'different_variant_ranges' if 14106 in changed else 'neutral_copy_for_conflicting_model_identity' if 7988 in changed else 'held_for_identity_and_reference_review'
  ledger.append({'product_ids':g['product_ids'],'status':reason,'changed_product_ids':changed,'remaining_duplicate':not bool(changed)})
 m={'repair':'product-seo-editorial-20261031-v1','batch':'product-seo-variant-review-20261101-v1','prior_backup':'storage/app/private/seo-repairs/duplicate-codes-20261101.FLNjoA','prior_plan_sha256':'6c34bbfad5916164c634761a19b6c753537eff738f8c9646ed10dc3f9b01f758','source_export_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'entries':entries,'duplicate_review':ledger,'scope':'12 titles, 10 descriptions; expected remaining duplicate title groups 32. No model renaming, consolidation, status, URL, price or source-body changes.'}
 for name,value in [('review.json',m),('source-fixture.json',fixtures)]: (ROOT/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
 print('PRODUCTS',len(entries),'DESCRIPTIONS',sum(e['after']['seo_description']!=e['expected']['seo_description'] for e in entries),'REMAINING_GROUPS',sum(g['remaining_duplicate'] for g in ledger))
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('export',type=Path);args=parser.parse_args();build(args.export)
