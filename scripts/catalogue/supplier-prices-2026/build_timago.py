"""Build the resolved Timago 2026 price table.

Usage: python -I build_timago.py "CENNIK dla dystrybutorów- 2026.xlsx" full-product-data.json out.json variants-out.json

The spreadsheet is Timago's distributor list (net, VAT, gross). The shop sells at the distributor
net price plus a 10% markup; gross follows from the list VAT rate. Each scraped Timago product
(storage/app/scrapers/timago/full-product-data.json) is matched by its SKU against the list
"Kod produktu"; a code with several different prices is only used when OVERRIDES picks the row.
Promotional bundle rows ("PAKIET 5 szt.") are never used.
"""

import json
import re
import sys
from collections import defaultdict
from decimal import ROUND_HALF_UP, Decimal

import openpyxl

MARKUP = Decimal("1.10")

# Scraped SKU (or product name when the SKU is missing or shared) -> list row "<sheet>:<row>".
# Each was checked by hand against the product name.
OVERRIDES = {
    "K7": "Rehabilitacja:30",  # BIG-TIM, 56 and 61 cm cost the same
    "TOPPER": "Rehabilitacja:233",
    "MOON-TIM GOFR": "Rehabilitacja:228",
    "KING-10-20": "Rehabilitacja:122",
    "KING-10L-20": "Rehabilitacja:123",
    "KING-15L-20": "Rehabilitacja:124",
    "KING-12A-20": "Rehabilitacja:125",
    "KING-CRTS4-20": "Rehabilitacja:126",  # nakładka wymiękczająca, listed as KING-CCMD4-20
    "AQD-C004-RE": "Rehabilitacja:163",
    "AQD-C004-BL": "Rehabilitacja:163",
    "AQD-C004-BK": "Rehabilitacja:163",
    "YGB-BL": "Rehabilitacja:192",  # 45 cm niebieska
    "YGB-RE": "Rehabilitacja:193",  # 55 cm czerwona
    "YGB-X": "Rehabilitacja:194",  # 65 cm czarna
    "YGB-Y": "Rehabilitacja:191",  # 75 cm żółta
    "yelloROLLER": "Rehabilitacja:195",
    "YM-X": "Rehabilitacja:196",
    "YFB-X": "Rehabilitacja:182",  # czarna 12-15 kg
    "Taśma do ćwiczeń yellowFLAT band żółta (opór 1-2 kg)": "Rehabilitacja:178",
    "Taśma do ćwiczeń yellowFLAT band czerwona (opór 2-4 kg)": "Rehabilitacja:179",
    "Taśma do ćwiczeń zielona yellowFLAT band zielona (opór 5-8kg)": "Rehabilitacja:180",
    "Taśma do ćwiczeń yellowFLAT band niebieska (opór 9-11kg)": "Rehabilitacja:181",
    "Taśma do ćwiczeń yellowLOOP band niebieski (1-5kg)": "Rehabilitacja:183",
    "Taśma do ćwiczeń yellowLOOP band żółty (5-10kg)": "Rehabilitacja:184",
    "Taśma do ćwiczeń yellowLOOP band zielony (10-15kg)": "Rehabilitacja:185",
    "Taśma do ćwiczeń yellowLOOP band czarny (15-20kg)": "Rehabilitacja:186",
    "YMB-8-EPP-XY": "Rehabilitacja:197",
    "YMB-65-S-X": "Rehabilitacja:198",
    "JMC-C 2047-1:3": "Rehabilitacja:97",  # all three colours cost the same
    "Torba do wózka inwalidzkiego": "Rehabilitacja:50",
    "Torba na podłokietnik do wózka inwalidzkiego": "Rehabilitacja:49",
    "TABLE": "Rehabilitacja:51",
    "HexTip": "Rehabilitacja:302",
    "JMC 2731": "Rehabilitacja:301",  # nasadki do balkoników 2 szt.
    "JMC 2906": "Rehabilitacja:300",  # nasadki do trójnogów/czwórnogów na wysokich nogach 4 szt.
    "Nasadki gumowe do lasek inwalidzkich - 2 sztuki": "Rehabilitacja:299",
    "Podkładka podwyższająca pod meble": "Rehabilitacja:296",
    "DREAM-TIM / DREAM-TIM PLUS": "Łóżka rehabilitacyjne:6",
    "Pro-Walker Low": "Ortotyka:57",
    "H011": "Rehabilitacja:21",  # CLASSIC-TIM base price; options cost more
    "Prysznicowo-toaletowy wózek transportowy 3w1 na małych kołach - SANI-TIM": "Rehabilitacja:44",  # 5'' wheels
    "Prysznicowo-toaletowy wózek transportowy 3w1 - SANI-TIM": "Rehabilitacja:43",  # 24'' wheels
    "Aparat szynowo - opaskowy na goleń i udo, niebieski": "Ortotyka:41",
    "TH 557/1": "Rehabilitacja dziecięca:177",  # Recaro Axion 1 Reha
    "MAJOR": "Rehabilitacja dziecięca:228",
    "SWIFTY": "Rehabilitacja dziecięca:98",  # standard version
    "TH 6637/61": "Rehabilitacja dziecięca:42",
    "TH 6637/6": "Rehabilitacja dziecięca:41",
    "TH 6341/1": "Rehabilitacja dziecięca:18",
    "TH 6833/1": "Rehabilitacja dziecięca:108",
    "TH 6621/41": "Rehabilitacja dziecięca:27",  # pałąk mocno zaokrąglony, listed as TH 6621/51
    # TH 421 and TH 675 appear under several car seats; the row is the one in that seat's accessories.
    "Rurka stabilizująca do fotelika Hercules Prime R129": "Rehabilitacja dziecięca:264",
    "Rurka stabilizująca do fotelika Hercules Small": "Rehabilitacja dziecięca:292",
    "TH 675": "Rehabilitacja dziecięca:243",  # Major, peloty boczne tułowia
}

