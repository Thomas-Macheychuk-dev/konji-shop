"""Build the Corradomed price list JSON from the supplier's PDF.

Usage:
    pdftotext -table -enc UTF-8 cennik_01.03.2026.pdf cennik.txt
    python -I build_price_list.py cennik.txt resources/import-data/corradomed/price-list-2026-03-01.json

The PDF is Corradomed's wholesale list ("Cennik hurtowy obowiązujący od 1 marca 2026 r."): net prices
per product code in eight finish columns, plus the VAT rate. `pdftotext -table` keeps each code on one
line with its prices; cells are assigned to a column by their position on the line, because rows leave
cells empty (e.g. UKP-7,5 has only the INOX ø 32 price).

The output keeps the list as printed (net, per column). The importer adds the shop markup and VAT.
ALIASES and PAGE_CODES map corradomed.pl table codes onto list codes where the two differ; each was
checked by hand against the product name on the page and in the list.
"""

import json
import re
import sys

COLUMNS = [
    "painted_25_white",
    "painted_25_black",
    "inox_25",
    "inox_32",
    "masked_32_white",
    "masked_32_black",
    "painted_32_white",
    "painted_32_black",
]

# Codes whose list row differs from the corradomed.pl table code (normalised form -> list code).
ALIASES = {
    # Bath seats: the list prices SWBO and SWZO once, the site lists widths. Same price for every width.
    "SWBO-14": "SWBO",
    "SWBO-16": "SWBO",
    "SWBO-18": "SWBO",
    "SWBO-20": "SWBO",
    "SWZO-20": "SWZO",
    "SWZO-22": "SWZO",
    # Treatment tables: SZ-1 has glass shelves, SZ-2 metal shelves (site page text and list names).
    "SZ-1": "SZ MISKA",
    "SZ-2": "SZ-2 MISKA",
    # The INOX ø 32 WC grab bar is 8/7,5 on the site; the list prices it as UWCL-8 / UWCP-8.
    "UWCL-8/7,5": "UWCL-8",
    "UWCP-8/7,5": "UWCP-8",
    # Doctor's stools: the site names them, the list codes them.
    "TABORETLEKARSKI": "TABORET",
    "TABORETLEKARSKIPODSTAWACHROM": "TABORET CHROM",
    "TABORETLEKARSKIZPODNOZKIEMPODSTAWACHROM": "TABORET PODNÓŻEK CHROM",
}

# Pages whose table does not list sellable codes: the product's variants are these list codes.
PAGE_CODES = {
    "medycz?do=paraw": [
        "PARAWAN SKŁADANY",
        "PARAWAN SKŁADANY DWUSKRZYDŁOWY",
        "PARAWAN ZMYWALNY",
        "PARAWAN ZMYWALNY DWUSKRZYDŁOWY",
        "PARAWAN SZTYWNY",
        "PARAWAN SZTYWNY ZMYWALNY",
    ],
    "medycz?do=stojak": ["2 ZAWIESZKI", "4 ZAWIESZKI", "2 ZAWIESZKI PP", "4 ZAWIESZKI PP"],
    "medycz?do=przewij": ["PRZEWIJAK", "PRZEWIJAK PÓŁKA", "PRZEWIJAK SZAFKA"],
    "medycz?do=aparatur": ["SAM"],
    # Page table says "KPU-O"; the page is the INOX chair with a PVC seat.
    "krzesla?do=uchyl10": ["KPU-O PVC"],
}

# Product names for pages whose heading is a category name ("Meble medyczne") or a plural.
PAGE_NAMES = {
    "medycz?do=kozet": "Kozetka lekarska",
    "medycz?do=stolik": "Stolik zabiegowy z miskami",
    "medycz?do=aparatur": "Stolik pod aparaturę medyczną",
    "medycz?do=paraw": "Parawan medyczny",
    "medycz?do=stojak": "Stojak na kroplówki",
    "medycz?do=krew": "Stanowisko do pobierania krwi",
    "medycz?do=krzeslo": "Krzesło lekarskie",
    "medycz?do=tabor": "Taboret lekarski",
    "medycz?do=przewij": "Przewijak - stół rehabilitacyjny dla niemowląt",
    "medycz?do=schod": "Schodek medyczny",
    "krzesla?do=tabor": "TP - Taboret prysznicowy",
    "uchwyty?do=umyw01": "UU - Uchwyty umywalkowe stałe",
    # The headings of these four drop the code that tells the two bath bar shapes apart.
    "uchwyty?do=wanna07": "UWPP - PiL ø 32 - Uchwyty wannowe poziomo-pionowe",
    "uchwyty?do=wanna08": "UWP-P - PiL ø 32 - Uchwyty wannowe poziomo-pionowe",
    "uchwyty?do=wanna17": "UWPP - PiL INOX ø 25 - Uchwyty wannowe poziomo-pionowe",
    "uchwyty?do=wanna18": "UWP-P - PiL INOX ø 25 - Uchwyty wannowe poziomo-pionowe",
}

