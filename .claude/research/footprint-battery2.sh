#!/bin/bash
# Realistic online checkout on dev-next: stock-managed product x2, coupon, account created at checkout, COD.
# Usage: battery2.sh <outdir>
set -u
OUT=$1; mkdir -p "$OUT"
BASE=https://dev-next.wcpos.com
TOK="X-WCPOS-Footprint: REPLACE-WITH-A-FRESH-TOKEN"
PHP=php-wvos4z8i4h42k7vycxwr9fdm-073211564552
SSH="ssh -o ConnectTimeout=15 root@213.239.218.130"
RUN=$(date +%s)
# Optional extra header for every probed request, e.g. the dev-next lane-variant swap to run the FREE plugin.
EXTRA=()
[ -n "${EXTRA_HDR:-}" ] && EXTRA=(-H "$EXTRA_HDR")

# --- setup: product, coupon, options ---
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

# --- teardown: options back, pull log, list what to delete ---
$SSH "docker exec $PHP wp option update woocommerce_enable_signup_and_login_from_checkout no --allow-root >/dev/null;
docker exec $PHP wp wc payment_gateway update cod --enabled=false --user=1 --allow-root >/dev/null;
docker exec $PHP cat /tmp/wcpos-footprint.jsonl" > "$OUT/footprint.jsonl"
echo "log lines: $(wc -l < "$OUT/footprint.jsonl")"
echo "$PID" > "$OUT/product_id"; echo "$CID" > "$OUT/coupon_id"; echo "$RUN" > "$OUT/run_id"