# Products whose list price depends on a size or configuration; each becomes a shop variant per row.
# scraped SKU -> (attribute name, [(value, list row)])
VARIANTS = {
    "SPIKY": ("Rozmiar", [
        ("5 cm (czerwona)", "Rehabilitacja:168"), ("6 cm (niebieska)", "Rehabilitacja:169"),
        ("7 cm (żółta)", "Rehabilitacja:170"), ("8 cm (zielona)", "Rehabilitacja:171"),
    ]),
    "yellowBAND": ("Szerokość", [
        ("5 cm", "Rehabilitacja:175"), ("7,5 cm", "Rehabilitacja:176"), ("10 cm", "Rehabilitacja:177"),
    ]),
    "yellowTAPE": ("Wariant", [
        ("jednokolorowa", "Rehabilitacja:174"), ("mix 4 kolorów", "Rehabilitacja:173"),
    ]),
    "SLIDE-TIM": ("Rozmiar", [("72 x 71 cm", "Rehabilitacja:274"), ("122 x 71 cm", "Rehabilitacja:273")]),
}


def norm_text(value):
    return " ".join(str(value).split()) if value is not None else ""


def code_key(code):
    return re.sub(r"[^A-Z0-9]", "", str(code or "").upper())


def read_rows(path):
    workbook = openpyxl.load_workbook(path, data_only=True)
    rows = {}
    for sheet in workbook.worksheets:
        header = None
        section = ""
        for number, raw in enumerate(sheet.iter_rows(max_col=16, values_only=True), 1):
            cells = [norm_text(c) if isinstance(c, str) else c for c in raw]
            if header is None:
                if "Cena netto" in cells:
                    header = {name: cells.index(name) for name in ("Nazwa handlowa asortymentu", "Kod produktu", "Cena netto", "VAT") if name in cells}
                continue
            net = cells[header["Cena netto"]]
            if not isinstance(net, (int, float)):
                texts = [c for c in cells if isinstance(c, str) and c]
                section = texts[0] if texts else section
                continue
            name = cells[header["Nazwa handlowa asortymentu"]]
            code = norm_text(cells[header["Kod produktu"]]) if "Kod produktu" in header else ""
            rows[f"{sheet.title}:{number}"] = {
                "id": f"{sheet.title}:{number}",
                "code": code.rstrip("*").strip() or None,
                "name": name,
                "net": Decimal(str(net)).quantize(Decimal("0.01"), ROUND_HALF_UP),
                "vat": int(round(float(cells[header["VAT"]]) * 100)),
                "bundle": bool(re.search(r"promocyjn|PAKIET", section + " " + name, re.I)),
            }
    return rows


