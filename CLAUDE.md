# eternal-subscription — Project Rules

WordPress + WooCommerce plugin: adds supply plan purchase options (3/6/9/12-month tiers) to any product. One-time orders only — **not** a recurring billing plugin. Each supply plan is a single order for N months' worth of product shipped together.

---

## Stack

| Layer | Technology |
|---|---|
| PHP | 8.3 — WordPress + WooCommerce plugin APIs |
| Admin JS | Vanilla JS (ES5-compatible, no build step) |
| Admin CSS | Plain CSS |
| Linter (PHP) | PHPCS 3.x + WordPress Coding Standards 3.x |
| Static analysis (PHP) | PHPStan level 6 + szepeviktor/phpstan-wordpress + WooCommerce stubs |
| Package manager | npm (for Husky only — no build toolchain) |
| Dependency manager (PHP) | Composer |
| Git hooks | Husky 9 + lint-staged |

> **No build step.** This plugin has no React, no JSX, no webpack. Admin UI uses native WooCommerce PHP field helpers + plain JS. Keep it that way.

---

## Directory Structure

```
eternal-subscription/
├── eternal-subscription.php        # Bootstrap — constants, esp_init(), woocommerce_loaded
├── inc/
│   ├── class-esp-product-fields.php  # WC product editor fields, save, admin column
│   ├── class-esp-cart.php            # Cart price override + order meta
│   └── class-esp-frontend.php        # Static public API for theme templates
├── assets/
│   ├── css/esp-admin.css             # Admin accordion + tier UI styles
│   └── js/esp-admin.js               # Toggle, accordion, real-time price compute
├── phpcs.xml                         # PHPCS ruleset (WordPress + exclusions)
└── phpstan.neon                      # PHPStan config (level 6, WC stubs)
```

---

## PHP Rules

- **Standard:** WordPress Coding Standards (`phpcs.xml`)
- **Indentation:** Tabs (not spaces)
- **Array syntax:** Long form `array()` — WordPress standard
- **Callbacks:** Always `array( $this, 'method' )` for hooks
- **Sanitisation:**
  - All `$_POST` values must be sanitised before saving (`sanitize_text_field`, `sanitize_key`, `sanitize_textarea_field`, `wc_format_decimal`, `absint`)
  - Always `wp_unslash()` before sanitising string inputs
  - `wc_format_decimal()` counts as sanitisation for price fields — add `phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` inline comment when used
- **Nonce verification:** `woocommerce_process_product_meta` hook — WooCommerce verifies the nonce before this hook fires. Use `phpcs:disable/enable WordPress.Security.NonceVerification.Missing` block to acknowledge this
- **Escaping:** All output escaped — `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` as appropriate
- **DocBlocks:** Required on every class and every public/private method
- **File headers:** Every PHP file must have `@package EternalSubscription`
- **No dead code:** No `var_dump`, `print_r`, `error_log` in committed code
- **PHP version:** Target PHP 8.3

### PHPStan

Level 6. Runs against `inc/` and `eternal-subscription.php`.

Run: `npm run lint:phpstan`

---

## Admin JavaScript Rules (`assets/js/esp-admin.js`)

- **No build step** — vanilla JS only, no ES6 modules, no import/export
- All logic inside a single `DOMContentLoaded` listener
- No jQuery dependency — plain `document.querySelector`, `addEventListener`
- Real-time price computation reads: base WC price (`#_regular_price`), MRP override, discount type radio, discount value
- CMC visibility: read `espAdminData.cmc_active` (localised via `wp_localize_script`) to show/hide per-currency fields
- Do not use `console.log` in committed code

---

## Admin CSS Rules (`assets/css/esp-admin.css`)

- BEM-inspired class names prefixed with `.esp-`
- No inline styles in PHP output — use CSS classes
- Accordion pattern mirrors `custom-multi-currency` admin UI for visual consistency

---

## Quality Gate

`npm run ai:check` must pass before every merge.

