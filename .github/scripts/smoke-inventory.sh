#!/usr/bin/env bash
# .github/scripts/smoke-inventory.sh — authed integration test for the
# inventory module against the live site. Exercises every master-spec
# success criterion that's checkable from outside a real browser:
#
#   - All edit-form fields rendered for the master schema
#   - Category → sub-category cascade JS embedded in the form
#   - CSV export column headers match the master spec exactly
#   - Create an item with qty < min → Reorder pill appears on profile
#     and the item shows up in /inventory/index.php?view=low
#   - "I checked this today" updates last_stock_check to today's date
#   - Retiring via status=damaged removes the item from the Active filter
#     WITHOUT hard-deleting (it still appears under status=damaged)
#   - Reports rollup renders with the per-category table
#
# Required environment:
#   BASE                  https URL of the live site
#   SMOKE_TEST_USER_ID    users.id of a test admin (NOT a production user)
#   SMOKE_TEST_PIN        4-6 digit PIN for that account
#
# All test rows use Item ID prefix "SMOKE-" so they're easy to spot in
# the admin UI. They never hard-delete — the smoke leaves them in
# status='damaged'. Periodically clean them via the admin Edit screen.
set -euo pipefail

BASE="${BASE:-https://mtt.thelittlegraduates.in}"
CJ=$(mktemp)
trap 'rm -f "$CJ" /tmp/inv_*' EXIT

if [ -z "${SMOKE_TEST_USER_ID:-}" ] || [ -z "${SMOKE_TEST_PIN:-}" ]; then
    echo "::warning::Authed smoke skipped — set SMOKE_TEST_USER_ID + SMOKE_TEST_PIN secrets to enable."
    exit 0
fi

req() { curl -sS -b "$CJ" -c "$CJ" -L --max-time 25 "$@"; }

# ---- 1. Land on /login.php, grab CSRF + session cookie -----------------------
echo "::group::1/9 — Land on login page"
HTML=$(req --fail "$BASE/login.php")
CSRF=$(printf '%s' "$HTML" | grep -oE 'window\.LG_CSRF *= *"[^"]+"' | sed -E 's/.*"([^"]+)".*/\1/' | head -1)
if [ -z "$CSRF" ]; then
    # PHP CSRF helper renders raw JSON-encoded string, sometimes with single
    # quotes — try a looser match.
    CSRF=$(printf '%s' "$HTML" | grep -oE 'LG_CSRF *= *[a-zA-Z0-9_"]+' | head -1 | sed -E 's/.*"([^"]+)".*/\1/')
fi
[ -n "$CSRF" ] || { echo "::error::no CSRF token in /login.php"; exit 1; }
echo "  got CSRF (${#CSRF} chars)"
echo "::endgroup::"

# ---- 2. Sign in --------------------------------------------------------------
echo "::group::2/9 — POST PIN"
RESP=$(req -o /tmp/inv_login -w "%{http_code}" \
    --data-urlencode "user_id=$SMOKE_TEST_USER_ID" \
    --data-urlencode "pin=$SMOKE_TEST_PIN" \
    --data-urlencode "_csrf=$CSRF" \
    "$BASE/login.php")
echo "  HTTP $RESP"
if [ "$RESP" != "200" ] || ! grep -q '"ok":true' /tmp/inv_login; then
    echo "::error::login failed"; cat /tmp/inv_login; exit 1
fi
echo "::endgroup::"

# ---- 3. Edit form must render every master-spec field ------------------------
echo "::group::3/9 — Edit form has every master-spec field"
req --fail -o /tmp/inv_edit "$BASE/inventory/edit.php"
MISSING=0
for field in 'name="sku"' 'name="name"' 'name="category"' 'name="sub_category"' \
              'name="quantity"' 'name="unit"' 'name="reorder_level"' \
              'name="purchase_date"' 'name="unit_cost"' 'name="supplier"' \
              'name="location"' 'name="condition"' 'name="assigned_to"' \
              'name="last_stock_check"' 'name="status"' 'name="notes"'; do
    if ! grep -q -F "$field" /tmp/inv_edit; then
        echo "::error::edit form missing $field"
        MISSING=1
    fi
done
# Cascade JS — the form embeds the category → sub-category map as JSON
if ! grep -q 'Practical Life' /tmp/inv_edit \
  || ! grep -q 'Story Books' /tmp/inv_edit; then
    echo "::error::edit form is missing the category → sub-category map"
    MISSING=1
fi
[ $MISSING -eq 0 ] || exit 1
echo "  ✓ all 16 fields + cascade JSON present"
echo "::endgroup::"

# ---- 4. CSV export headers match master spec --------------------------------
echo "::group::4/9 — CSV export column headers"
req --fail -o /tmp/inv_csv "$BASE/inventory/index.php?format=csv"
HEADER=$(head -1 /tmp/inv_csv | tr -d '\r')
echo "  $HEADER"
for col in "Item ID" "Item Name" "Category" "Sub Category" "Quantity Available" \
           "Unit" "Minimum Stock Level" "Purchase Date" "Purchase Cost" \
           "Supplier/Vendor" "Storage Location" "Condition" "Assigned To" \
           "Last Stock Check" "Status" "Remarks" "Reorder?"; do
    if ! printf '%s' "$HEADER" | grep -qF "$col"; then
        echo "::error::CSV header missing column: $col"
        exit 1
    fi
