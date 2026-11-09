"""Build the variant table for supplier products priced per version from Polish online shop prices.

Usage: python -I build_online_variants.py research-dir out.json

research-dir holds the variant research of 2026-11-09 (kept in storage/app/price-research/variants):
  resultA.json, resultB.json  per product: the versions the supplier sells and, per version, shop offers
                              whose gross price was read on the shop page (confirmed)

A product is included only when the research marked it variant_ready: every version the supplier sells has
a confirmed price and the versions differ along one choice. Each version's price is the median of its
confirmed offers. Shop prices are gross only, so rows carry no VAT rate: catalogue:apply-supplier-variants
gives them the product's current VAT and derives the net price.
"""

import json
import os
import statistics
import sys

# Marked variant_ready by the research but left out on review, by external_id.
EXCLUDE = {
    "1255": "CT3: Timago sells one 'system'; sensor and transmitter are separate retail items, not its versions",
    "282": "chusta trójkątna: the cotton price comes from a single shop selling 12-packs",
    "891": "elastoBAND: the 5 and 10 cm prices are unattributed 'od' prices, not shop offers",
}


def main(research_dir, out_path):
    products, skipped = [], []
    for name in ("resultA.json", "resultB.json"):
        for entry in json.load(open(os.path.join(research_dir, name), encoding="utf-8")):
            rows, reason = [], None
            if entry["external_id"] in EXCLUDE:
                reason = EXCLUDE[entry["external_id"]]
            elif not entry.get("variant_ready"):
                reason = entry.get("reason") or "not variant_ready"
            for version in entry.get("versions") or []:
                if reason:
                    break
                prices = [o["gross_pln"] for o in version.get("offers") or []
                          if o.get("confirmed") and isinstance(o.get("gross_pln"), (int, float))]
                if not prices:
                    reason = f"no confirmed price for {version['value']}"
                    break
                shops = sorted({o["shop"] for o in version["offers"] if o.get("confirmed")})
                rows.append({
                    "value": version["value"],
                    "list_row": f"online median of {len(prices)}: {', '.join(shops)}"[:180],
                    "list_net_minor": None,
                    "vat": None,
                    "net_minor": None,
                    "gross_minor": round(statistics.median(prices) * 100),
                    "offers": [o["url"] for o in version["offers"] if o.get("confirmed")],
                })
            if not reason and len(rows) < 2:
                reason = "fewer than two versions"
            if reason:
                skipped.append({"sku": entry["sku"], "name": entry["name"], "reason": reason})
                continue
            products.append({
                "external_id": entry["external_id"],
                "sku": entry["sku"],
                "name": entry["name"],
                "attribute": entry["attribute"],
                "variants": rows,
            })

    out = {
        "supplier": "timago",
        "external_source": "timago",
        "source_file": "Polish online shop prices, researched 2026-11-09",
        "effective_from": "2026-11-09",
        "currency": "PLN",
        "pricing": "median gross price of matching Polish shop offers per version; VAT stays as set on the product",
        "products": products,
        "unresolved": skipped,
    }
    with open(out_path, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(out, handle, ensure_ascii=False, indent=1)
        handle.write("\n")
    print(f"with_variants={len(products)} skipped={len(skipped)}")


if __name__ == "__main__":
    main(*sys.argv[1:3])
