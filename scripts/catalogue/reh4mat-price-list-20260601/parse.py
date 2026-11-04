"""Convert the Reh4Mat price list PDF ("CENNIK 2026 01-06.pdf") into reference JSON.

Usage: python3 -I parse.py <cennik.pdf> <output.json>

Needs `pdftotext` (poppler). The PDF is read in -raw mode, where every price row
ends with "<amount> zł". Lines before it (back to the previous row, page footer
or table header) belong to the same row: symbol first, then the description.
"""

import hashlib
import json
import re
import subprocess
import sys

# Prices above 999 are written "1 200"; the NFZ limit right before the price is a separate number ("200 115 zł").
PRICE_RE = re.compile(r'^(?P<body>.*?)\s*(?<!\S)(?P<price>\d \d{3}|\d{1,4}) zł\.?\s*\*?\s*$')
# "H.01.01 200", "H.08.01.01 150", "L.04.01 K 800", "L.05.01. 35", "S.03.01.PJ 400"
NFZ_RE = re.compile(r'\s(?P<code>[A-Z]\.\d{2}\.\d{2}(?:\.\d{2})?\.?(?:\s?[A-Z]{1,2}\b)?)(?:\s+(?P<limit>\d+))?\s*$')
COLOUR_RE = re.compile(r'(?:\s+X)+\s*$')
PAGE_RE = re.compile(r'^Strona (\d+) z \d+$')
STATUS_RE = re.compile(r'\s*-\s*(NOWOŚĆ|WYPRZEDAŻ)\b', re.IGNORECASE)
NO_DISCOUNT = '*CENA NIE PODLEGA RABATOWANIU'
HEADER_LINES = {'SYMBOL CHARAKTERYSTYKA KOD NFZ', 'SYMBOL CHARAKTERYSTYKA', 'LIMIT', 'NFZ', 'CENA', 'NETTO', 'KOLOR WYROBU'}

# Words that extend a symbol into a different product (AS-KX-08 vs AS-KX-08 X-SIZE).
SYMBOL_WORDS = {'X-SIZE', 'DUAL', 'PRO', 'MAX', 'SHORT', '4ARMY', 'BIONIC', 'OA', 'CCA', 'ACL', 'HKAFO', 'PRIME', 'L', 'R', 'P'}
# Model names that are symbols on their own.
NAMED_SYMBOLS = ['OA PRIME', 'ATTACK PRO', 'ATTACK OA', 'ACL CCA', 'RAPTOR BIONIC 4ARMY', 'RAPTOR BIONIC', 'SPARTAN', 'MIDNIGHT', 'JAVELIN', 'KLUCZ DO SZYN']


def is_code_token(token: str) -> bool:
    return bool(re.search(r'\d', token)) or token.upper() in SYMBOL_WORDS


def split_symbol(lines: list[str]) -> tuple[str, str]:
    """Return (symbol, description) for the text lines of one row."""
    first = lines[0]
    rest = lines[1:]

    if rest:
        # A wrapped description can still start on the symbol line ("PCO-A-01 ORTEZA KOMPRESYJNA ...").
        # Symbol-only lines are short, even with a colour or status ("AM-PES-03 BEŻ, CZARNY - WYPRZEDAŻ").
        if len(first) > 45:
            symbol, description = split_symbol([first])
            if len(description.split()) >= 2:
                return symbol, ' '.join([description] + rest).strip()

        # Multi-line rows put the symbol on its own line(s); a status may wrap ("AM-ASS-OS PRO MAX -" / "NOWOŚĆ").
        symbol = first
        if symbol.rstrip().endswith('-') and rest and rest[0].strip().upper() in ('NOWOŚĆ', 'WYPRZEDAŻ'):
            symbol = symbol + ' ' + rest[0]
            rest = rest[1:]
        elif rest and re.fullmatch(r'4ARMY|X-SIZE|DUAL|SHORT', rest[0].strip(), re.IGNORECASE):
            symbol = symbol + ' ' + rest[0]
            rest = rest[1:]
        return symbol.strip(), ' '.join(rest).strip()

    for named in NAMED_SYMBOLS:
        if first.upper().startswith(named + ' ') or first.upper() == named:
            description = first[len(named):].strip()
            status = re.match(r'-\s*(NOWOŚĆ|WYPRZEDAŻ)\s*', description)
            if status:
                return named + ' - ' + status.group(1), description[status.end():]
            return named, description

    tokens = first.split(' ')
    symbol: list[str] = []
    i = 0
    while i < len(tokens):
        token = tokens[i]
        if not symbol:
            symbol.append(token)
        elif token == '-' and i + 1 < len(tokens) and tokens[i + 1].upper() in ('NOWOŚĆ', 'WYPRZEDAŻ', 'AKCESORIA'):
            symbol.extend([token, tokens[i + 1]])
            i += 1
        elif token.upper() in ('-WYPRZEDAŻ', '-NOWOŚĆ', '-AKCESORIA', 'AKCESORIA'):
            symbol.append(token)
        elif is_code_token(token):
            symbol.append(token)
        else:
            break
        i += 1

    return ' '.join(symbol), ' '.join(tokens[i:]).strip()