done
echo "  ✓ every master-spec column present"
echo "::endgroup::"

# ---- 5. Create a test item with qty < min ----------------------------------
echo "::group::5/9 — Create item; Reorder pill must appear"
TS=$(date +%s)
SKU="SMOKE-$TS"
NAME="Inventory smoke $TS"
req -o /tmp/inv_create -w "POST /inventory/edit.php → HTTP %{http_code}\n" \
    --data-urlencode "_csrf=$CSRF" \
    --data-urlencode "sku=$SKU" \
    --data-urlencode "name=$NAME" \
    --data-urlencode "category=Stationery" \
    --data-urlencode "sub_category=Pens" \
    --data-urlencode "quantity=1" \
    --data-urlencode "unit=Nos" \
    --data-urlencode "reorder_level=5" \
    --data-urlencode "purchase_date=$(date +%Y-%m-%d)" \
    --data-urlencode "unit_cost=10.00" \
    --data-urlencode "supplier=Smoke Co." \
    --data-urlencode "location=Office" \
    --data-urlencode "condition=good" \
    --data-urlencode "assigned_to=Smoke Test" \
    --data-urlencode "last_stock_check=" \
    --data-urlencode "status=active" \
    --data-urlencode "notes=Auto-created by CI" \
    "$BASE/inventory/edit.php"
# The redirect lands on /inventory/view.php?id=N. Reorder pill must be in the body.
if ! grep -qE 'Reorder' /tmp/inv_create; then
    echo "::error::Reorder pill missing on the new item's profile"
    head -c 800 /tmp/inv_create
    exit 1
fi
echo "  ✓ Reorder pill visible"
echo "::endgroup::"

# ---- 6. /inventory/index.php?view=low must include the test row -----------
echo "::group::6/9 — Low-stock view shows the new item"
req --fail -o /tmp/inv_low "$BASE/inventory/index.php?view=low"
grep -qF "$SKU" /tmp/inv_low || { echo "::error::SKU $SKU not in low-stock view"; exit 1; }
echo "  ✓ $SKU listed under view=low"
echo "::endgroup::"

# ---- 7. "I checked this today" — last_stock_check should be today's date -----
echo "::group::7/9 — Mark-checked updates last_stock_check"
ITEM_ID=$(grep -oE 'view\.php\?id=[0-9]+' /tmp/inv_low | head -1 | grep -oE '[0-9]+')
[ -n "$ITEM_ID" ] || { echo "::error::could not extract item id"; exit 1; }
echo "  item_id=$ITEM_ID"
req -o /tmp/inv_check -w "POST mark_checked → HTTP %{http_code}\n" \
    --data-urlencode "_csrf=$CSRF" --data-urlencode "op=mark_checked" \
    "$BASE/inventory/view.php?id=$ITEM_ID"
TODAY=$(date +%Y-%m-%d)
# View page renders "Last checked: <strong>$DATE</strong>"
if ! grep -qE "Last checked:.*<strong>$TODAY</strong>" /tmp/inv_check; then
    echo "::error::last_stock_check not updated to $TODAY"
    grep -A 1 "Last checked" /tmp/inv_check | head -3
    exit 1
fi
echo "  ✓ last_stock_check shows $TODAY"
echo "::endgroup::"

# ---- 8. Retire via set_status — never hard-delete --------------------------
echo "::group::8/9 — Retire (status=damaged) — never hard-delete"
req -o /tmp/inv_retire -w "POST set_status → HTTP %{http_code}\n" \
    --data-urlencode "_csrf=$CSRF" --data-urlencode "op=set_status" \
    --data-urlencode "status=damaged" \
    "$BASE/inventory/view.php?id=$ITEM_ID"
# Now /inventory/index.php?status=active must NOT contain the SKU,
# but /inventory/index.php?status=damaged MUST contain it (record preserved).
req --fail -o /tmp/inv_active "$BASE/inventory/index.php?status=active"
if grep -qF "$SKU" /tmp/inv_active; then
    echo "::error::$SKU still in status=active list after retirement"; exit 1
fi
req --fail -o /tmp/inv_damaged "$BASE/inventory/index.php?status=damaged"
grep -qF "$SKU" /tmp/inv_damaged || { echo "::error::$SKU disappeared from status=damaged (hard-deleted?)"; exit 1; }
echo "  ✓ retired but preserved"
echo "::endgroup::"

# ---- 9. Reports page renders the per-category rollup ----------------------
echo "::group::9/9 — Reports rollup"
req --fail -o /tmp/inv_reports "$BASE/inventory/reports.php"
grep -q "Stock value by category" /tmp/inv_reports || { echo "::error::reports rollup missing"; exit 1; }
grep -q "Total (active)" /tmp/inv_reports || { echo "::error::reports tfoot missing"; exit 1; }
echo "  ✓ per-category + overall totals rendered"
echo "::endgroup::"

echo "::notice::Inventory authed smoke ✓ all master-spec criteria pass on the live app"
