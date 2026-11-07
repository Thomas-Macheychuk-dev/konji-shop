#!/usr/bin/env bash
# Check what https://ortezka.pl serves: hosts, legacy redirects, indexing headers, robots.txt and the sitemap.
#
#   scripts/seo/cutover-preflight.sh 63.183.188.75   # before the DNS switch: send ortezka.pl requests to the new origin
#   scripts/seo/cutover-preflight.sh                 # after the DNS switch: go through public DNS and Cloudflare
#
# Read-only. Ends with CUTOVER_PREFLIGHT=PASS or CUTOVER_PREFLIGHT=FAIL (n problems).
set -u

IP="${1:-}"
R=()
if [ -n "$IP" ]; then
    for h in ortezka.pl www.ortezka.pl; do R+=(--resolve "$h:443:$IP" --resolve "$h:80:$IP"); done
fi
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
TMP="$(mktemp -d)"
trap 'rm -r "$TMP"' EXIT
FAILS=0

fail() { echo "FAIL: $1"; FAILS=$((FAILS + 1)); }

# url, expected first status, expected Location (empty when not a redirect)
check() {
    local url="$1" want_code="$2" want_loc="${3:-}" out code loc final
    out=$(curl -s -o /dev/null -m 30 -A "$UA" "${R[@]}" -w '%{http_code} %{redirect_url}' "$url")
    code=${out%% *}
    loc=${out#* }
    final=""
    [ -n "$loc" ] && final=$(curl -s -o /dev/null -m 30 -A "$UA" "${R[@]}" -w '%{http_code}' "$loc")
    printf '%-3s %-72s %s %s\n' "$code" "$url" "${loc:+-> $loc}" "${final:+($final)}"
    [ "$code" = "$want_code" ] || fail "$url returned $code, expected $want_code"
    [ "$loc" = "$want_loc" ] || fail "$url redirected to '${loc}', expected '${want_loc}'"
    [ -z "$loc" ] || [ "$final" = 200 ] || fail "$url target $loc returned $final"
}

echo "== Certificate served for ortezka.pl${IP:+ by $IP}"
if [ -n "$IP" ]; then
    echo | openssl s_client -connect "$IP:443" -servername ortezka.pl 2>/dev/null | openssl x509 -noout -subject -enddate -ext subjectAltName 2>/dev/null | tr -s ' '
else
    echo "(through Cloudflare: the edge certificate is Cloudflare's; check SSL mode Full (strict) in the dashboard)"
fi

echo; echo "== Hosts"
check http://ortezka.pl/ 301 https://ortezka.pl/
check http://www.ortezka.pl/ 301 https://ortezka.pl/
check https://www.ortezka.pl/ 301 https://ortezka.pl/
check https://ortezka.pl/ 200
check https://ortezka.pl/index.php 301 https://ortezka.pl/

echo; echo "== Legacy URLs with the most Search Console traffic (one hop each)"
check https://ortezka.pl/chusty-i-turbany-po-chemioterapii-cat-240 301 https://ortezka.pl/categories/turbany-i-chusty
check https://ortezka.pl/korektory-postawy-cat-237 301 https://ortezka.pl/categories/kregoslup-i-tulow
check https://www.ortezka.pl/korektory-postawy-cat-237 301 https://ortezka.pl/categories/kregoslup-i-tulow
check "https://ortezka.pl/korektory-postawy-cat-237?page=2" 301 https://ortezka.pl/categories/kregoslup-i-tulow
check https://ortezka.pl/bawelniany-rekaw-ochronny-pod-orteze-am-rb-id-13037 301 https://ortezka.pl/products/rekaw-ochronny-pod-orteze-am-rb
check https://ortezka.pl/wkladki-ortopedyczne-na-zapalenie-rozciegna-podeszwowego-vaya-plantaro-id-13163 301 https://ortezka.pl/products/wkladki-ortopedyczne-na-zapalenie-rozciegna-podeszwowego-vaya-plantaro-1-para
check https://ortezka.pl/walker-na-stope-po-czesciowej-amputacji-id-3549 301 https://ortezka.pl/products/walker-na-stope-po-czesciowej-amputacji-pro-term
check https://ortezka.pl/brand/179-clinic-dress 301 https://ortezka.pl/categories/odziez-medyczna
check https://ortezka.pl/content/15-kontakt 301 https://ortezka.pl/contact
check https://ortezka.pl/content/11-wypozyczalnia-sprzetu-medycznego 301 https://ortezka.pl/wypozyczalnia-sprzetu-medycznego
check https://ortezka.pl/wozki-inwalidzkie-cat-126 301 https://ortezka.pl/categories/wozki-inwalidzkie
check "https://ortezka.pl/index.php?id_product=13037&controller=product" 301 https://ortezka.pl/products/rekaw-ochronny-pod-orteze-am-rb
check https://ortezka.pl/categories/ortezy-kolana 301 https://ortezka.pl/categories/kolano

echo; echo "== Old URLs that should be gone"
check "https://ortezka.pl/szukaj?tag=CK825A" 404
check https://ortezka.pl/1_index_sitemap.xml 404
check https://ortezka.pl/nieistniejaca-strona-cutover-check 404

echo; echo "== Indexing signals on ortezka.pl"
curl -s -m 30 -A "$UA" "${R[@]}" -D "$TMP/h" -o "$TMP/home.html" https://ortezka.pl/
if grep -qi '^x-robots-tag:.*noindex' "$TMP/h"; then fail "homepage sends X-Robots-Tag noindex"; else echo "no noindex header"; fi
if grep -qiE '<meta name="robots"[^>]*noindex' "$TMP/home.html"; then fail "homepage has a noindex meta tag"; else echo "no noindex meta tag"; fi
grep -oE '<link rel="canonical"[^>]*>' "$TMP/home.html"
grep -q '<link rel="canonical" href="https://ortezka.pl"' "$TMP/home.html" || fail "homepage canonical is not https://ortezka.pl"
curl -s -m 30 -A "$UA" "${R[@]}" -o "$TMP/p.html" https://ortezka.pl/products/orteza-nogi-as-pu-02
grep -q '<link rel="canonical" href="https://ortezka.pl/products/orteza-nogi-as-pu-02"' "$TMP/p.html" || fail "product canonical is not on ortezka.pl"
grep -qE '"@type": ?"Product"' "$TMP/p.html" || fail "product page has no Product structured data"

echo; echo "== robots.txt"
curl -s -m 30 -A "$UA" "${R[@]}" https://ortezka.pl/robots.txt -o "$TMP/robots.txt"
grep -iE '^sitemap:' "$TMP/robots.txt"
grep -q '^Sitemap: https://ortezka.pl/sitemap.xml' "$TMP/robots.txt" || fail "robots.txt does not point to https://ortezka.pl/sitemap.xml"
awk 'tolower($0) ~ /^user-agent: (\*|googlebot)$/ {g=1; next} /^user-agent:/ {g=0} g && tolower($0) ~ /^disallow: \/$/ {bad=1} END {exit bad}' "$TMP/robots.txt" \
    || fail "robots.txt disallows / for Googlebot or *"

echo; echo "== Sitemap"
code=$(curl -s -m 60 -A "$UA" "${R[@]}" -o "$TMP/s.xml" -w '%{http_code}' https://ortezka.pl/sitemap.xml)
locs=$(grep -o '<loc>' "$TMP/s.xml" | wc -l)
echo "HTTP $code, $locs URLs"
[ "$code" = 200 ] && [ "$locs" -gt 1000 ] || fail "sitemap is missing or nearly empty"
grep -q 'staging\.ortezka\.pl' "$TMP/s.xml" && fail "sitemap lists staging.ortezka.pl URLs"

echo
if [ "$FAILS" -eq 0 ]; then echo "CUTOVER_PREFLIGHT=PASS"; else echo "CUTOVER_PREFLIGHT=FAIL ($FAILS problems)"; exit 1; fi
