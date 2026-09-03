#!/bin/bash
# Storefront + Store API checkout battery against dev-next with the footprint probe armed.
# Usage: footprint-battery.sh <outdir>   (set EXTRA_HDR="X-WCPOS-Lane-Variant: <token>" to run the FREE lane)
set -u
OUT=${1:?usage: footprint-battery.sh <outdir>}
mkdir -p "$OUT"
BASE=https://dev-next.wcpos.com
TOK="X-WCPOS-Footprint: REPLACE-WITH-A-FRESH-TOKEN"
SSH="ssh -o ConnectTimeout=15 root@213.239.218.130"
EXTRA=()
[ -n "${EXTRA_HDR:-}" ] && EXTRA=(-H "$EXTRA_HDR")

# Resolve the dev-next PHP container by its mount, not by the Coolify-generated name (which changes on redeploy).
PHP=$($SSH 'for N in $(docker ps --format "{{.Names}}" | grep "^php-"); do docker inspect "$N" --format "{{range .Mounts}}{{.Source}}{{\"\n\"}}{{end}}" 2>/dev/null | grep -q "/data/wordpress/dev-next/" && echo "$N" && break; done')
[ -z "$PHP" ] && { echo "dev-next PHP container not found"; exit 1; }
echo "php container=$PHP"

# Remember the COD gateway's original state and restore it on every exit path.
COD_WAS=$($SSH "docker exec $PHP wp wc payment_gateway get cod --user=1 --field=enabled --allow-root" | tr -d '[:space:]')
restore_cod() { $SSH "docker exec $PHP wp wc payment_gateway update cod --enabled=${COD_WAS:-false} --user=1 --allow-root >/dev/null" && echo "COD restored to ${COD_WAS:-false}"; }
trap restore_cod EXIT

# Remove (not truncate) the log so php-fpm can recreate it as its own user, enable COD, read the store country.
$SSH "docker exec $PHP sh -c 'rm -f /tmp/wcpos-footprint.jsonl'; docker exec $PHP wp wc payment_gateway update cod --enabled=true --user=1 --allow-root >/dev/null; docker exec $PHP wp option get woocommerce_default_country --allow-root" > "$OUT/country.txt" 2>&1
COUNTRY=$(tail -n1 "$OUT/country.txt" | cut -d: -f1)
[ -z "$COUNTRY" ] && { echo "setup failed:"; cat "$OUT/country.txt"; exit 1; }
echo "country=$COUNTRY"

# Warm-up (not logged).
for p in / /shop/ /product/wcpos-e2e-simple/ /cart/; do curl -s -o /dev/null ${EXTRA[@]+"${EXTRA[@]}"} "$BASE$p"; done

echo "== storefront pages, 5 iterations x 3 modes"
for i in 1 2 3 4 5; do
  for mode in on plain off; do
    for p in / /shop/ /product/wcpos-e2e-simple/ /cart/; do
      curl -s -o /dev/null -w "$mode $p %{http_code} %{time_total}\n" -H "$TOK" -H "X-WCPOS-Footprint-Mode: $mode" ${EXTRA[@]+"${EXTRA[@]}"} "$BASE$p"
    done
  done
done

echo "== Store API guest checkout, 3 iterations x 3 modes"
# Postcode must validate for the store country (GB on dev-next).
ADDR="{\"first_name\":\"Footprint\",\"last_name\":\"Probe\",\"address_1\":\"1 Probe St\",\"city\":\"London\",\"state\":\"\",\"postcode\":\"SW1A 1AA\",\"country\":\"$COUNTRY\",\"email\":\"footprint-probe@example.com\",\"phone\":\"600000000\"}"
for i in 1 2 3; do
  for mode in on plain off; do
    M="X-WCPOS-Footprint-Mode: $mode"
    HDRS=$(curl -s -D - -o /dev/null -H "$TOK" -H "$M" ${EXTRA[@]+"${EXTRA[@]}"} "$BASE/wp-json/wc/store/v1/cart")
    CT=$(echo "$HDRS" | tr -d '\r' | awk 'tolower($1)=="cart-token:"{print $2}')
    if [ -z "$CT" ]; then echo "no cart token"; echo "$HDRS" | head -20; continue; fi
    curl -s -o "$OUT/add-$mode-$i.json" -w "$mode add-item %{http_code} %{time_total}\n" -H "$TOK" -H "$M" ${EXTRA[@]+"${EXTRA[@]}"} -H "Cart-Token: $CT" -H "Content-Type: application/json" -X POST -d '{"id":98041,"quantity":1}' "$BASE/wp-json/wc/store/v1/cart/add-item"
    curl -s -o "$OUT/checkout-$mode-$i.json" -w "$mode checkout %{http_code} %{time_total}\n" -H "$TOK" -H "$M" ${EXTRA[@]+"${EXTRA[@]}"} -H "Cart-Token: $CT" -H "Content-Type: application/json" -X POST -d "{\"billing_address\":$ADDR,\"shipping_address\":$ADDR,\"payment_method\":\"cod\",\"customer_note\":\"WCPOS footprint probe - safe to delete\"}" "$BASE/wp-json/wc/store/v1/checkout"
  done
done

$SSH "docker exec $PHP cat /tmp/wcpos-footprint.jsonl" > "$OUT/footprint.jsonl"
echo "log lines: $(wc -l < "$OUT/footprint.jsonl")"
echo "Remember to delete the probe orders (customer note 'WCPOS footprint probe') afterwards."
