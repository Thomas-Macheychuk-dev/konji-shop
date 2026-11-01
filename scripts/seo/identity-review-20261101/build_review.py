#!/usr/bin/env python3
"""Build a bounded identity correction from reviewed primary-source facts."""
import hashlib,json,pathlib
ROOT=pathlib.Path(__file__).parent
COPY={
1419:('CARA SMALL DELUXE – kod 4048924102482','Peruka syntetyczna CARA SMALL DELUXE. Mały rozmiar. Kod produktu 4048924102482.'),
4530:('CARA SMALL DELUXE – kod 4048924643855','Peruka syntetyczna CARA SMALL DELUXE. Mały rozmiar. Kod produktu 4048924643855.'),
164:('EVOFLEX Scholl – obuwie czarne','EVOFLEX Scholl w kolorze czarnym. Kod produktu F293781004.'),
165:('EVOFLEX Scholl – obuwie granatowe','EVOFLEX Scholl w kolorze granatowym. Kod produktu F293781040.'),
220:('AIR BAG MED Scholl – MF215311014, fuksja','Klapki AIR BAG MED Scholl. Kod produktu MF215311014, kolor fuksja.'),
221:('AIR BAG MED Scholl – klapki granatowe','Klapki AIR BAG MED Scholl w kolorze granatowym. Kod produktu MF215311017.'),
244:('AIR BAG MED Scholl – klapki czarne','Klapki AIR BAG MED Scholl w kolorze czarnym. Kod produktu MF215311004.'),
247:('AIR BAG MED Scholl – klapki brązowe','Klapki AIR BAG MED Scholl w kolorze brązowym. Kod produktu F215311016.'),
234:('HEAVEN AD MED Scholl – sandały czarne','Sandały HEAVEN AD MED Scholl w kolorze czarnym. Kod produktu MF230091004.'),
237:('HEAVEN AD MED Scholl – sandały brązowe','Sandały HEAVEN AD MED Scholl w kolorze brązowym. Kod produktu F230091011.'),
257:('Nautilus Scholl – MF305791040, granatowe','Granatowe klapki Nautilus Scholl. Kod produktu MF305791040.'),
266:('Nautilus Scholl – F243541040, granatowe','Granatowe klapki Nautilus Scholl. Kod produktu F243541040.'),
2828:('ALETTA MONO PART – peruka rubyred/mix','Peruka syntetyczna termoodporna ALETTA MONO PART w kolorze rubyred/mix. Kod produktu 4048924683066.'),
2829:('ALETTA MONO PART – peruka silkgrey/mix','Peruka syntetyczna termoodporna ALETTA MONO PART w kolorze silkgrey/mix. Kod produktu 4048924683073.'),
6744:('ALASKA – peruka hotchocolate/mix','Peruka syntetyczna ALASKA w kolorze hotchocolate/mix. Kod produktu 4048924197204.'),
7951:('FRAPPE – treska dark blonde','Treska syntetyczna FRAPPE w kolorze dark blonde, mocowana klamrą. Kod produktu 4048924615524.'),
7952:('FRAPPE – treska dark brown','Treska syntetyczna FRAPPE w kolorze dark brown, mocowana klamrą. Kod produktu 4048924615562.'),
9066:('Reh4Mat AM-OSN-U-02 – orteza szara','Szara orteza nadgarstka Reh4Mat AM-OSN-U-02. Otwarta konstrukcja z regulowanymi zapięciami.'),
9067:('Reh4Mat AM-OSN-U-02 – orteza czarna','Czarna orteza nadgarstka Reh4Mat AM-OSN-U-02. Otwarta konstrukcja z regulowanymi zapięciami.'),
9092:('Reh4Mat OKG-25 – temblak czarny','Czarny uniwersalny temblak Reh4Mat OKG-25 z regulowanym zapięciem.'),
9093:('Reh4Mat OKG-25 – temblak szary','Szary uniwersalny temblak Reh4Mat OKG-25 z regulowanym zapięciem.'),
9094:('Reh4Mat – separator neurologiczny szary','Szary separator neurologiczny Reh4Mat, zakładany na dłoń.'),
9095:('Reh4Mat – separator neurologiczny czarny','Czarny separator neurologiczny Reh4Mat, zakładany na dłoń.'),
9620:('Nasadka na sedes MASO 08002 – 10 cm','Nasadka na sedes MASO 08002 z klapą. Wysokość 10 cm.'),
9940:('Nasadka na sedes OrthoVisions – 5, 10 lub 15 cm','Nasadka na sedes OrthoVisions z klapą. Oferta obejmuje warianty wysokości 5, 10 i 15 cm.'),
13026:('Wysoka orteza piersiowo-lędźwiowo-krzyżowa – beż, S–XXL','Wysoka orteza piersiowo-lędźwiowo-krzyżowa z regulacją. Oferta w kolorze beżowym, w rozmiarach S, M, L, XL i XXL.'),
13064:('Wysoka orteza piersiowo-lędźwiowo-krzyżowa – XS–XXXL','Wysoka orteza piersiowo-lędźwiowo-krzyżowa z regulacją. Oferta w rozmiarach XS, S, M, L, XL, XXL i XXXL.'),
}
NAMES={2828:('Peruka syntetyczna termoodporna ALETTA MONO PART rubyred/mix','Peruka syntetyczna ALETTA MONO PART – zdjęcie produktu'),6744:('Peruka syntetyczna ALASKA hotchocolate/mix','Peruka syntetyczna ALASKA – zdjęcie produktu'),7951:('Treska syntetyczna FRAPPE dark blonde','Treska syntetyczna FRAPPE – zdjęcie produktu')}
PERUKA={2828:'aletta-mono-part-rubyred-mix',2829:'aletta-mono-part-silkgrey-mix',6744:'alaska-hotchocolate-mix-peruka-syntetyczna',7951:'frappe-dark-blonde-dopinka',7952:'frappe-dark-brown-dopinka'}
REH={9066:1188,9067:313671,9092:313813,9093:316311,9094:41493,9095:290460}
DECISIONS={
0:('distinct_colours','Keep both; source parent IDs/codes and colour attributes identify black versus navy.'),
1:('distinct_colours','Keep four. Exact shop parent IDs identify different source catalogue codes. 220 is labelled fuksja at source; its cherry slug is not evidence for colour. The similar Mobilex air-bag-3 page is a different code and is not used.'),
2:('distinct_colours','Keep both; source code/colour fields identify black versus brown.'),
3:('distinct_source_catalogue_references','Keep both navy offers; MF305791040 and F243541040 are different source catalogue references. Do not deduplicate by colour/name.'),
4:('distinct_source_codes_price_identity_hold','CARA pages publish different MPN values, 4048924102482 and 4048924643855, and prices differ (91650 versus 183300 grosz). Keep both offers and differentiate exact source codes. Confirm packaging/generation and price policy before any consolidation; shared model/colour is not enough.'),
5:('distinct_selected_colours','Selected source swatches/source MPNs identify rubyred/mix versus silkgrey/mix despite repeated source headlines. Correct 2828 name and matching image text.'),
6:('one_verified_colour_companion_unavailable','7139 is selected hotchocolate/mix with its own source MPN. Correct 6744 name and image text. The old 7175 honey page is currently unavailable; do not change 6745 price/status or infer equivalence.'),
7:('distinct_selected_colours','Selected source swatches and source MPNs identify dark blonde versus dark brown. Correct 7951 name and matching image text.'),
8:('design_generation_confirmation','Manufacturer photographs show different frame/hinge designs as well as colour. Retain both SPARTAN records until the manufacturer confirms generation/configuration equivalence.'),
9:('distinct_manufacturer_colour_pages','Reciprocal manufacturer colour selectors link grey and black AM-OSN-U-02 pages. Colour is visually reviewed on main product photographs, not inferred from recommended accessories or a pediatric URL segment.'),
10:('distinct_manufacturer_colour_pages','Reciprocal manufacturer colour selectors and main photographs identify 9092 black and 9093 grey OKG-25. Keep both.'),
11:('distinct_manufacturer_colour_pages','Reciprocal colour selectors and main photographs identify grey and black separators. Keep both.'),
12:('duplicate_candidate_missing_identity','Same FP-25 copy and unpriced draft variants, but no images and no numeric source product IDs. Obtain the exact source product identity before choosing a canonical record.'),
13:('distinct_brand_and_assortment','MASO 08002 single 10 cm offer versus OrthoVisions 5/10/15 cm assortment. Own body and producer attributes agree. Keep both; do not merge based on generic title.'),
14:('duplicate_confirmed_prepare_consolidation','Both current Timago pages identify RANGER with the same specification. Keep complete galleries/categories and validate live references when preparing a reversible merge; different image URLs are not a distinct model.'),
15:('duplicate_confirmed_prepare_consolidation','Both current Timago pages identify JMC-C 2052 with identical copy/attributes. External ID 2052 is not proof of manufacturer identity by itself. Preserve the larger gallery before consolidation.'),
16:('kit_configuration_confirmation','Shared source EAN 619498638802, but symbols 63880/63816 versus 63880 and different draft option sets. Confirm complete kit versus cushion/frame component mapping with supplier; no invented mask distinction.'),
17:('duplicate_candidate_priced_offer','Same AT04702 photographs/copy, but the copy-slug record has the active priced variant and the clean-slug record is unpriced. Confirm source catalogue code/offer mapping; do not archive the purchasable record or silently choose its price.'),
18:('same_variant_price_decision','Equal active options and same source product imagery, but 5600 versus 20000 grosz. Business decision on clearance versus regular offers is required before consolidation.'),
19:('duplicate_candidate_specification_confirmation','PEPI imagery and attributes agree; one body additionally gives dimensions/load. Confirm those are the same configuration and preserve complete information before consolidation.'),
20:('duplicate_confirmed_prepare_consolidation','Vicair Active O2 model, sampled manufacturer images, attributes and dimensions agree; copy differences are punctuation and orientation labels. Require live complete gallery/reference checks before archival.'),
21:('duplicate_confirmed_prepare_consolidation','Vicair Allrounder O2 shares sampled images/model; additional copy explains how to measure the same size range. Preserve useful instructions and full galleries during consolidation.'),
22:('model_code_conflict','Liberty Back body codes LBS/1–3 versus LBC/1–3 conflict. Identical sampled imagery is insufficient to choose whether a cover or configuration differs. Ask manufacturer/supplier before merging.'),
23:('duplicate_candidate_configuration_confirmation','Anatomic Back sampled images/model agree, but rewritten copy adds size/order claims. Confirm complete size/configuration equivalence and preserve verified facts before consolidation.'),
24:('duplicate_confirmed_prepare_consolidation','Multifunctional Back shares sampled images/model/dimensions; one body adds only a training-link paragraph. Require full live media/reference checks and preserve data in the consolidation plan.'),
25:('distinct_current_assortments_model_unconfirmed','Current S–XXL beige assortment differs from XS–XXXL assortment without a colour option. Differentiate the actual offers; supplier model identity still needs confirmation. No inference that an internal SIGVARIS SKU is a manufacturer model code.'),
}
def snapshot(p):
 return [{'id':v['id'],'sku':v['sku'],'external_variant_id':v['external_variant_id'],'status':v['status'],'deleted_at':v['deleted_at'],'attributes':[{k:a[k] for k in ['attribute','value_id','value']} for a in sorted(v['attributes'],key=lambda a:a['value_id'])]} for v in sorted(p['variants'],key=lambda v:v['id'])]
