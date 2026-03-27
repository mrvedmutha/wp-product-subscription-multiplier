# Eternal Subscription

Version 1.0.0 | PHP 8.3+ | WordPress 6.4+ | WooCommerce 8.0+

## Overview

Eternal Subscription is a standalone WooCommerce plugin that adds supply plan purchase options to individual products. Store admins configure up to four tiers — 3, 6, 9, and 12-month plans — each with its own label, contents note, and discount (either a percentage off the regular price multiplied by months, or a fixed total price). Orders are one-time charges only; there is no recurring billing or subscription management. The plugin integrates softly with the custom-multi-currency (CMC) plugin: when CMC is active, per-currency final price overrides are available in the admin.

## Requirements

- WordPress 6.4 or later
- WooCommerce 8.0 or later
- PHP 8.3 or later
- Composer (development dependencies only)

## Installation

1. Clone or copy the plugin into `wp-content/plugins/eternal-subscription/`.
2. Run `composer install` inside the plugin directory to install development tooling (PHPCS, PHPStan). No build step is required for production.
3. Activate **Eternal Subscription** from the WordPress Plugins admin screen.

## How It Works

An admin enables supply plans on a per-product basis and configures each active tier via the product General tab. On the product page, the theme renders a tier selector and includes a hidden `eternal_supply_months` input in the Add to Cart form. When the customer adds the product to cart, the plugin reads that input, looks up the computed final price for the selected tier in the active currency, stores the price and label in the cart item data, and overrides the WooCommerce line item price before totals are calculated. At checkout, the supply plan label and months value are written to the order line item meta so they appear in order details, emails, and the WC admin order screen.

## Admin Usage

1. Open a product for editing in WP Admin.
2. Navigate to the **General** tab.
3. Tick **Enable Supply Plans**. The tiers container will expand.
4. For each desired tier (3, 6, 9, or 12 months), click its header to expand it.
5. Tick **Activate this tier** to make the tier available on the front end.
6. Optionally fill in a **Tier Label** (defaults to `N Month Plan`) and a **Contents Note**.
7. Optionally set an **MRP Override**. If left empty the MRP is `regular_price × months`.
8. Choose a **Discount Type**: Percentage or Fixed Total.
9. Enter the **Discount Value** (percentage points or fixed price).
10. The **Computed Final Price** span updates in real time as you type.
11. If the custom-multi-currency plugin is active, per-currency override fields appear below; leave them empty to use auto-calculation.
12. Save the product.

## Meta Keys

| Meta Key | Type | Description |
|---|---|---|
| `_esp_enabled` | `'1'` / `'0'` | Master switch: supply plans enabled for this product |
| `_esp_{N}m_active` | `'1'` / `'0'` | Whether the N-month tier is active (N = 3, 6, 9, 12) |
| `_esp_{N}m_label` | string | Display label for the tier, e.g. "6 Month Bundle" |
| `_esp_{N}m_contents_note` | string | Optional contents description shown on the front end |
| `_esp_{N}m_discount_type` | `'percentage'` / `'fixed_total'` | How the discount is applied |
| `_esp_{N}m_discount_value` | decimal string | Percentage points or fixed price amount |
| `_esp_{N}m_mrp_override` | decimal string | Manual MRP; if empty, MRP = regular_price × N |
| `_esp_{N}m_final_{CUR}` | decimal string | Per-currency final price override (CUR = lowercase ISO code, e.g. `usd`) |

## Public API (Theme Integration)

### `ESP_Frontend::is_enabled( int $product_id ): bool`

Returns `true` if supply plans are enabled for the given product ID, `false` otherwise.

```php
if ( ESP_Frontend::is_enabled( get_the_ID() ) ) {
    // render tier selector
}
```

### `ESP_Frontend::get_active_tiers( int $product_id ): array`

Returns an indexed array of active tier data arrays in the current currency. Each element has the following shape:

```php
[
    'months'        => int,    // e.g. 6
    'label'         => string, // e.g. "6 Month Plan"
    'contents_note' => string, // e.g. "Includes 6 units"
    'mrp'           => float,  // maximum retail price
    'final_price'   => float,  // discounted price
    'currency'      => string, // e.g. "USD"
    'symbol'        => string, // e.g. "$"
]
```

### `ESP_Frontend::get_tier_price( int $product_id, int $months, string $currency ): float`

Returns the computed final price for a specific tier in the specified currency. Checks the per-currency stored override first; falls back to auto-calculation from the regular price, discount type and discount value.

## Theme Cart Integration

The theme's Add to Cart form must include a hidden input named `eternal_supply_months` whose value is the selected tier length in months. The plugin reads this value when the form is submitted.

Example — a simple static form:

```html
<form action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
    <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( get_the_ID() ); ?>">
    <input type="hidden" name="eternal_supply_months" value="6">
    <button type="submit">Add 6-Month Plan to Cart</button>
</form>
```

For a dynamic selector, render radio buttons or a `<select>` and use JavaScript to keep a hidden `eternal_supply_months` input in sync with the selected value before the form is submitted.

## Currency Integration (custom-multi-currency)

Eternal Subscription has a **soft dependency** on the custom-multi-currency (CMC) plugin. When CMC is active:

- Per-currency final price override fields appear in the admin tier panels (one field per additional currency configured in CMC).
- The active cart currency is read from `CMC_Currency_Manager::get_active_currency()`.
- Base prices are read from `CMC_Product_Fields::get_product_price()`.

When CMC is not active, the plugin operates entirely on the default WooCommerce store currency (`woocommerce_currency` option) and reads base prices from the standard WooCommerce product regular price. There is no hard dependency — the plugin activates and works correctly without CMC installed.

## Quality Checks

```bash
npm run ai:check
```

Runs PHPCS (WordPress Coding Standards) and PHPStan (level 6) across all plugin PHP files. Both checks must pass with zero errors before a commit is made.

## Git Hooks

Husky is configured with a pre-commit hook that runs `lint-staged`. Any PHP file staged for commit is automatically checked by PHPCS. Fix any reported violations before the commit will be accepted.

## Related

- [eternal-product-meta](https://github.com/mrvedmutha/wp-custom-product-meta) — custom product meta plugin used alongside this one.
