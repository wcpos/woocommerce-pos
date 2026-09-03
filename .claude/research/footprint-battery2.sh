#!/bin/bash
# Realistic online checkout on dev-next: stock-managed product x2, coupon, account created at checkout, COD.
# Usage: footprint-battery2.sh <outdir>   (set EXTRA_HDR="X-WCPOS-Lane-Variant: <token>" to run the FREE lane)
set -u
OUT=${1:?usage: footprint-battery2.sh <outdir>}
mkdir -p "$OUT"
BASE=https://dev-next.wcpos.com
TOK="X-WCPOS-Footprint: REPLACE-WITH-A-FRESH-TOKEN"
SSH="ssh -o ConnectTimeout=15 root@213.239.218.130"
RUN=$(date +%s)
EXTRA=()
[ -n "${EXTRA_HDR:-}" ] && EXTRA=(-H "$EXTRA_HDR")

# Resolve the dev-next PHP container by its mount, not by the Coolify-generated name (which changes on redeploy).
PHP=$($SSH 'for N in $(docker ps --format "{{.Names}}" | grep "^php-"); do docker inspect "$N" --format "{{range .Mounts}}{{.Source}}{{\"\n\"}}{{end}}" 2>/dev/null | grep -q "/data/wordpress/dev-next/" && echo "$N" && break; done')
[ -z "$PHP" ] && { echo "dev-next PHP container not found"; exit 1; }
echo "php container=$PHP"

# Remember the settings this run changes and restore them on every exit path.
COD_WAS=$($SSH "docker exec $PHP wp wc payment_gateway get cod --user=1 --field=enabled --allow-root" | tr -d '[:space:]')
SIGNUP_WAS=$($SSH "docker exec $PHP wp option get woocommerce_enable_signup_and_login_from_checkout --allow-root" | tr -d '[:space:]')
restore_settings() {
  $SSH "docker exec $PHP wp wc payment_gateway update cod --enabled=${COD_WAS:-false} --user=1 --allow-root >/dev/null; docker exec $PHP wp option update woocommerce_enable_signup_and_login_from_checkout ${SIGNUP_WAS:-no} --allow-root >/dev/null" && echo "settings restored (cod=${COD_WAS:-false}, signup=${SIGNUP_WAS:-no})"
}
trap restore_settings EXIT

# --- setup: log removed (not truncated, so php-fpm can recreate it), product, coupon, options ---
$SSH "docker exec $PHP sh -c 'rm -f /tmp/wcpos-footprint.jsonl';
docker exec $PHP wp wc product create --name='Footprint probe stock product' --type=simple --regular_price=10 --manage_stock=true --stock_quantity=1000 --status=publish --user=1 --porcelain --allow-root;
docker exec $PHP wp wc shop_coupon create --code=probe10-$RUN --discount_type=percent --amount=10 --user=1 --porcelain --allow-root;
docker exec $PHP wp option update woocommerce_enable_signup_and_login_from_checkout yes --allow-root >/dev/null;
docker exec $PHP wp wc payment_gateway update cod --enabled=true --user=1 --allow-root >/dev/null" > "$OUT/setup.txt" 2>&1
PID=$(sed -n '1p' "$OUT/setup.txt"); CID=$(sed -n '2p' "$OUT/setup.txt")
echo "product=$PID coupon=$CID"
[ -z "$PID" ] && { cat "$OUT/setup.txt"; exit 1; }

ADDR_BASE='"first_name":"Footprint","last_name":"Probe","address_1":"1 Probe St","city":"London","state":"","postcode":"SW1A 1AA","country":"GB","phone":"600000000"'

for i in 1 2 3; do
  for mode in on plain off; do
    M="X-WCPOS-Footprint-Mode: $mode"
    EMAIL="footprint-$RUN-$mode-$i@example.com"
    HDRS=$(curl -s -D - -o /dev/null -H "$TOK" -H "$M" ${EXTRA[@]+"${EXTRA[@]}"} "$BASE/wp-json/wc/store/v1/cart")
    CT=$(echo "$HDRS" | tr -d '\r' | awk 'tolower($1)=="cart-token:"{print $2}')
    [ -z "$CT" ] && { echo "no cart token"; continue; }
    curl -s -o "$OUT/add-$mode-$i.json" -w "$mode add-item %{http_code} %{time_total}\n" -H "$TOK" -H "$M" ${EXTRA[@]+"${EXTRA[@]}"} -H "Cart-Token: $CT" -H "Content-Type: application/json" -X POST -d "{\"id\":$PID,\"quantity\":2}" "$BASE/wp-json/wc/store/v1/cart/add-item"
    curl -s -o "$OUT/coupon-$mode-$i.json" -w "$mode apply-coupon %{http_code} %{time_total}\n" -H "$TOK" -H "$M" ${EXTRA[@]+"${EXTRA[@]}"} -H "Cart-Token: $CT" -H "Content-Type: application/json" -X POST -d "{\"code\":\"probe10-$RUN\"}" "$BASE/wp-json/wc/store/v1/cart/apply-coupon"
    curl -s -o "$OUT/checkout-$mode-$i.json" -w "$mode checkout %{http_code} %{time_total}\n" -H "$TOK" -H "$M" ${EXTRA[@]+"${EXTRA[@]}"} -H "Cart-Token: $CT" -H "Content-Type: application/json" -X POST -d "{\"billing_address\":{$ADDR_BASE,\"email\":\"$EMAIL\"},\"shipping_address\":{$ADDR_BASE},\"payment_method\":\"cod\",\"create_account\":true,\"customer_note\":\"WCPOS footprint probe - safe to delete\"}" "$BASE/wp-json/wc/store/v1/checkout"
  done
done

$SSH "docker exec $PHP cat /tmp/wcpos-footprint.jsonl" > "$OUT/footprint.jsonl"
echo "log lines: $(wc -l < "$OUT/footprint.jsonl")"
echo "$PID" > "$OUT/product_id"; echo "$CID" > "$OUT/coupon_id"; echo "$RUN" > "$OUT/run_id"
echo "Remember to delete the probe orders (customer note 'WCPOS footprint probe'), the footprint-* users, product $PID and coupon $CID afterwards."