def price(row):
    """Return (net_minor, gross_minor) for a list row: distributor net + markup, gross from the list VAT."""
    net = (row["net"] * MARKUP).quantize(Decimal("0.01"), ROUND_HALF_UP)
    net_minor = int(net * 100)
    return net_minor, int((Decimal(net_minor) * (100 + row["vat"]) / 100).quantize(Decimal("1"), ROUND_HALF_UP))


def main(xlsx, scraped_path, out_path, variants_out_path):
    rows = read_rows(xlsx)
    by_code = defaultdict(list)
    for row in rows.values():
        if row["code"] and not row["bundle"]:
            for part in row["code"].split("/"):
                by_code[code_key(part)].append(row)

    data = json.load(open(scraped_path, encoding="utf-8"))
    products = data["products"] if isinstance(data, dict) else data
    entries, variant_products, unresolved = [], [], []

    for product in products:
        sku, name = product.get("sku"), product["name"]
        if sku in VARIANTS:
            attribute, picks = VARIANTS[sku]
            variants = []
            for value, row_id in picks:
                row = rows[row_id]
                net_minor, gross_minor = price(row)
                variants.append({
                    "value": value,
                    "list_row": row["id"],
                    "list_net_minor": int(row["net"] * 100),
                    "vat": row["vat"],
                    "net_minor": net_minor,
                    "gross_minor": gross_minor,
                })
            variant_products.append({
                "external_id": str(product["external_product_id"]),
                "sku": sku,
                "name": name,
                "attribute": attribute,
                "variants": variants,
            })
            continue
        override = OVERRIDES.get(sku) or OVERRIDES.get(name)
        if override:
            row, via = rows[override], "override"
        else:
            candidates = by_code.get(code_key(sku), [])
            prices = {(c["net"], c["vat"]) for c in candidates}
            if len(prices) != 1:
                unresolved.append({"sku": sku, "name": name, "reason": "ambiguous" if prices else "not_in_list"})
                continue
            row, via = candidates[0], "code"

        net_minor, gross_minor = price(row)
        entries.append({
            "external_id": str(product["external_product_id"]),
            "sku": sku,
            "name": name,
            "list_row": row["id"],
            "list_code": row["code"],
            "list_net_minor": int(row["net"] * 100),
            "vat": row["vat"],
            "net_minor": net_minor,
            "gross_minor": gross_minor,
            "matched_by": via,
        })

    header = {
        "supplier": "timago",
        "external_source": "timago",
        "source_file": "CENNIK dla dystrybutorów- 2026.xlsx",
        "effective_from": "2026-01-01",
        "currency": "PLN",
        "pricing": "distributor net price + 10% markup; gross from the list VAT rate",
    }
    write_json(out_path, {**header, "entries": entries, "unresolved": unresolved})
    write_json(variants_out_path, {**header, "products": variant_products})
    print(f"products={len(products)} priced={len(entries)} with_variants={len(variant_products)} unresolved={len(unresolved)}")


def write_json(path, data):
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=1)
        handle.write("\n")


if __name__ == "__main__":
    main(*sys.argv[1:5])
