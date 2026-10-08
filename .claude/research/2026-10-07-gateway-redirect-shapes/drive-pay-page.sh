#!/bin/bash
# Drive the WCPOS hosted pay page over real HTTP for one gateway.
# Usage: drive-pay-page.sh <gateway_id>
set -u
GW="$1"
WT=/Users/kilbot/Projects/woocommerce-pos-worktrees/fix-hosted-gateway-redirect/woocommerce-pos
WPENV=/Users/kilbot/Projects/woocommerce-pos/node_modules/.bin/wp-env
S=/private/tmp/claude-501/-Users-kilbot-Projects-monorepo-v2/16fb0e3f-b9e4-4748-aaca-e39f37d5a2ba/scratchpad
cd "$WT" || exit 1
TOKEN=$("$WPENV" run cli -- wp eval 'echo \WCPOS\WooCommercePOS\Services\Auth::instance()->generate_token( get_user_by( "login", "admin" ) ) . "\n";' 2>/dev/null | grep -E "^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$" | tail -1)
echo "token: ${TOKEN:0:12}... (${#TOKEN} chars)"

# 1. A fresh POS order at pos-open, one line item, the gateway preselected.
read -r ID KEY < <("$WPENV" run cli -- wp eval '
$order = wc_create_order();
$pid = (int) get_option( "wcpos_probe_product_id" );
$order->add_product( wc_get_product( $pid ), 1 );
$order->set_payment_method( "'"$GW"'" );
$order->update_meta_data( "_pos", "1" );
$order->set_created_via( "woocommerce-pos" );
$order->calculate_totals();
$order->set_status( "pos-open" );
$order->save();
echo $order->get_id() . " " . $order->get_order_key();
' 2>/dev/null | grep -E '^[0-9]+ wc_order_' | tail -1)
echo "order=$ID key=$KEY gateway=$GW"

URL="http://localhost:8888/wcpos-checkout/order-pay/$ID/?pay_for_order=true&key=$KEY&token=$TOKEN"
JAR="$S/cookies-$ID.txt"; rm -f "$JAR"

# 2. GET the pay page (POS marker header), take the pay nonce.
HTML=$(curl -s -c "$JAR" -b "$JAR" -H 'X-WCPOS: 1' "$URL")
NONCE=$(printf '%s' "$HTML" | grep -o 'name="woocommerce-pay-nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
echo "pay-page: $(printf '%s' "$HTML" | grep -c "wcpos-process-payment") process-payment listener(s); nonce=${NONCE:-MISSING}; gateway offered: $(printf '%s' "$HTML" | grep -c "value=\"$GW\"")"

# 3. POST the pay form. No redirect following: the Location IS the gateway's declared target.
RESP=$(curl -s -o /dev/null -c "$JAR" -b "$JAR" -H 'X-WCPOS: 1' -w '%{http_code} %{redirect_url}' \
  --data-urlencode "woocommerce_pay=1" --data-urlencode "payment_method=$GW" \
  --data-urlencode "woocommerce-pay-nonce=$NONCE" --data-urlencode "terms=on" --data-urlencode "terms-field=1" \
  --data-urlencode "_wp_http_referer=/wcpos-checkout/order-pay/$ID/" "$URL")
echo "POST -> $RESP"

# 4. Server truth.
"$WPENV" run cli -- wp eval '
$o = wc_get_order( '"$ID"' );
echo "status=" . $o->get_status() . " date_paid=" . ( $o->get_date_paid() ? $o->get_date_paid()->date( "c" ) : "null" ) . " method=" . $o->get_payment_method() . "\n";
foreach ( wc_get_order_notes( array( "order_id" => '"$ID"', "order_by" => "date_created", "order" => "ASC" ) ) as $n ) { echo "  note: " . $n->content . "\n"; }
' 2>/dev/null | grep -v "^✔"
