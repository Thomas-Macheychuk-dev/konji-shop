"""Build the resolved Vermeiren 2026 price table.

Usage: python -I build_vermeiren.py product-data.json out.json variants-out.json

Prices are the retail ("cena detaliczna") net and gross columns of Vermeiren's
"CENNIK 2026" (valid from 01/01/2026, 47 pages). The PDF text layout is too irregular to parse
reliably, so PRICES was transcribed by hand and checked against the rendered pages. Keys are the
scraped Vermeiren SKUs (storage/app/scrapers/vermeiren/product-data.json); products sharing a SKU
share the price. Products priced per size or configuration (VARIANTS) go to a separate table that
catalogue:apply-supplier-variants turns into shop variants; NOT_PRICED lists what stays unpriced.
"""

import json
import sys

# scraped SKU -> (PDF model label, page, retail net PLN, retail gross PLN)
PRICES = {
    "Klick Power Standard Limited Edition": ("Klick Power Limited Edition Standard", 20, 17857, 19286),
    "Klick Power Tetra Limited Edition": ("Klick Power Limited Edition Tetra", 20, 19902, 21494),
    "Klick Race Standard": ("Klick Race Standard", 20, 18786, 20289),
    "Klick Race Tetra": ("Klick Race Tetra", 20, 20757, 22418),
    "Klick Monster Standard": ("Klick Monster Standard", 21, 19282, 20825),
    "Blumil GO": ("Blumil Go", 20, 8403, 9075),
    "Blumil Sport": ("Blumil Sport", 20, 12037, 13000),
    "BOBBY EVO": ("Bobby EVO", 3, 1446, 1562),
    "BOBBY": ("Bobby", 3, 1446, 1562),
    "101": ("101", 2, 602, 650),
    "JAZZ S50": ("JAZZ S50", 2, 787, 850),
    "JAZZS50 B69": ("JAZZ S50 B69", 2, 1203, 1299),
    "708D_v2013": ("708D", 2, 986, 1065),
    "V100": ("V100", 3, 1341, 1448),
    "V100 XL": ("V100XL 53/56 cm", 3, 1572, 1698),
    "D200": ("D200", 3, 1574, 1700),
    "D200-C": ("D200 C", 3, 1574, 1700),
    "D200 Split": ("D200 SPLIT", 4, 1620, 1750),
    "V300": ("V300", 4, 1799, 1943),
    "V300 XL": ("V300 (XL) WD", 4, 2843, 3070),
    "V500 Light": ("V500 Light", 4, 1852, 2000),
    "ECLIPS X4": ("ECLIPSX4", 4, 2017, 2178),
    "ECLIPS XXL": ("ECLIPSXXL", 4, 4493, 4852),
    "V500 Active": ("V500 ACTIVE", 5, 4166, 4499),
    "TRIGO S": ("Trigo S line", 5, 4515, 4876),
    "TRIGO T": ("Trigo T line", 5, 4620, 4990),
    "SAGITTA": ("sagitta", 5, 4167, 4500),
    "SAGITTA SI": ("sagitta Si", 5, 4552, 4916),
    "D200 30°": ("D200 30°", 7, 1873, 2023),
    "D200 30° Comfort": ("D200 30° WD VICAIR VECTOR O2 (komfort)", 7, 3704, 4000),
    "V300 30°": ("V300 30°", 7, 3200, 3456),
    "V300 30° Comfort": ("V300 30° WD (KOMFORT)", 7, 3704, 4000),
    "V500 30°": ("V500 30°", 7, 3200, 3456),
    "ECLIPS X4 90°": ("eclipsx4 90°", 8, 3570, 3856),
    "Inovys II Evo": ("inovys II Evo", 8, 3704, 4000),
    "Inovys II-E Evo": ("inovys II EVO WD-E", 8, 4938, 5333),
    "Inovys II Vicair": ("inovys II EVO (VICAIR) WD", 8, 7255, 7835),
    "Inovys II + L70": ("inovys 2 L70 WD", 8, 8250, 8910),
    "EZ1": ("Ez1", 8, 5490, 5929),
    "ALESIA": ("Alesia", 9, 4661, 5034),
    "9300": ("9300", 6, 3704, 4000),
    "CORAILLE": ("Coraille", 9, 3704, 4000),
    "ALTITUDE": ("Altitude", 8, 3704, 4000),
    "Normandie Plus": ("NORMANDIE PLUS", 9, 2917, 3150),
    "Normandie*": ("NORMANDIE", 37, 2004, 2164),
    "Bretagne": ("Bretagne (rama drewniana, bez kółek)", 37, 3707, 4004),
    "JAZZS50 HEM2": ("JAZZS50 Hem2", 6, 2778, 3000),
    "D200 HEM2": ("D200 Hem2", 6, 2778, 3000),
    "YPSI": ("YPSI", 10, 6143, 6634),
    "Jazz S50 Kids": ("JazzS50 KIDS", 2, 1071, 1157),
    "Eclips X2 Kids": ("eclipsx2 KIDS", 5, 1714, 1851),
    "ECLIPS X4 KIDS": ("eclipsx4 kids", 6, 2033, 2196),
    "SAGITTA KIDS": ("sagitta kids", 6, 4300, 4644),
    "ECLIPS X4 90° KIDS": ("eclipsx4 90° kids", 10, 2777, 2999),
    "Inovys Junior": ("inovys 2 JUNIOR", 10, 4147, 4479),
    "Cub": ("CUB", 10, 8257, 8918),
    "V-DRIVE": ("V-Drive Standard", 15, 4489, 4848),
    "Plego": ("PLEGO", 15, 10500, 11340),
    "Verso": ("VERSO", 15, 10500, 11340),
    "EXPRESS VB": ("EXPRESS", 15, 9990, 10789),
    "NAVIX RWD": ("NAVIX RWD", 15, 11429, 12343),
    "NAVIX FWD": ("NAVIX FWD", 15, 11429, 12343),
    "NAVIX SU": ("NAVIX SU", 15, 19787, 21370),
    "Turios": ("TURIOS", 16, 11250, 12150),
    "FOREST 3 Initial": ("FOREST3 INITIAL 6 km/h", 16, 11913, 12866),
    "FOREST 3": ("FOREST 3 standard", 16, 12761, 13782),
    "FOREST 3+": ("FOREST 3 + standard", 16, 26713, 28850),
    "FOREST 3 SU": ("FOREST 3 SU", 16, 25846, 27914),
    "TIMIX": ("TIMIX 6 km/h", 17, 18345, 19813),
    "TIMIX SU": ("TIMIX SU 6 km/h", 17, 26390, 28501),
    "SIGMA 230": ("SIGMA 230 6 km/h", 17, 14222, 15360),
    "FOREST KIDS": ("FOREST kids", 16, 13898, 15010),
    "SPRINGER": ("SPRINGER 6 km/h", 17, 16626, 17956),
    "One": ("ONE", 17, 6067, 6552),
    "One Air+": ("ONE air+", 17, 6400, 6912),
    "One Air+ LED": ("ONE air+ LED", 17, 6784, 7327),
    "One Air+ Spring": ("ONE air+ SPRING", 17, 6900, 7452),
    "ERIS": ("ERIS 38Ah", 18, 9600, 10368),
    "CERES SE": ("CERES SE 10 km/h 38Ah", 18, 10906, 11778),
    "CARPO 2 SE": ("CARPO 2 SE", 18, 13906, 15018),
    "CARPO 2 XD SE": ("CARPO 2 SE XD", 18, 16883, 18234),
    "Carpo 4D": ("CARPO 4D", 18, 16897, 18249),
    "Carpo 3D": ("CARPO 3D", 18, 16897, 18249),
    "Mercurius 4D": ("MERCURIUS 4D", 18, 15000, 16200),
    "Movo": ("MOVO", 19, 3000, 3240),
    "PEPI": ("PEPI", 14, 728, 786),
    "ALBATROS II": ("ALBATROS", 14, 7000, 7560),
    "Club": ("CLUB", 44, 3190, 3445),
    "Club D": ("CLUB D", 44, 3633, 3924),
    "Club Vario": ("CLUB VARIO", 44, 3551, 3835),
    "ILLICO": ("ILLICO", 45, 3741, 4040),
    "INTERVAL XXL": ("INTERVAL 3 XXL", 45, 6156, 6648),
    "ALOIS": ("ALOIS", 45, 4323, 4669),
    "376": ("376 stolik przyłóżkowy", 46, 428, 462),
    "MU713": ("MU 713", 31, 229, 248),
    "631": ("631", 38, 435, 470),
    "EASY-MAT": ("Easy Mat", 43, 302, 326),
    "BASIC MEDiCO": ("MEDICO BASIC", 43, 463, 500),
    "Goliat Artro": ("GOLIAT ARTRO", 11, 1146, 1238),
    "GOLIAT": ("GOLIAT", 11, 1044, 1128),
    "286i": ("286B", 11, 427, 461),
    "CADEO": ("CADEO", 11, 521, 563),
    "Eco-Light II": ("eco light", 11, 554, 598),
    "ECO-Plus": ("ECO-Plus", 11, 498, 538),
    "Quadri-Light": ("QUADRI Light", 11, 601, 649),
    "QUAVA": ("QUAVA", 11, 667, 720),
    "FOUR-Light": ("FOUR Light", 11, 667, 720),
    "276": ("276", 12, 969, 1047),
    "266": ("266", 12, 726, 784),
    "268": ("268", 12, 726, 784),
    "Lyna II": ("lyna II", 12, 224, 242),
    "Lindsey": ("lindsey", 12, 244, 264),
    "Twin-Light": ("Twin Light", 12, 319, 345),
    "STEPHANIE": ("Stephanie", 12, 91, 98),
    "ASHLEY": ("Ashley", 13, 116, 125),
    "ZOE": ("Zoe", 13, 96, 104),
    "BEATRIX": ("Beatrix", 13, 56, 60),
    "BETTY": ("Betty", 13, 56, 60),
    "BONNY": ("Bonny", 13, 56, 60),
    "BRITNEY": ("Britney", 13, 56, 60),
    "MONA": ("Mona", 13, 47, 51),
    "MARY": ("Mary", 13, 47, 51),
    "MAUD": ("Maud", 13, 47, 51),
    "MONICA": ("Monica", 13, 47, 51),
    "FLORA": ("Flora1", 13, 114, 123),
    "TRIP-TIP": ("Trip - Tip", 14, 69, 75),
    "ONTARIO 2": ("ONTARIO 2", 38, 2670, 3284),
    "139": ("139B", 37, 646, 698),
    "Kim": ("KIM", 35, 197, 213),
    "JILL": ("Jill", 35, 209, 226),
    "JINNY": ("Jinny", 35, 156, 168),
    "LILLY": ("Lilly", 35, 257, 278),
    "SIDNEY": ("Sidney", 35, 253, 273),
    "LALY": ("LALY", 35, 470, 508),
    "KATE": ("Kate", 35, 386, 417),
    "GRACE": ("Grace", 35, 300, 324),
    "VICKY": ("VICKY", 35, 223, 241),
    "MAGDA": ("MAGDA", 36, 651, 703),
    "LIDDY": ("Liddy", 36, 173, 187),
    "TINA": ("Tina", 36, 250, 270),
    "STACY": ("Stacy", 36, 429, 463),
    "IRIS": ("Iris", 36, 181, 195),
    "9062": ("9062", 37, 503, 543),
    "9062XXL": ("9062 XXL", 37, 543, 586),
    "9063": ("9063", 37, 590, 637),
    "Falcon": ("FALCON", 37, 4300, 4644),
    "EAGLE 620": ("620", 38, 4673, 5047),
    "EAGLE 625": ("625", 38, 5140, 5551),
    "692": ("692 szyny aluminiowe 0.9-1.8 m", 38, 1278, 1572),
    "VICAIR LIBERTY": ("VICAIR LIBERTY + pokrowiec", 31, 333, 360),
    "VICAIR LIBERTY PROFILE": ("VICAIR PROFILE + pokrowiec", 31, 590, 637),
    "VICAIR ACTIVE O2": ("VICAIR ACTIVE O2 + pokrowiec", 31, 1155, 1247),
    "VICAIR VECTOR O2": ("VICAIR VECTOR O2 + pokrowiec", 31, 1155, 1247),
    "VICAIR ADJUSTER O2": ("VICAIR Adjuster O2 + pokrowiec", 31, 1155, 1247),
    "Vicair Multifunctional O2": ("VICAIR MULTIFUNCTIONAL O2 + pokrowiec", 31, 1295, 1399),
    "VICAIR ALLROUNDER": ("AllRounder O2", 32, 1034, 1117),
    "VICAIR LIBERTY BACK": ("VICAIR LIBERTY BACK + pokrowiec", 32, 427, 461),
    "VICAIR ANATOMY BACK": ("VICAIR ANATOMIC BACK, rozmiary standardowe", 32, 1572, 1698),
    "VICAIR MULTIFUNCTIONAL BACK": ("VICAIR MULTIFUNCTIONAL BACK O2, rozmiary standardowe", 33, 1572, 1698),
    "STABILO Velcro Armrests": ("B66S", 33, 827, 893),
    "MULTISUPPORT": ("Multi Support", 42, 1000, 1080),
    "Clinisan": ("Clinisan", 42, 1250, 1350),
    "Vicair 415": ("415", 42, 7000, 7560),
    "MATTRESS MAT-X1": ("MAT-X1", 41, 370, 400),
    "MATTRESS MAT-X2": ("MAT-X2", 41, 370, 400),
    "MATTRESS MAT-X3": ("MAT-X3", 41, 764, 825),
    "Novacare Smartline Plus 5": ("SmartLine Plus 5", 41, 1800, 1944),
    "Novacare Smartline Plus 8": ("SmartLine Plus 8", 41, 3500, 3780),
    "MATTRESS ASX NOVACARE": ("ASX", 41, 3426, 3700),
    "MATTRESS ASX DIGITAL NOVACARE": ("ASX Digital", 41, 5841, 6308),
    "2202 (Aqua)": ("AQUA", 39, 2258, 2438),
    "2202 (Safari)": ("SAFARI", 39, 2258, 2438),
    "2215 (Happy)": ("HAPPY", 39, 2342, 2529),
    "2215 (Sporty)": ("SPORTY", 39, 2342, 2529),
    "2217 (Freedom)": ("FREEDOM", 39, 2660, 2872),
    "2217E": ("FREEDOM E", 39, 8978, 9696),
    "2219 (Lagoon)": ("LAGOON", 39, 3150, 3402),
    "2219E": ("LAGOON E", 39, 9464, 10221),
    "2219 Retro (Vintage)": ("VINTAGE", 39, 3469, 3747),
    "2219E (Vintage)": ("VINTAGE E", 39, 9950, 10746),
    "Physipro Valdis": ("VALDIS", 27, 1845, 1993),
    "Physipro Premium": ("PREMIUM", 27, 2560, 2765),
    "Physipro Contour Cinch": ("CONTOUR Cinch", 27, 2678, 2892),
    "Physipro HP2": ("HP2 Contoured/Profiled", 27, 3699, 3995),
    "Physipro HP2 Sport": ("HP2 Sport", 27, 3699, 3995),
    "Neo 20.01 Chest support - wide": ("Neo 20.01", 28, 231, 249),
    "Neo 20.02 Chest support with zipper - wide": ("Neo 20.02", 28, 312, 337),
    "Neo 20.03 Chest support - slim": ("Neo 20.03", 28, 231, 249),
    "Neo 20.04 Chest support with zipper - slim": ("Neo 20.04", 28, 312, 337),
    "Neo 20.05 Shoulder harness": ("Neo 20.05", 28, 179, 193),
    "Neo 20.06 Linked shoulder harness": ("Neo 20.06", 28, 205, 221),
    "Neo 20.07 Chest belt": ("Neo 20.07", 28, 193, 208),
    "Neo 30.01 Two-points hip belt": ("Neo 30.01", 28, 150, 162),
    "Neo 30.02 Four-points hip belt": ("Neo 30.02", 28, 164, 177),
    "Neo 30.03 Abduction control": ("Neo 30.03", 29, 300, 324),
    "Neo 40.01 Calf band": ("Neo 40.01", 29, 383, 414),
    "Neo 40.02 Ankle control": ("Neo 40.02", 29, 438, 473),
    "Neo 00.01 Multi-use band": ("Neo 00.01", 28, 193, 208),
    "Neo 00.02 Multi-use band extension": ("Neo 00.02", 28, 180, 194),
    # Base model plus the list's "dopłata do wersji XXL" (546 net / 590 gross; NORMANDIE 590 / 637).
    "CORAILLE XXL (from seat width 55)": ("Corille + dopłata XXL", 9, 3704 + 546, 4000 + 590),
    "ALTITUDE XXL (from seat width 55)": ("Altitude + dopłata XXL", 8, 3704 + 546, 4000 + 590),
    "NORMANDIE XXL": ("NORMANDIE + dopłata XXL", 37, 2004 + 590, 2164 + 637),
    # 708D plus the HEM2 "dopłata do wózka" (one-hand drive) from the options table.
    "708D HEM2": ("708D + dopłata HEM2", 24, 986 + 1433, 1065 + 1548),
    # The shop's CLUB UL is the bed the list sells as CLUB UL D.
    "Club UL": ("CLUB UL D", 45, 4100, 4428),
}