def build():
 data=json.load(open('upload/konji-seo-reference-review.json'));products={p['id']:p for p in data['products']};old={p['id']:p for p in json.load(open('upload/konji-seo-editorial-review.json'))['products']}
 source_rows=json.load(open(ROOT/'scholl-source-evidence.json'));shop={str(r['id']):r for r in source_rows};entries=[];fixtures=[];proofs=[]
 for i,(title,description) in sorted(COPY.items()):
  p=products[i];assert not p['description_truncated'] and not p['short_description_truncated']
  samples=[x['source_url'] for x in old[i]['images']];assert samples
  evidence={'variant_snapshot':snapshot(p),'media_samples':samples,'producer_attributes':[{k:a[k] for k in ['attribute','value_id','value']} for a in p['product_attributes']]}
  if p['external_source']=='mobilex':
   row=shop[p['external_id']];assert row['id']==int(p['external_id']);assert samples[0] in row['images'];evidence['source']='source_shop_product_id';evidence['primary_url']=row['url'];evidence['primary_identity']={'id':row['id'],'permalink':row['permalink'],'attributes':[a for a in row['attributes'] if a['name'] in ['Kod','Kolor','Rodzaj']]}
  elif i in [1419,4530]:
   slug='cara-small-deluxe-14-12-peruka-syntetyczna' if i==1419 else 'cara-small-deluxe-darksand-mix-14-12-peruka-syntetyczna'
   row=json.load(open(ROOT/(slug+'-identity.json')));assert row['id']==[p['external_id']] and len(row['gtin'])==1
   evidence['source']='source_product_id_and_mpn';evidence['primary_identity']={'url':row['url'],'canonical':row['canonical'],'product_id':row['id'][0],'source_mpn':row['gtin'][0]}
  elif i in PERUKA:
   row=json.load(open(ROOT/(PERUKA[i]+'-identity.json')));assert row['product_id']==p['external_id'];assert row['canonical']==[row['url']];evidence['source']='source_selected_colour_and_mpn';evidence['primary_identity']={k:v for k,v in row.items() if k!='gtin'};evidence['primary_identity']['source_mpn']=row['gtin']
  elif i in REH:
   row=json.load(open(ROOT/f'reh4mat-{REH[i]}-identity.json'));evidence['source']='manufacturer_colour_selector_and_visual_review';evidence['primary_identity']={k:row[k] for k in ['id','url','canonical','title','colour_selector_links']};evidence['primary_identity']['colour_selector_links']=row['colour_selector_links'][:1];evidence['reviewed_main_photo']=samples[0];evidence['reviewed_photo_sha256']=hashlib.sha256(pathlib.Path(f'/tmp/konji-identity-{i}.jpg').read_bytes()).hexdigest();evidence['inference']='Colour observed on the main manufacturer product photograph with reciprocal colour-selector linkage. No age, clinical or construction distinction inferred.'
  else:evidence['source']='own_body_and_frozen_assortment'
  entry={'id':i,'expected':{k:p[k] for k in ['name','slug','external_source','external_id','seo_title','seo_description']},'source_description_sha256':p['description_sha256'],'source_short_description_sha256':p['short_description_sha256'],'after':{'seo_title':title,'seo_description':description},'evidence':evidence}
  if i in NAMES:entry['after_name'],entry['after_image_caption']=NAMES[i]
  entries.append(entry);fixtures.append(p|{'images':old[i]['images']})
  proofs.append({'product_id':i,'evidence':{k:v for k,v in evidence.items() if k!='variant_snapshot'}})
 held=json.load(open('seo-consolidation/review.json'))['held_groups'];review=[]
 for n,g in enumerate(held):
  status,action=DECISIONS[n];changed=sorted(set(g['product_ids'])&COPY.keys());review.append({'product_ids':g['product_ids'],'title_before':g['title'],'decision':status,'action':action,'metadata_correction_ids':changed,'title_group_resolved_by_this_batch':bool(changed),'requires_further_identity_or_business_review':n in [4,6,8,12,16,17,18,19,22,23,25]})
 m={'repair':'product-seo-editorial-20261031-v1','batch':'product-seo-identity-review-20261101-v1','prior_backup':'storage/app/private/seo-repairs/consolidation-20261101.NoHbjP','prior_plan_sha256':'d6bca0e0b9dd1f6ef21535ae1ef7638000a8215ba0501b0cb32a0f2b8ccab7e2','reviewed_at':'2026-11-01','entries':entries,'identity_review':review,'scope':'27 title/description corrections, three wrong colour names and only image caption text matching those wrong names. No archive, pricing, stock, variant, source body, slug, category or redirect changes. Expected remaining duplicate-title groups: 13; this does not imply remaining physical identity questions are solved.'}
 for name,value in [('review.json',m),('source-fixture.json',fixtures),('source-evidence.json',proofs)]: (ROOT/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
 print('REVIEWED_GROUPS',len(review),'PRODUCTS',len(entries),'NAME_CORRECTIONS',len(NAMES),'TITLE_GROUPS_RESOLVED',sum(g['title_group_resolved_by_this_batch'] for g in review),'REMAINING_TITLE_GROUPS',sum(not g['title_group_resolved_by_this_batch'] for g in review))
if __name__=='__main__':build()