```
npm run lint:php       # PHPCS — WordPress coding standards
npm run lint:phpstan   # PHPStan level 6
```

Both must exit 0.

---

## Git Hooks (Husky + lint-staged)

Pre-commit hook runs automatically on `git commit`. Staged PHP files only:

| File pattern | Check |
|---|---|
| `**/*.php` | PHPCS |

To bypass in an emergency (not recommended): `git commit --no-verify`

---

## CMC Integration (Soft Dependency)

`custom-multi-currency` is a **soft dependency** — always guard with `class_exists( 'CMC_Currency_Manager' )`.

Three integration points:
1. **Admin fields** — `CMC_Currency_Manager::get_additional_currencies()` generates per-currency price inputs per tier
2. **Price calculation** — `CMC_Product_Fields::get_product_price( $id, $currency, 'regular' )` for auto-calc
3. **Cart** — `CMC_Currency_Manager::get_active_currency()` identifies checkout currency

If CMC is deactivated: plugin continues to work using base WC currency.

---

## WooCommerce Cart Integration

The theme's "Add to Cart" form must submit:
```html
<input type="hidden" name="eternal_supply_months" value="3">
```

Value is `0` for one-time purchase, or `3/6/9/12` for a supply plan.

The plugin reads this in `woocommerce_add_cart_item_data` and overrides the cart item price in `woocommerce_before_calculate_totals`.

**Never** override WC price via `woocommerce_product_get_price` — that filter conflicts with CMC. Always override at the cart item level.

---

## Public API (Theme-Facing)

Theme templates must only use `ESP_Frontend` static methods. Never read raw meta keys directly in templates.

```php
ESP_Frontend::is_enabled( int $product_id ): bool
ESP_Frontend::get_active_tiers( int $product_id ): array
ESP_Frontend::get_tier_price( int $product_id, int $months, string $currency ): float
```

---

## Meta Key Conventions

All meta keys use `_esp_` prefix (Eternal Supply Plan).

- `_esp_enabled` — master toggle
- `_esp_{N}m_active` — is this tier enabled? (N = 3, 6, 9, 12)
- `_esp_{N}m_label` — display label
- `_esp_{N}m_contents_note` — small-print note
- `_esp_{N}m_discount_type` — `percentage` or `fixed_total`
- `_esp_{N}m_discount_value` — the discount amount
- `_esp_{N}m_mrp_override` — optional manual MRP
- `_esp_{N}m_final_{cur}` — per-currency final price override (e.g. `_esp_3m_final_usd`)

Never add new meta keys outside this naming convention.

---

## Plugin Boundaries

This plugin handles **pricing and cart logic only**. Never add:
- Custom taxonomies or product display meta → belongs in `eternal-product-meta`
- Per-currency exchange rates → belongs in `custom-multi-currency`
- Recurring billing, Stripe subscriptions — out of scope entirely

---

## Key Hooks

| Hook | Class | Purpose |
|---|---|---|
| `woocommerce_loaded` | bootstrap | Loads all 3 class files |
| `woocommerce_product_options_pricing` | `ESP_Product_Fields` | Renders supply plan UI in WC General tab |
| `woocommerce_process_product_meta` | `ESP_Product_Fields` | Saves all tier meta |
| `admin_enqueue_scripts` | `ESP_Product_Fields` | Enqueues CSS + JS on product edit pages |
| `manage_product_posts_columns` | `ESP_Product_Fields` | Adds Supply Plan column to products list |
| `woocommerce_add_cart_item_data` | `ESP_Cart` | Tags cart item with tier data |
| `woocommerce_before_calculate_totals` | `ESP_Cart` | Overrides line item price |
| `woocommerce_get_item_data` | `ESP_Cart` | Displays plan label in cart/checkout |
| `woocommerce_checkout_create_order_line_item` | `ESP_Cart` | Saves plan to order meta |

---

## Related Plugin

`eternal-product-meta` — `https://github.com/mrvedmutha/wp-custom-product-meta`