def clean_symbol(raw: str) -> tuple[str, str | None, bool]:
    """Strip the NOWOŚĆ/WYPRZEDAŻ marker; flag accessory rows."""
    status = None
    match = STATUS_RE.search(raw)
    if match:
        status = match.group(1).upper()
        raw = (raw[:match.start()] + raw[match.end():])
    accessory = bool(re.search(r'AKCESORIA', raw, re.IGNORECASE))
    raw = re.sub(r'\s+', ' ', raw).strip(' -,')
    return raw, status, accessory


def main() -> None:
    pdf_path, out_path = sys.argv[1], sys.argv[2]
    text = subprocess.run(['pdftotext', '-raw', '-enc', 'UTF-8', pdf_path, '-'], check=True, capture_output=True).stdout.decode('utf-8')
    with open(pdf_path, 'rb') as handle:
        sha256 = hashlib.sha256(handle.read()).hexdigest()

    rows = []
    buffer: list[str] = []

    # pdftotext ends every page with a form feed; rows never continue across pages.
    lines_with_page = [(page, line.strip()) for page, page_text in enumerate(text.split(''), start=1) for line in page_text.splitlines()]

    for page, line in lines_with_page:
        if PAGE_RE.match(line):
            buffer = []
            continue
        if not line or line in HEADER_LINES or line.startswith('SYMBOL CHARAKTERYSTYKA'):
            buffer = []
            continue

        match = PRICE_RE.match(line)
        if not match:
            buffer.append(line)
            continue

        body = match.group('body')
        no_discount = NO_DISCOUNT in body or NO_DISCOUNT in ' '.join(buffer)
        body = body.replace(NO_DISCOUNT, '').strip()
        nfz_code = nfz_limit = None
        nfz = NFZ_RE.search(' ' + body)
        if nfz:
            nfz_code, nfz_limit = nfz.group('code'), nfz.group('limit')
            body = (' ' + body)[:nfz.start()].strip()
        body = COLOUR_RE.sub('', ' ' + body).strip()
        if body == 'X':
            body = ''

        lines = [l.replace(NO_DISCOUNT, '').strip() for l in buffer] + ([body] if body else [])
        lines = [l for l in lines if l]
        buffer = []
        if not lines:
            continue

        raw_symbol, description = split_symbol(lines)
        symbol, status, accessory = clean_symbol(raw_symbol)
        rows.append({
            'page': page,
            'symbol': symbol,
            'raw_symbol': raw_symbol,
            'description': re.sub(r'\s+', ' ', description),
            'status': status,
            'accessory': accessory,
            'nfz_code': re.sub(r'\s+', ' ', nfz_code).rstrip('.') if nfz_code else None,
            'nfz_limit_pln': int(nfz_limit) if nfz_limit else None,
            'net_minor': int(match.group('price').replace(' ', '')) * 100,
            'price_not_discountable': no_discount,
        })

    with open(out_path, 'w', encoding='utf-8') as handle:
        json.dump({
            'source': 'reh4mat_price_list',
            'source_file': 'CENNIK 2026 01-06.pdf',
            'source_sha256': sha256,
            'effective_from': '2026-06-01',
            'price_basis': 'net',
            'currency': 'PLN',
            'row_count': len(rows),
            'rows': rows,
        }, handle, ensure_ascii=False, indent=2)
        handle.write('\n')


if __name__ == '__main__':
    main()
