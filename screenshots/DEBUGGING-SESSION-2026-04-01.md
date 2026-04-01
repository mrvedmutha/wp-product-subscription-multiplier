# Debugging Session — Multi-Currency + Subscription Price Bug
**Date:** 2026-04-01  
**Plugins Affected:** `eternal-subscription`, `custom-multi-currency`

---

## Bugs Identified

### Bug 1 — Wrong subscription price when switching currencies (Scenario 1)

**Steps to reproduce:**
1. Set currency to INR (default)
2. Go to product page → select 3 Month Plan (₹10,000) → Add to Bag
3. Switch currency to USD
4. Visit cart

**Expected:** $119.99 (the explicit USD override stored in `_esp_3m_final_usd`)  
**Actual:** $41.99 (the one-time purchase USD price stored in `_cmc_price_usd_regular`)

---

### Bug 2 — Currency switch exploit in cart (Scenario 2)

**Steps to reproduce:**
1. Set currency to USD
2. Go to product page → select 3 Month Plan ($119.99) → Add to Bag
3. Visit cart in USD (shows $41.99 — already wrong)
4. Switch to INR

**Expected:** ₹10,000  
**Actual:** ₹119.99 with ₹4,000 struck-through showing fake "Save ₹3,880.01" — a pricing exploit allowing purchase at a 97% discount

---

## Root Cause Analysis

### What the DB actually holds

| Meta Key | Value | Meaning |
|---|---|---|
| `_esp_3m_discount_type` | `fixed_total` | INR subscription price is a fixed total |
| `_esp_3m_discount_value` | `10000` | INR 3-month plan = ₹10,000 |
| `_esp_3m_final_usd` | `119.99` | Explicit USD 3-month override |
| `_cmc_price_usd_regular` | `41.99` | CMC's one-time USD product price |

### The filter conflict

`ESP_Cart::set_cart_item_price` runs on `woocommerce_before_calculate_totals` and calls `$product->set_price(119.99)` for USD.

Then `CMC_Frontend::get_custom_price` runs on `woocommerce_product_get_price` at **priority 10**, finds `_cmc_price_usd_regular = 41.99`, and returns `41.99` — overwriting the subscription price.

Additionally, `_esp_final_price` in cart item data was frozen at add-to-cart time in the then-active currency. Switching currencies never recomputed it, making the exploit possible (₹119.99 instead of ₹10,000).

---

## Fix Applied

**File:** `inc/class-esp-cart.php`

**Change 1 — `set_cart_item_price` now recomputes price from current currency:**  
Instead of reading the stale `_esp_final_price` stored at add-to-cart time, it now calls `ESP_Frontend::get_tier_price($product_id, $months, $current_currency)` on every cart calculation. This ensures the correct per-currency override is always used regardless of which currency was active when the item was added.

**Change 2 — New `override_subscription_price` filter at priority 20:**  
Added a `woocommerce_product_get_price` filter at priority 20 (after CMC's priority 10). `set_cart_item_price` now also populates a static cache (`self::$subscription_prices`) keyed by product ID. The new filter reads this cache and enforces the subscription tier price after CMC has already overridden it with the one-time product price.

```
Hook execution order (per cart calculation):
1. woocommerce_before_calculate_totals
   → ESP set_cart_item_price (populates cache + calls set_price)
2. woocommerce_product_get_price (priority 10)
   → CMC get_custom_price → returns $41.99 (one-time USD price) ← WRONG
3. woocommerce_product_get_price (priority 20)
   → ESP override_subscription_price → returns $119.99 (from cache) ← CORRECT
```

---

## How to Verify the Fix

**Scenario 1:**
1. INR → Add 3-month subscription → Switch to USD → Cart must show **$119.99**

**Scenario 2:**
1. USD → Add 3-month subscription → Switch to INR → Cart must show **₹10,000** (no discount exploit)

---

## Plugin Ownership

| Responsibility | Plugin |
|---|---|
| Subscription tier prices per currency | `eternal-subscription` (`_esp_{n}m_final_{cur}`) |
| One-time product price per currency | `custom-multi-currency` (`_cmc_price_{cur}_regular`) |
| Fix location | `eternal-subscription/inc/class-esp-cart.php` |