# Products whose list price depends on a size or configuration; each becomes a shop variant per row.
# scraped SKU -> (attribute name, page, [(value, PDF label, retail net, retail gross)])
SLING_ROWS = [("siatka", "z siatki", 441, 476), ("poliester", "z poliestru", 386, 417)]
VARIANTS = {
    "Klaxon Twist": ("Wersja", 21, [
        ("napęd tylny (RDE)", "TWIST RDE", 20491, 22130),
        ("napęd przedni (FDE)", "TWIST FDE", 22935, 24770),
        ("napęd przedni z kierownicą (FDE+SB)", "TWIST FDE+SB", 24444, 26400),
        ("wersja full (FDE+RDE+SB)", "TWIST FDE+RDE+SB", 26714, 28851),
    ]),
    "Gemini 2*": ("Rozmiar", 10, [("32 cm", "gemini² /32", 2887, 3118), ("40 cm", "gemini² /40", 3145, 3397)]),
    "BAYO": ("Rozmiar", 14, [("32 cm", "BAYO /32 cm", 2439, 2634), ("40 cm", "BAYO /40 cm", 2530, 2732)]),
    "SIGMA": ("Prędkość", 17, [("6 km/h", "SIGMA 230 6 km/h", 14222, 15360), ("10 km/h", "SIGMA 230 10 km/h", 15342, 16569)]),
    "STABILO BASE": ("Rozmiar", 33, [
        ("S", "STABILO BASE S", 998, 1078), ("S+", "STABILO BASE S+", 1168, 1261), ("M", "STABILO BASE M", 1271, 1373),
        ("M+", "STABILO BASE M+", 1391, 1502), ("L", "STABILO BASE L", 1462, 1579),
    ]),
    "STABILO Comfortable": ("Rozmiar", 33, [
        ("S", "STABILO CONFORTABLE S", 1167, 1260), ("M", "STABILO CONFORTABLE M", 1573, 1699),
        ("L", "STABILO CONFORTABLE L", 1664, 1797),
    ]),
    "STABILO Comfortable Plus": ("Rozmiar", 33, [
        ("S", "CONFORTABLE PLUS S", 1380, 1490), ("M", "CONFORTABLE PLUS M", 1573, 1699),
        ("L", "CONFORTABLE PLUS L", 1762, 1903), ("XL", "CONFORTABLE PLUS XL", 1892, 2043),
    ]),
    "STABILO Comfortable Plus Duo": ("Rozmiar", 33, [
        ("S", "COMFORTABLE PLUS DUO S", 2682, 2897), ("M", "COMFORTABLE PLUS DUO M", 2990, 3229),
        ("L", "COMFORTABLE PLUS DUO L", 3343, 3610), ("XL", "COMFORTABLE PLUS DUO XL", 3611, 3900),
        ("XXL", "COMFORTABLE PLUS DUO XXL", 4294, 4638),
    ]),
    "Mia": ("Wysokość", 36, [("10 cm", "Mia 10 cm", 119, 129), ("15 cm", "Mia 15 cm", 139, 150)]),
    "IZZIE": ("Wariant", 36, [
        ("30 cm", "Izzie 30", 43, 46), ("40 cm", "Izzie 40", 49, 53), ("45 cm", "Izzie 45", 53, 57),
        ("60 cm", "Izzie 60", 59, 64), ("80 cm", "Izzie 80", 67, 72), ("kątowa (R)", "Izzie R", 114, 123),
    ]),
    "PLUO": ("Koła", 37, [("5 cali", "PLUO 5'", 1836, 1983), ("24 cale", "PLUO 24'", 3352, 3620)]),
    "REGULAR (Eagle Sling)": ("Materiał", 38, [(v, "REGULAR " + l, n, g) for v, l, n, g in SLING_ROWS]),
    "COMFORT (Eagle sling)": ("Materiał", 38, [(v, "COMFORT " + l, n, g) for v, l, n, g in SLING_ROWS]),
    "HYGIENIC (Eagle sling)": ("Materiał", 38, [(v, "HYGIENIC " + l, n, g) for v, l, n, g in SLING_ROWS]),
    "690": ("Zestaw", 38, [
        ("kit 1 (do 4 cm)", "rampy 690 kit1", 308, 379), ("kit 2 (do 7,5 cm)", "rampy 690 kit2", 596, 733),
        ("kit 3 (do 11,5 cm)", "rampy 690 kit3", 970, 1193), ("kit 4 (do 15 cm)", "rampy 690 kit4", 1174, 1444),
        ("kit 5 (do 20 cm)", "rampy 690 kit5", 2425, 2983),
    ]),
    "385": ("Blat", 46, [("wyciągany", "385 z wyciąganym blatem", 1137, 1228), ("odchylany", "385 z odchylanym blatem", 900, 972)]),
}

