#!/bin/bash
# Storefront + Store API checkout battery against dev-next with the footprint probe armed.
set -u
BASE=https://dev-next.wcpos.com
TOK="X-WCPOS-Footprint: REPLACE-WITH-A-FRESH-TOKEN"
PHP=php-wvos4z8i4h42k7vycxwr9fdm-073211564552
SSH="ssh -o ConnectTimeout=15 root@213.239.218.130"

# Truncate the log, enable COD, read the store country.
$SSH "docker exec $PHP sh -c ': > /tmp/wcpos-footprint.jsonl'; docker exec $PHP wp wc payment_gateway update cod --enabled=true --user=1 --allow-root >/dev/null; docker exec $PHP wp option get woocommerce_default_country --allow-root" > $1/country.txt 2>&1
COUNTRY=$(tail -n1 $1/country.txt | cut -d: -f1)
echo "country=$COUNTRY"

# Warm-up (not logged).
for p in / /shop/ /product/wcpos-e2e-simple/ /cart/; do curl -s -o /dev/null $BASE$p; done

echo "== storefront pages, 5 iterations x 2 modes"
for i in 1 2 3 4 5; do
  for mode in on plain off; do
    for p in / /shop/ /product/wcpos-e2e-simple/ /cart/; do
      curl -s -o /dev/null -w "$mode $p %{http_code} %{time_total}\n" -H "$TOK" -H "X-WCPOS-Footprint-Mode: $mode" "$BASE$p"
    done
  done
done

echo "== Store API guest checkout, 3 iterations x 2 modes"
ADDR="{\"first_name\":\"Footprint\",\"last_name\":\"Probe\",\"address_1\":\"1 Probe St\",\"city\":\"Probeville\",\"state\":\"\",\"postcode\":\"SW1A 1AA\",\"country\":\"$COUNTRY\",\"email\":\"footprint-probe@example.com\",\"phone\":\"600000000\"}"
for i in 1 2 3; do
  for mode in on plain off; do
    M="X-WCPOS-Footprint-Mode: $mode"
    HDRS=$(curl -s -D - -o /dev/null -H "$TOK" -H "$M" "$BASE/wp-json/wc/store/v1/cart")
    CT=$(echo "$HDRS" | tr -d '\r' | awk 'tolower($1)=="cart-token:"{print $2}')
    if [ -z "$CT" ]; then echo "no cart token"; echo "$HDRS" | head -20; continue; fi
    curl -s -o $1/add-$mode-$i.json -w "$mode add-item %{http_code} %{time_total}\n" -H "$TOK" -H "$M" -H "Cart-Token: $CT" -H "Content-Type: application/json" -X POST -d '{"id":98041,"quantity":1}' "$BASE/wp-json/wc/store/v1/cart/add-item"
    curl -s -o $1/checkout-$mode-$i.json -w "$mode checkout %{http_code} %{time_total}\n" -H "$TOK" -H "$M" -H "Cart-Token: $CT" -H "Content-Type: application/json" -X POST -d "{\"billing_address\":$ADDR,\"shipping_address\":$ADDR,\"payment_method\":\"cod\",\"customer_note\":\"WCPOS footprint probe - safe to delete\"}" "$BASE/wp-json/wc/store/v1/checkout"
  done
done

# Disable COD again and pull the log.
$SSH "docker exec $PHP wp wc payment_gateway update cod --enabled=false --user=1 --allow-root >/dev/null; docker exec $PHP cat /tmp/wcpos-footprint.jsonl" > $1/footprint.jsonl
echo "log lines: $(wc -l < $1/footprint.jsonl)"
