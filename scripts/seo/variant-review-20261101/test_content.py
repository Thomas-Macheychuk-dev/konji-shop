#!/usr/bin/env python3
import gzip,hashlib,importlib.util,json,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())
spec=importlib.util.spec_from_file_location('reference_download',ROOT/'download.py');download=importlib.util.module_from_spec(spec);spec.loader.exec_module(download)
class ContentTests(unittest.TestCase):
 def test_frozen_scope_and_variant_claims(self):
  path=ROOT.parents[2]/'tests/Fixtures/Seo/variant-review-20261101.json'
  if not path.exists():path=ROOT/'source-fixture.json'
  rows={p['id']:p for p in json.loads(path.read_text())}
  self.assertEqual(12,len(M['entries']));self.assertEqual(10,sum(e['after']['seo_description']!=e['expected']['seo_description'] for e in M['entries']))
  self.assertEqual(42,len(M['duplicate_review']));self.assertEqual(32,sum(g['remaining_duplicate'] for g in M['duplicate_review']))
  for e in M['entries']:
   p=rows[e['id']]
   for f in ['description','short_description']:self.assertEqual(hashlib.sha256(p[f].encode()).hexdigest(),e['source_'+f+'_sha256'])
   for q in e['evidence']['quotes']:self.assertIn(q,p['description'])
   if e['id'] in [11925,11933,11956,11958,11966,11998,12010]:
    active=[v for v in p['variants'] if v['status']=='active' and v['deleted_at'] is None];self.assertEqual(1,len(active))
    values={a['attribute']:a['value'].casefold() for a in active[0]['attributes']}
    self.assertIn(values['Rozmiar'].casefold(),e['after']['seo_title'].casefold())
    colour=values['Kolor'];stem={'czarny':'czarn','brązowy':'brązow','niebieski':'niebiesk','kaszmirowy':'kaszmirow'}.get(colour,colour);self.assertIn(stem,e['after']['seo_title'].casefold())
   self.assertNotIn('sale',e['after']['seo_title'].casefold())
  entries={e['id']:e for e in M['entries']};self.assertNotIn('WINE',entries[7988]['after']['seo_title']);self.assertNotIn('SANGRIA',entries[7988]['after']['seo_title'])
  # Accessory recommendation colours are not evidence for the Reh4mat product.
  self.assertNotIn(9066,entries);self.assertNotIn(9067,entries);self.assertNotIn(12021,entries)
 def test_readonly_transport_and_integrity(self):
  files={n:b'<?php' for n in ['export.php','DuplicateExport.php','ReferenceAudit.php','Metadata.php']}
  command=download.export_command(files,'konji-shop-prod-app-1');self.assertNotIn(' apply',command)
  with self.assertRaises(ValueError):download.export_command(files,'app; unsafe')
  data={'version':download.VERSION,'database_writes':False,'products':[],'groups':[],'references':[],'summary':{}};raw=json.dumps(data).encode();payload=gzip.compress(raw,mtime=0)
  meta={'path':'/tmp/konji-seo-editorial.ABC123/review.json.gz','bytes':len(payload),'json_bytes':len(raw),'sha256':hashlib.sha256(payload).hexdigest(),'json_sha256':hashlib.sha256(raw).hexdigest()}
  class Client:
   def read_chunk(self,path,offset,length):return payload[offset:offset+length]
  with tempfile.TemporaryDirectory() as d:
   target=Path(d)/'reference.json';download.transport.download_export(Client(),meta,target,version=download.VERSION,collections=('products','groups','references'));self.assertEqual(raw,target.read_bytes())
   meta['sha256']='0'*64
   with self.assertRaises(ValueError):download.transport.download_export(Client(),meta,Path(d)/'bad.json',version=download.VERSION,collections=('products','groups','references'))
   self.assertFalse((Path(d)/'bad.json').exists())
if __name__=='__main__':unittest.main()