# Scraped SKUs deliberately left unpriced, with the reason.
NOT_PRICED = {
    "Bobby Evo B03": "B03 armrests are only listed as a separate purchase, no model price",
    "ECLIPS X4 30°": "not in the 2026 list",
    "ECLIPS X4 90° COMFORT": "not in the 2026 list",
    "SAGITTA KIDS SI": "not in the 2026 list",
    "Plego Carbon": "not in the 2026 list",
    "Kuarz": "not in the 2026 list",
    "Sync": "not in the 2026 list",
    "Eon": "not in the 2026 list",
    "275": "not in the 2026 list",
    "Mattress BaWü": "not in the 2026 list",
}


def list_price(sku, net, gross):
    """Return (vat, net_minor, gross_minor) from the list's retail net and gross columns."""
    # The list rounds both columns to whole złoty, so small prices only approximate the rate.
    vat = min((8, 23), key=lambda rate: abs(net * (100 + rate) / 100 - gross))
    if abs(net * (100 + vat) / 100 - gross) > 1:
        raise SystemExit(f"Net {net} and gross {gross} for {sku} fit neither 8% nor 23% VAT")
    gross_minor = gross * 100
    return vat, round(gross_minor * 100 / (100 + vat)), gross_minor


def main(scraped_path, out_path, variants_out_path):
    data = json.load(open(scraped_path, encoding="utf-8"))
    products = data["products"] if isinstance(data, dict) else data
    entries, variant_products, unresolved = [], [], []

    for product in products:
        sku = product.get("sku")
        if sku in VARIANTS:
            attribute, page, rows = VARIANTS[sku]
            variants = []
            for value, label, net, gross in rows:
                vat, net_minor, gross_minor = list_price(sku, net, gross)
                variants.append({
                    "value": value,
                    "list_row": f"page {page}: {label}",
                    "list_net_minor": net * 100,
                    "vat": vat,
                    "net_minor": net_minor,
                    "gross_minor": gross_minor,
                })
            variant_products.append({
                "external_id": str(product["external_product_id"]),
                "sku": sku,
                "name": product["name"],
                "attribute": attribute,
                "variants": variants,
            })
            continue
        if sku not in PRICES:
            unresolved.append({"sku": sku, "name": product["name"], "reason": NOT_PRICED.get(sku, "not_in_list")})
            continue
        label, page, net, gross = PRICES[sku]
        vat, net_minor, gross_minor = list_price(sku, net, gross)
        entries.append({
            "external_id": str(product["external_product_id"]),
            "sku": sku,
            "name": product["name"],
            "list_row": f"page {page}: {label}",
            "list_code": label,
            "list_net_minor": net * 100,
            "vat": vat,
            "net_minor": net_minor,
            "gross_minor": gross_minor,
            "matched_by": "manual",
        })

    scraped_skus = {p.get("sku") for p in products}
    unused = sorted((set(PRICES) | set(VARIANTS)) - scraped_skus)
    if unused:
        raise SystemExit(f"PRICES/VARIANTS keys not in the scraped data: {unused}")

    header = {
        "supplier": "vermeiren",
        "external_source": "vermeiren",
        "source_file": "cennik 2026 ważny od 01,01,2026-1.pdf",
        "effective_from": "2026-01-01",
        "currency": "PLN",
        "pricing": "retail (cena detaliczna) gross from the list; net follows from the list VAT rate",
    }
    write_json(out_path, {**header, "entries": entries, "unresolved": unresolved})
    write_json(variants_out_path, {**header, "products": variant_products})
    print(f"products={len(products)} priced={len(entries)} with_variants={len(variant_products)} unresolved={len(unresolved)}")


def write_json(path, data):
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=1)
        handle.write("\n")


if __name__ == "__main__":
    main(*sys.argv[1:4])
