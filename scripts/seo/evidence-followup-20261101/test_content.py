#!/usr/bin/env python3
import gzip,hashlib,importlib.util,json,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).parent
M=json.loads((ROOT/'review.json').read_text())
spec=importlib.util.spec_from_file_location('duplicate_download',ROOT/'download.py');download=importlib.util.module_from_spec(spec);spec.loader.exec_module(download)

class EvidenceTests(unittest.TestCase):
    def test_frozen_evidence_and_scope(self):
        fixture=ROOT.parents[2]/'tests/Fixtures/Seo/evidence-followup-20261101.json'
        if not fixture.exists():fixture=ROOT/'source-fixture.json'
        rows={p['id']:p for p in json.loads(fixture.read_text())}
        self.assertEqual(23,len(M['entries']))
        self.assertEqual(29,len(M['held_descriptions']))
        self.assertEqual(59,sum(bool(g['remaining_same_title_ids']) for g in M['duplicate_review']))
        for group in M['duplicate_review']:
            if group['status']=='resolved_by_explicit_product_codes':self.assertTrue(set(group['product_ids'])<=set(group['changed_product_ids']))
        for e in M['entries']:
            for f in ['description','short_description']:self.assertEqual(hashlib.sha256(rows[e['id']][f].encode()).hexdigest(),e['source_'+f+'_sha256'])
            self.assertEqual({'seo_title','seo_description'},set(e['after']))
            if e['evidence']['source']=='wojdak':
                self.assertIn(e['evidence']['model'],e['expected']['name'])
                for x in ['%','rękaw','rofinor','biały','zielony']:self.assertNotIn(x,e['after']['seo_description'].casefold())
            if e['evidence']['source']=='own_product_body':
                self.assertIn(e['evidence']['quote'],rows[e['id']]['description'])
                self.assertNotIn('kg',e['after']['seo_title']);self.assertNotIn('kg',e['after']['seo_description'])
        entries={e['id']:e for e in M['entries']}
        for i in [10432,10434]:self.assertIn('bez pompy',entries[i]['after']['seo_description'])
        self.assertNotIn('LADY PREGNA',entries[14345]['after']['seo_description'])
        self.assertNotIn('ciąży',entries[14352]['after']['seo_description'])

    def test_downloader_uses_new_schema_and_validates_payload_before_saving(self):
        data={'version':download.VERSION,'database_writes':False,'products':[],'groups':[],'summary':{}}
        raw=json.dumps(data).encode();payload=gzip.compress(raw,mtime=0)
        meta={'path':'/tmp/konji-seo-editorial.ABC123/review.json.gz','bytes':len(payload),'json_bytes':len(raw),'sha256':hashlib.sha256(payload).hexdigest(),'json_sha256':hashlib.sha256(raw).hexdigest()}
        class Client:
            def read_chunk(self,path,offset,length):return payload[offset:offset+length]
        with tempfile.TemporaryDirectory() as d:
            target=Path(d)/'review.json'
            download.transport.download_export(Client(),meta,target,version=download.VERSION,collections=('products','groups'))
            self.assertEqual(raw,target.read_bytes())
            with self.assertRaises(FileExistsError):download.transport.download_export(Client(),meta,target,version=download.VERSION,collections=('products','groups'))
            meta['sha256']='0'*64
            with self.assertRaises(ValueError):download.transport.download_export(Client(),meta,Path(d)/'bad.json',version=download.VERSION,collections=('products','groups'))
            self.assertFalse((Path(d)/'bad.json').exists())

    def test_export_bundle_cannot_inject_shell_arguments(self):
        files={k:b'<?php' for k in ['export.php','DuplicateExport.php','Metadata.php']}
        with self.assertRaises(ValueError):download.export_command(files,'app; echo unsafe')
        with self.assertRaises(ValueError):download.export_command({'../export.php':b'x'},'app')
        command=download.export_command(files,'konji-shop-prod-app-1')
        self.assertNotIn('repair.sh',command);self.assertNotIn(' apply',command)

if __name__=='__main__':unittest.main()
