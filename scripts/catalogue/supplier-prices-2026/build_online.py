"""Build price tables from Polish online shop prices for supplier products missing from the price lists.

Usage: python -I build_online.py research-dir out-dir

research-dir holds the price research of 2026-11-09 (kept in storage/app/price-research):
  result1.json, result2.json, result3.json  one entry per unpriced product, with the shop offers found
  verified1.json                            result1's offers re-checked on the shop pages

Only products found with high or medium confidence are priced, and only those sold as one version: products
whose offers differ by size, pack or capacity (variant_prices with more than one row) need variants and are
left out. Result1 came from search summaries, so its prices count only once confirmed in verified1.

The price is the median of the matching offers' gross prices. Shops show gross prices only, so the entries
carry no VAT rate: catalogue:apply-supplier-prices keeps each variant's VAT and derives the net price.
"""

import json
import os
import statistics
import sys


def offers_and_median(entry, verified):
    if entry["_group"] == 1:
        check = verified.get(entry["external_id"])
        if not check or not check.get("confirmed_count") or check.get("confirmed_median_gross_pln") is None:
            return None, None, "not confirmed on the shop pages"
        offers = [o for o in check["offers"] if o.get("confirmed") and o.get("exact")]
        return offers, check["confirmed_median_gross_pln"], None

    offers = [o for o in entry["offers"] if o.get("exact") and (o.get("currency") or "PLN") == "PLN"
              and isinstance(o.get("gross_pln"), (int, float))]
    if not offers:
        return None, None, "no exact PLN offer"
    variants = entry.get("variant_prices") or []
    if len(variants) == 1 and variants[0].get("suggested_gross_pln") is not None:
        return offers, variants[0]["suggested_gross_pln"], None
    return offers, statistics.median(o["gross_pln"] for o in offers), None


def main(research_dir, out_dir):
    entries = []
    for group in (1, 2, 3):
        for entry in json.load(open(os.path.join(research_dir, f"result{group}.json"), encoding="utf-8")):
            entries.append({**entry, "_group": group})
    verified = {v["external_id"]: v for v in json.load(open(os.path.join(research_dir, "verified1.json"), encoding="utf-8"))}

    tables = {source: {"entries": [], "unresolved": []} for source in ("timago", "vermeiren")}
    for entry in entries:
        table = tables[entry["supplier"]]
        reason = None
        if entry["confidence"] not in ("high", "medium"):
            reason = f"confidence {entry['confidence']}"
        elif len(entry.get("variant_prices") or []) > 1:
            reason = "priced per size/pack/version; needs variants"
        else:
            offers, median, reason = offers_and_median(entry, verified)
        if reason:
            table["unresolved"].append({"sku": entry["sku"], "name": entry["name"], "reason": reason})
            continue

        shops = sorted({o["shop"] for o in offers})
        table["entries"].append({
            "external_id": entry["external_id"],
            "sku": entry["sku"],
            "name": entry["name"],
            "list_row": f"online median of {len(offers)}: {', '.join(shops)}"[:180],
            "list_code": None,
            "list_net_minor": None,
            "vat": None,
            "net_minor": None,
            "gross_minor": round(median * 100),
            "matched_by": "online",
            "offers": [o["url"] for o in offers],
        })

    for source, table in tables.items():
        out = {
            "supplier": source,
            "external_source": source,
            "source_file": "Polish online shop prices, researched 2026-11-09",
            "effective_from": "2026-11-09",
            "currency": "PLN",
            "pricing": "median gross price of matching Polish shop offers; VAT stays as set on the variant",
            **table,
        }
        with open(os.path.join(out_dir, f"{source}-online-2026.json"), "w", encoding="utf-8", newline="\n") as handle:
            json.dump(out, handle, ensure_ascii=False, indent=1)
            handle.write("\n")
        print(f"{source}: priced={len(table['entries'])} unresolved={len(table['unresolved'])}")


if __name__ == "__main__":
    main(*sys.argv[1:3])
