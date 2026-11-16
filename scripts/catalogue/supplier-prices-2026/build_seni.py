"""Build the frozen Seni base-price table for `php artisan seni:apply-price-markup`.

Usage: python3 -I build_seni.py seni24-crawl.json matopat24-listing.json out.json

seni24-crawl.json is a `seni24:crawl-product-data --json` run over every Seni product matopat24.pl lists
(15 November 2026). seni24.pl and matopat24.pl share product and combination ids, so each seni24 variant's
base price is matopat24's price for the same combination, read from matopat24's product refresh endpoint;
products without options take matopat24's listing price. The owner asked for matopat24 prices + 20%; most
seni24 prices are the same, but Seni San is a few percent cheaper on seni24.
"""

import json
import re
import sys
import time
import urllib.parse
import urllib.request
from decimal import ROUND_HALF_UP, Decimal

HEADERS = {"User-Agent": "Mozilla/5.0", "Accept": "application/json"}


def minor(value):
    return int((Decimal(str(value)) * 100).quantize(Decimal("1"), ROUND_HALF_UP))


def matopat_combination_price(product_id, combination_id):
    query = urllib.parse.urlencode({
        "controller": "product", "id_product": product_id, "id_product_attribute": combination_id,
        "ajax": 1, "action": "refresh", "quantity_wanted": 1,
    })
    request = urllib.request.Request("https://www.matopat24.pl/index.php?" + query, data=b"", headers=HEADERS)
    data = json.load(urllib.request.urlopen(request, timeout=30))
    if str(data.get("id_product_attribute")) != str(combination_id):
        return None
    prices = re.findall(r'content="([0-9.]+)"', data.get("product_prices", ""))
    return Decimal(prices[0]) if prices else None


def main(crawl_path, listing_path, out):
    listing = {str(p["id"]): p for p in json.load(open(listing_path, encoding="utf-8"))}
    crawl = json.load(open(crawl_path, encoding="utf-8"))
    variants, missing = [], []
    for product in crawl["products"]:
        product_id = str(product["external_product_id"])
        listed = listing.get(product_id)
        for candidate in product.get("variant_candidates") or []:
            combination_id = str(candidate["external_variant_id"])
            if combination_id == "0":
                price = Decimal(re.sub(r"[^0-9,]", "", listed["price"]).replace(",", ".")) if listed else None
            else:
                price = matopat_combination_price(product_id, combination_id)
                time.sleep(0.25)
            if price is None:
                missing.append(f"{product_id}-{combination_id} {product['name']}")
                continue
            seni24 = candidate.get("price_gross_amount", product.get("price_gross_amount"))
            variants.append({
                "external_variant_id": f"seni24-{product_id}-{combination_id}",
                "product": product["name"],
                "label": candidate.get("label") or "-",
                "base_gross_minor": minor(price),
                "seni24_gross_minor": None if seni24 is None else minor(seni24),
            })
    table = {
        "source": "matopat24.pl combination prices, matched to seni24.pl variants by shared ids",
        "scraped_on": "2026-11-15",
        "pricing": "shop price = base gross + 20%",
        "variants": sorted(variants, key=lambda v: v["external_variant_id"]),
    }
    with open(out, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(table, handle, ensure_ascii=False, indent=1)
        handle.write("\n")
    print(f"products={len(crawl['products'])} variants={len(variants)} without_matopat_price={len(missing)}")
    for line in missing:
        print("NO_MATOPAT_PRICE", line)


if __name__ == "__main__":
    main(*sys.argv[1:4])
