#!/usr/bin/env python3
import hashlib,json,re
from pathlib import Path
ROOT=Path(__file__).parent
SOURCE=Path('upload/konji-seo-consolidation-review.json')
def build():
 d=json.loads(SOURCE.read_text());p={z['id']:z for z in d['products']};rows=d['catalogue_rows']
 assert len(p)==10 and len(rows['product_images'])==73 and rows['product_documents']==[] and rows['product_attribute_value_images']==[]
 pairs=[];aliases=json.load(open('seo-consolidation/aliases.json'))
 for target,source in [(12583,12619),(12587,12623),(12590,12626)]:
  imgs=lambda i:sorted((z['source_url'],z['sha256']) for z in rows['product_images'] if z['product_id']==i)
  assert imgs(target)==imgs(source)
  pair={role:{k:p[i][k] for k in ['id','slug','external_source','external_id']} for role,i in [('target',target),('source',source)]};pairs.append(pair);aliases['pairs'].append(pair)
 colours={9983:('czarna','czarnym','Podpórka RANGER Timago – czarna','Podpórka czterokołowa RANGER Timago w kolorze czarnym. Aluminiowa rama, siedzisko z oparciem i hamulce z blokadą postojową.'),9986:('biała','białym','Podpórka RANGER Timago – biała','Podpórka czterokołowa RANGER Timago w kolorze białym. Aluminiowa rama, siedzisko z oparciem i hamulce z blokadą postojową.'),10104:('czarna','czarnym','Laska składana JMC-C 2052 Timago – czarna','Laska aluminiowa składana JMC-C 2052 Timago w kolorze czarnym. Regulowana wysokość i drewniany uchwyt.'),10107:('brązowa','brązowym','Laska składana JMC-C 2052 Timago – brązowa','Laska aluminiowa składana JMC-C 2052 Timago w kolorze brązowym. Regulowana wysokość i drewniany uchwyt.')}
 updates=[{'id':i,'after':{'name':p[i]['name']+' – '+c[0],'seo_title':c[2],'seo_description':c[3]}} for i,c in colours.items()]
 for target,source,video in [(12583,12619,'https://www.youtube.com/watch?v=itS-NJZhgXc'),(12587,12623,'https://youtu.be/YcUgijz9nGU')]:
  assert video in p[source]['description'] and video not in p[target]['description']
  after=p[target]['description']+'\n<p><a href="'+video+'" target="_blank" rel="noopener noreferrer">Dodatkowy materiał wideo producenta</a></p>'
  updates.append({'id':target,'after':{'description':after}})
  # All source manuals/certificates already have counterparts at the same basename.
 for target,source in [(12583,12619),(12587,12623),(12590,12626)]:
  pdf=lambda i:{u.rsplit('/',1)[-1] for u in re.findall(r'href="([^"]+\.pdf)"',p[i]['description'])}
  assert pdf(source)<=pdf(target)
 remove=[59711,59712,59713,59714,59715]
 assert [z['id'] for z in rows['product_images'] if z['product_id']==10104 and z['id'] in remove]==remove
 frozen={'products':d['products'],**rows,'references':d['references']}
 manifest={'batch':'product-seo-consolidation-final-20261101-v1','prior_backup':d['review']['prior_backup'],'prior_plan_sha256':d['review']['prior_plan_sha256'],'source_export_sha256':hashlib.sha256(SOURCE.read_bytes()).hexdigest(),'expected_active_products':14621,'expected_duplicate_title_groups':13,'expected_after_active_products':14618,'expected_after_duplicate_title_groups':8,'pairs':pairs,'product_updates':updates,'remove_image_ids':remove,'frozen':frozen,'reference_inventory':d['reference_inventory'],'decision_correction':'Timago RANGER and JMC-C 2052 are distinct colour offers, not duplicates. Keep all four; archive only three equivalent Vicair records.','media_evidence':{'10104':{'source_url':'https://www.timago.com/pl/laska-inwalidzka-aluminiowa-skladana-model-jmc-c-2052.html','retained_image_ids':[59708,59709,59710],'excluded_colour_link_image_ids':[59711],'excluded_related_product_image_ids':[59712,59713,59714,59715]},'colour_sources':{'9983':'https://www.timago.com/pl/podporka-czterokolowa-aluminiowa-ranger.html','9986':'https://www.timago.com/pl/ranger-podporka-czterokolowa-aluminiowa.html','10104':'https://www.timago.com/pl/laska-inwalidzka-aluminiowa-skladana-model-jmc-c-2052.html','10107':'https://www.timago.com/pl/laska-inwalidzka-aluminiowa-skladana.html'}},'scope':'Three reversible archives with category union and guarded 301s; four Timago colour names/titles/descriptions; preserve two additional video links; remove five proven off-product gallery rows to private backup without deleting files. Preserve all variants, prices, source records, target sizing/training/manual content and previous aliases.'}
 manifest['media_evidence']['source_checks']=json.loads((ROOT/'source-evidence.json').read_text())
 (ROOT/'review.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n');(ROOT/'aliases.json').write_text(json.dumps(aliases,ensure_ascii=False,indent=2)+'\n');(ROOT/'source-fixture.json').write_text(json.dumps(frozen,ensure_ascii=False,indent=2)+'\n')
 print('ARCHIVES=3 COLOUR_OFFERS=4 CATEGORY_ADDITIONS=3 EXCLUDED_GALLERY_ROWS=5 PRESERVED_VIDEO_LINKS=2 EXPECTED_ACTIVE=14618 EXPECTED_GROUPS=8')
if __name__=='__main__':build()
