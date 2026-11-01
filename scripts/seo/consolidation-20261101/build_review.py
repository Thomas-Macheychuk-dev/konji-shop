#!/usr/bin/env python3
import hashlib,json
from pathlib import Path
ROOT=Path(__file__).parent
PAIRS=[(12424,12427),(12581,12617),(12582,12618),(12584,12620),(12585,12621),(12586,12622)]
def build():
 path=Path('upload/konji-seo-reference-review.json');data=json.loads(path.read_text());p={p['id']:p for p in data['products']};refs={r['product_id']:r for r in data['references']}
 old={r['id']:r for r in json.load(open('upload/konji-seo-editorial-review.json'))['products']};pairs=[];fixtures=[];aliases=[]
 for target,source in PAIRS:
  a,b=p[target],p[source]
  assert a['description']==b['description'] and not a['description_truncated'] and not b['description_truncated']
  assert a['product_attributes']==b['product_attributes']
  images=lambda r:sorted(set(i['source_url'] for i in r['images']))
  assert images(old[target])==images(old[source]) and images(old[target])
  pair={'manufacturer_image_samples':images(old[target])}
  for role,i in [('target',target),('source',source)]:
   z=p[i];assert z['active_variant_count']==0 and len(z['variants'])==1
   assert all(v['price_gross_amount'] is None and v['price_net_amount'] is None for v in z['variants'])
   pair[role]={k:z[k] for k in ['id','name','slug','external_source','external_id','seo_title','seo_description','description_sha256','short_description_sha256']}
   pair[role]['attribute_value_ids']=[v['value_id'] for v in z['product_attributes']]
   assert not any(v['value_truncated'] for v in z['product_attributes'])
   pair[role]['attribute_evidence']=[{k:v[k] for k in ['attribute','value_id','value']} for v in z['product_attributes']]
   pair[role]['variant_sku']=z['variants'][0]['sku']
   pair[role]['categories']=[{'category_id':c['id'],'is_primary':bool(c['is_primary'])} for c in sorted(old[i]['categories'],key=lambda c:c['id'])]
   pair[role]['references']=refs[i]['references']
   fixtures.append(z|{'categories':pair[role]['categories'],'image_evidence':old[i]['images'],'attribute_image_evidence':old[i]['attribute_images']})
  pairs.append(pair)
  aliases.append({'source':{k:b[k] for k in ['id','slug','external_source','external_id']},'target':{k:a[k] for k in ['id','slug','external_source','external_id']}})
 manifest={'batch':'product-seo-consolidation-20261101-v1','prior_backup':'storage/app/private/seo-repairs/variant-review-20261101.NmgeeB','prior_plan_sha256':'604ca4387852727450f392ffb34eb4a0afed5bb77cec02991977e0b19ffe07d6','source_export_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'expected_active_products':14627,'pairs':pairs,'held_groups':[g for g in data['groups'] if not set(g['product_ids'])&{i for pair in PAIRS for i in pair}],'reference_inventory':data['reference_inventory'],'scope':'Archive six duplicate products, retain every source row/variant/media record, add missing non-primary target category links, serve guarded same-host 301 aliases. No deletion, price, variant, primary or legacy nginx-map changes.'}
 for name,value in [('review.json',manifest),('aliases.json',{'version':1,'pairs':aliases}),('source-fixture.json',fixtures)]: (ROOT/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n')
 print('PAIRS=6 REMAINING_GROUPS=26 EXPECTED_ACTIVE_PRODUCTS=14621')
if __name__=='__main__':build()
