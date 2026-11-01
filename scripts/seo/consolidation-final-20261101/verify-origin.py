#!/usr/bin/env python3
"""Run on the shop server: verify origin redirects, canonicals, colours and sitemap, without writes."""
import json
from html.parser import HTMLParser
from pathlib import Path
import subprocess
import tempfile
from urllib.parse import urljoin
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[3]

class CanonicalParser(HTMLParser):
    def __init__(self):
        super().__init__();self.urls=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='link' and 'canonical' in a.get('rel','').split():self.urls.append(a.get('href'))


def request(host,path,directory):
    url='https://'+host+path
    header=directory/'headers';body=directory/'body'
    response=subprocess.run(['curl','--silent','--show-error','--max-time','40','--resolve',host+':443:127.0.0.1','--dump-header',str(header),'--output',str(body),'--write-out','%{http_code}',url],capture_output=True,text=True,check=True)
    headers={}
    for line in header.read_text().splitlines():
        if ':' in line:
            key,value=line.split(':',1);headers.setdefault(key.lower(),[]).append(value.strip())
    return int(response.stdout),headers,body.read_text()


def main():
    aliases=json.loads((ROOT/'resources/seo/ortezka/reviewed-product-aliases-20261101.json').read_text())['pairs']
    review=json.loads((Path(__file__).parent/'review.json').read_text())
    colours={p['id']:p for p in review['frozen']['products'] if p['id'] in [9983,9986,10104,10107]}
    if len(aliases)!=9 or len(colours)!=4:raise RuntimeError('Unexpected alias/colour scope')
    with tempfile.TemporaryDirectory(prefix='konji-origin-verify.') as temporary:
        directory=Path(temporary)
        for host in ['staging.ortezka.pl','ortezka.pl']:
            for pair in aliases:
                source='/products/'+pair['source']['slug'];target='/products/'+pair['target']['slug']
                status,headers,_=request(host,source,directory)
                if status!=301 or len(headers.get('location',[]))!=1 or urljoin('https://'+host+source,headers['location'][0])!='https://'+host+target:
                    raise RuntimeError('Incorrect source redirect: '+host+source)
                status,headers,body=request(host,target,directory)
                parser=CanonicalParser();parser.feed(body)
                if status!=200 or parser.urls!=['https://'+host+target]:raise RuntimeError('Target/canonical failed: '+host+target)
                if host.startswith('staging.') and not any('noindex' in v.lower() for v in headers.get('x-robots-tag',[])):raise RuntimeError('Staging noindex missing')
                print('PASS '+host+' '+pair['source']['slug']+' → 301 → 200',flush=True)
            for product in colours.values():
                path='/products/'+product['slug'];status,_,body=request(host,path,directory)
                parser=CanonicalParser();parser.feed(body)
                if status!=200 or parser.urls!=['https://'+host+path]:raise RuntimeError('Distinct colour offer failed: '+host+path)
            status,_,body=request(host,'/sitemap.xml',directory)
            if status!=200:raise RuntimeError('Sitemap unavailable: '+host)
            locs={node.text for node in ET.fromstring(body).iter() if node.tag.rsplit('}',1)[-1]=='loc'}
            for pair in aliases:
                if 'https://'+host+'/products/'+pair['source']['slug'] in locs or 'https://'+host+'/products/'+pair['target']['slug'] not in locs:raise RuntimeError('Sitemap aliases differ: '+host)
            if any('https://'+host+'/products/'+p['slug'] not in locs for p in colours.values()):raise RuntimeError('Colour offer missing from sitemap')
            print('COLOUR_OFFERS_AND_SITEMAP=PASS '+host,flush=True)
    print('DATABASE_WRITES=0\nORIGIN_HTTP_CHECK=PASS')

if __name__=='__main__':main()
