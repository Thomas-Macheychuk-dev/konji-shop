"""Build the second-pass online price tables for supplier products still unpriced after the first pass.

Usage: python -I build_online_pass2.py pass2-dir variants-dir prices-out.json variants-out.json

pass2-dir holds the second research pass of 2026-11-09 (kept in storage/app/price-research/pass2):
resultA/B1/B2/C.json, one entry per product with the versions the supplier sells and, per version, shop
offers. variants-dir holds the first variant pass (resultA/B.json); a version without a confirmed offer in
the second pass falls back to that pass's confirmed offers for the same version label.

Rules agreed for this pass:
- Only confirmed, exact offers in PLN count (gross price read on the page). Foreign-currency-only products
  (Vermeiren Kuarz/Sync, Layertex washcloths, TH 427) stay unpriced.
- Prices from hospital-supply shops (emka-sklep.com.pl) count like any shop price.
- Products sold in several versions are offered in the versions that have a price; the others are left out
  until priced. A product with one version is priced directly; a product with one priced version out of
  several becomes a one-variant product so the version stays visible.
- Each price is the median of the confirmed offers; entries carry no VAT rate, so the commands keep the VAT
  the product already has.
"""

import json
import os
import statistics
import sys

# Left out on review, by external_id.
EXCLUDE = {
    "1255": "CT3: Timago sells one kit (applicator, sensor, transmitter, case); shops sell the parts separately",
    "233": "leg urine bag: the shop offer names a different manufacturer (Ningbo Caremet) than Timago's GreatCare code",
    "1306": "Maya: only search-snippet prices; the shop pages refuse to load",
}

# First-variant-pass prices rejected on review there; never used as a fallback.
NO_FALLBACK = {
    "282",  # chusta trójkątna: the cotton price came from a shop selling 12-packs
    "891",  # elastoBAND: the 5 and 10 cm prices were unattributed 'od' prices
}


def confirmed(offers):
    return [o for o in offers or []
            if o.get("confirmed") and o.get("exact") is not False
            and (o.get("currency") or "PLN") == "PLN"
            and isinstance(o.get("price", o.get("gross_pln")), (int, float))]


def price_of(offer):
    return offer.get("price", offer.get("gross_pln"))


def main(pass2_dir, variants_dir, prices_out, variants_out):
    fallback = {}
    for name in ("resultA.json", "resultB.json"):
        for entry in json.load(open(os.path.join(variants_dir, name), encoding="utf-8")):
            if entry["external_id"] in NO_FALLBACK:
                continue
            for version in entry.get("versions") or []:
                fallback[(entry["external_id"], version["value"])] = version

    tables = {"timago": ([], [], []), "vermeiren": ([], [], [])}
    for name in ("resultA.json", "resultB1.json", "resultB2.json", "resultC.json"):
        for entry in json.load(open(os.path.join(pass2_dir, name), encoding="utf-8")):
            supplier = "vermeiren" if len(entry["external_id"]) == 64 else "timago"
            singles, variant_products, unresolved = tables[supplier]
            if entry["external_id"] in EXCLUDE:
                unresolved.append({"sku": entry["sku"], "name": entry["name"], "reason": EXCLUDE[entry["external_id"]]})
                continue

            versions = entry.get("versions") or []
            rows = []
            for version in versions:
                source = version
                offers = confirmed(version.get("offers"))
                if not offers:
                    source = fallback.get((entry["external_id"], version["value"]), {})
                    offers = confirmed(source.get("offers"))
                if not offers:
                    continue
                # Offers can be in different units (a 10-pack next to a single piece); the research converted them
                # to the shop's unit in price_gross_pln, so that wins over a median of the raw offer prices.
                gross = source.get("price_gross_pln")
                if gross is None:
                    gross = statistics.median(price_of(o) for o in offers)
                shops = sorted({o["shop"] for o in offers})
                rows.append({
                    "value": version["value"],
                    "list_row": f"online median of {len(offers)}: {', '.join(shops)}"[:180],
                    "list_net_minor": None,
                    "vat": None,
                    "net_minor": None,
                    "gross_minor": round(gross * 100),
                    "offers": [o["url"] for o in offers],
                })

            if not rows:
                unresolved.append({"sku": entry["sku"], "name": entry["name"], "reason": f"no confirmed PLN offer ({entry.get('confidence')})"})
                continue

            if entry["kind"] == "single" or len(versions) == 1:
                row = rows[0]
                singles.append({
                    "external_id": entry["external_id"], "sku": entry["sku"], "name": entry["name"],
                    "list_row": row["list_row"], "list_code": None, "list_net_minor": None,
                    "vat": None, "net_minor": None, "gross_minor": row["gross_minor"],
                    "matched_by": "online", "offers": row["offers"],
                })
            else:
                variant_products.append({
                    "external_id": entry["external_id"], "sku": entry["sku"], "name": entry["name"],
                    "attribute": entry.get("attribute") or "Wariant", "variants": rows,
                    "unpriced_versions": [v["value"] for v in versions if v["value"] not in {r["value"] for r in rows}],
                })

    header = {
        "source_file": "Polish online shop prices, second pass 2026-11-09",
        "effective_from": "2026-11-09",
        "currency": "PLN",
        "pricing": "median gross price of matching Polish shop offers (incl. hospital-supply shops); VAT stays as set on the product",
    }
    for supplier, (singles, variant_products, unresolved) in tables.items():
        base = {"supplier": supplier, "external_source": supplier, **header}
        if singles:
            write(prices_out.replace("{supplier}", supplier), {**base, "entries": singles, "unresolved": unresolved})
        if variant_products:
            write(variants_out.replace("{supplier}", supplier), {**base, "products": variant_products})
        print(f"{supplier}: single={len(singles)} with_variants={len(variant_products)} unresolved={len(unresolved)}")


def write(path, data):
    with open(path, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=1)
        handle.write("\n")


if __name__ == "__main__":
    main(*sys.argv[1:5])
