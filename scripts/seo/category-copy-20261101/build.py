#!/usr/bin/env python3
"""Validate generated category copy and write it into resources/content/category-copy.pl.php after the reviewed entries."""
import json, re, sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).parent
REPO = ROOT.parent.parent.parent
COPY_FILE = REPO / 'resources/content/category-copy.pl.php'
ENTRIES = ROOT / 'entries.json'
SOURCES = ROOT / 'sources.json'
MARKER = '    // Generated from scripts/seo/category-copy-20261101/entries.json; grounded in each category\'s products.\n'

BANNED = re.compile(
    r'\b(lecz\w*|wylecz\w*|łagodz\w*|uśmierz\w*|zapobieg\w*|gwarant\w*|najlepsz\w*|najwyższ\w*|klinicz\w*|'
    r'polecan\w*|skuteczn\w*|cen[aęyą]\b|cenow\w*|promocj\w*|tani\w*|darmow\w*|dostaw\w*|wysyłk\w*|'
    r'w magazynie|od ręki|kup\b|kupuj\w*|zamów\b|refundac\w*|NFZ|Ortezka|'
    # Site-structure talk ("położona w ścieżce …", "najgłębszy poziom ścieżki") is not shopper-facing copy.
    r'ścieżk\w*|najgłębsz\w*|w strukturze)', re.I | re.U)


def problems(entries, sources, reviewed_slugs):
    found = []
    by_slug = {s['slug']: s for s in sources}
    if [e['slug'] for e in entries] != [s['slug'] for s in sources]:
        found.append('entries do not match sources one-to-one')
    for e in entries:
        source = by_slug.get(e['slug'])
        if source is None or e['name'] != source['name']:
            found.append(f'{e["slug"]}: name does not match source')
            continue
        if e['slug'] in reviewed_slugs:
            found.append(f'{e["slug"]}: already has reviewed copy')
        if not 120 <= len(e['meta']) <= 155:
            found.append(f'{e["slug"]}: meta length {len(e["meta"])}')
        d = e['description']
        if d.count('\n\n') != 1 or not 350 <= len(d) <= 750:
            found.append(f'{e["slug"]}: description shape/length {len(d)}')
        # Product names are facts too ("Mata zapobiegająca ześlizgiwaniu się z wózka").
        product_names = [p.split(' — ')[0] for p in source['sample_products']]
        terms = [source['name'], *source['path'].split(' > '), *source['children'], *product_names]
        for text in (e['meta'], d):
            if re.search(r'[<>*#_`]|https?:', text):
                found.append(f'{e["slug"]}: markup')
            scan = text
            # Category and subcategory names may legitimately contain a banned word ("Leczenie bezdechu sennego").
            for term in sorted(filter(None, terms), key=len, reverse=True):
                scan = re.sub(re.escape(term), ' ', scan, flags=re.I)
            scan = re.sub(r'\bna zamówienie\b', ' ', scan, flags=re.I)
            found += [f'{e["slug"]}: banned "{m.group(0)}"' for m in BANNED.finditer(scan)]
    for field in ('meta', 'description'):
        found += [f'duplicate {field}: {t[:60]}' for t, c in Counter(e[field] for e in entries).items() if c > 1]
    return found


def php_string(text):
    return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"


def render(reviewed_php, entries):
    head = reviewed_php.split(MARKER)[0].rstrip()
    # Before the first build there is no marker yet, so the reviewed part still ends with the closing "];".
    if MARKER not in reviewed_php and head.endswith('];'):
        head = head[:-2].rstrip()
    if not head.endswith('],') or not reviewed_php.rstrip().endswith('];'):
        raise ValueError('unexpected category-copy.pl.php shape')
    lines = [head, MARKER.rstrip('\n')]
    for e in entries:
        lines += [f"    {php_string(e['slug'])} => [",
                  f"        'name' => {php_string(e['name'])},",
                  f"        'meta' => {php_string(e['meta'])},",
                  f"        'description' => {php_string(e['description'])},",
                  '    ],']
    return '\n'.join(lines) + '\n];\n'


def reviewed_slugs(php):
    return set(re.findall(r"^    '([^']+)' => \[$", php.split(MARKER)[0], re.M))


def main():
    entries = json.loads(ENTRIES.read_text(encoding='utf-8'))
    sources = json.loads(SOURCES.read_text(encoding='utf-8'))
    php = COPY_FILE.read_text(encoding='utf-8')
    found = problems(entries, sources, reviewed_slugs(php))
    if found:
        print('\n'.join(found[:50]))
        sys.exit(f'CATEGORY_COPY=FAIL {len(found)} problems')
    if '--check' not in sys.argv:
        COPY_FILE.write_text(render(php, entries), encoding='utf-8')
    print(json.dumps({'reviewed': len(reviewed_slugs(php)), 'generated': len(entries)}))


if __name__ == '__main__':
    main()