# The list prints UWP-P - 12/7/7 among the UWP-P (horizontal-vertical) bars; the site and the old
# shop only sell UWP-P 12/7/9, and 12/7/7 belongs to the UWPP family. Same prices as UWP-P - 10/7/9.
CODE_FIXES = {"UWP-P - 12/7/7 *": "UWP-P - 12/7/9 *"}

PRICE = re.compile(r"(\d+)\s*zł|-{8}")
VAT = re.compile(r"\b(8|23)%")


def main(source: str, target: str) -> None:
    text = open(source, encoding="utf-8").read()
    items = []

    for page in text.split("\f"):
        items.extend(parse_page(page.splitlines()))

    seen = {}
    for item in items:
        if item["code"] in seen:
            raise SystemExit("Duplicate list code: " + item["code"])
        seen[item["code"]] = item

    json.dump(
        {
            "supplier": "Corradomed Sp. z o.o.",
            "source": "Cennik hurtowy obowiązujący od 1 marca 2026 r. (cennik_01.03.2026.pdf)",
            "valid_from": "2026-03-01",
            "currency": "PLN",
            "prices": "net per piece",
            "columns": COLUMNS,
            "notes": [
                "Products marked * are made to order only, about 20-25 working days.",
                "Matt black paint costs +20% (not sold).",
                "Shipping 30 zł net, free from 550 zł net except medical furniture (quoted individually).",
            ],
            "aliases": ALIASES,
            "page_codes": PAGE_CODES,
            "page_names": PAGE_NAMES,
            "items": items,
        },
        open(target, "w", encoding="utf-8"),
        ensure_ascii=False,
        indent=2,
    )
    print(f"{len(items)} list rows -> {target}")


def parse_page(lines: list[str]) -> list[dict]:
    header = next((line for line in lines if "Oznaczenie uchwytu" in line), None)
    if header is None:
        return []

    code_start = header.index("Oznaczenie uchwytu")
    rows = []
    pending_code = []
    pending_name = []

    # Rows start below the column header; everything above it is the letterhead.
    for line in lines[lines.index(header) + 1 :]:
        vat = VAT.search(line)
        # Notes to the right of the VAT column mention prices too ("Wysyłka zamówienia 30 zł netto").
        end = vat.start() if vat else len(line)
        cells = [m for m in PRICE.finditer(line, 0, end) if m.start() > code_start]
        name_part = line[:code_start].strip()
        code_part = line[code_start : cells[0].start() if cells else len(line)].strip()

        if not cells or vat is None:
            if line is header or "Pełna nazwa" in line or "RAL" in line:
                continue
            if code_part and len(code_part) < 25:
                pending_code.append(code_part)
            if name_part and name_part.isupper():
                pending_name.append(name_part)
            continue

        rows.append(
            {
                "name": " ".join(pending_name + ([name_part] if name_part else [])),
                "code": " ".join(pending_code + [code_part]),
                "cells": [(m.start() + m.end()) / 2 for m in cells],
                "values": [int(m.group(1)) if m.group(1) else None for m in cells],
                "vat": int(vat.group(1)),
            }
        )
        pending_code = []
        pending_name = []

    # Column centres from rows that have every cell.
    full = [row["cells"] for row in rows if len(row["cells"]) == len(COLUMNS)]
    if not full:
        raise SystemExit("No complete row on a page; cannot place columns.")
    centres = [sum(col) / len(full) for col in zip(*full)]

    items = []
    last_name = ""
    for row in rows:
        prices = {key: None for key in COLUMNS}
        for position, value in zip(row["cells"], row["values"]):
            column = min(range(len(centres)), key=lambda i: abs(centres[i] - position))
            if prices[COLUMNS[column]] is not None:
                raise SystemExit(f"Two cells in one column for {row['code']}")
            prices[COLUMNS[column]] = value

        code = CODE_FIXES.get(row["code"], row["code"])
        made_to_order = code.endswith("*")
        code = re.sub(r"\s*\*$", "", code).strip()
        last_name = row["name"] or last_name

        items.append(
            {
                "code": code,
                "name": last_name,
                "vat_rate": row["vat"],
                "made_to_order": made_to_order,
                "prices": prices,
            }
        )

    return items


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
