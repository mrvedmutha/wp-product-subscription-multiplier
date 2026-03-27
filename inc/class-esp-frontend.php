<?php
/**
 * Frontend data API for Eternal Subscription.
 *
 * Provides static methods for theme templates and cart integration to query
 * supply plan data without direct meta access.
 *
 * @package EternalSubscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESP_Frontend
 *
 * Exposes a static public API for querying supply plan configuration:
 * whether supply plans are enabled for a product, what active tiers exist,
 * and what the computed price for a given tier is.
 */
class ESP_Frontend {

	/**
	 * Supply plan tier lengths in months.
	 *
	 * @var int[]
	 */
	private const TIERS = array( 3, 6, 9, 12 );

	/**
	 * Checks whether supply plans are enabled for a given product.
	 *
	 * @param int $product_id The WooCommerce product ID.
	 * @return bool True if supply plans are enabled, false otherwise.
	 */
	public static function is_enabled( int $product_id ): bool {
		return '1' === get_post_meta( $product_id, '_esp_enabled', true );
	}

	/**
	 * Returns an array of active tier data for a product in the current currency.
	 *
	 * Each element contains: months, label, contents_note, mrp, final_price,
	 * currency, and symbol.
	 *
	 * @param int $product_id The WooCommerce product ID.
	 * @return array<int, array{months: int, label: string, contents_note: string, mrp: float, final_price: float, currency: string, symbol: string}>
	 */
	public static function get_active_tiers( int $product_id ): array {
		if ( class_exists( 'CMC_Currency_Manager' ) ) {
			$currency = CMC_Currency_Manager::get_active_currency();
		} else {
			$currency = get_option( 'woocommerce_currency' );
		}

		$symbol = get_woocommerce_currency_symbol( $currency );
		$tiers  = array();

		foreach ( self::TIERS as $n ) {
			$active = get_post_meta( $product_id, "_esp_{$n}m_active", true );

			if ( '1' !== $active ) {
				continue;
			}

			$label = get_post_meta( $product_id, "_esp_{$n}m_label", true );

			$tiers[] = array(
				'months'        => $n,
				'label'         => $label ? $label : "{$n} Month Plan",
				'contents_note' => (string) get_post_meta( $product_id, "_esp_{$n}m_contents_note", true ),
				'mrp'           => self::get_mrp( $product_id, $n, $currency ),
				'final_price'   => self::get_tier_price( $product_id, $n, $currency ),
				'currency'      => $currency,
				'symbol'        => $symbol,
			);
		}

		return $tiers;
	}

	/**
	 * Returns the final price for a specific tier of a product in the given currency.
	 *
	 * Priority order:
	 * 1. Stored per-currency override.
	 * 2. Auto-calculation from base price, discount type and discount value.
	 *
	 * @param int    $product_id The WooCommerce product ID.
	 * @param int    $months     The tier length in months.
	 * @param string $currency   ISO 4217 currency code.
	 * @return float The computed final price.
	 */
	public static function get_tier_price( int $product_id, int $months, string $currency ): float {
		$key          = "_esp_{$months}m_final_" . strtolower( $currency );
		$stored_price = get_post_meta( $product_id, $key, true );

		if ( '' !== $stored_price && false !== $stored_price ) {
			return (float) $stored_price;
		}

		$base           = self::get_base_price( $product_id, $currency );
		$discount_type  = (string) get_post_meta( $product_id, "_esp_{$months}m_discount_type", true );
		$discount_value = (float) get_post_meta( $product_id, "_esp_{$months}m_discount_value", true );

		if ( 'percentage' === $discount_type ) {
			return (float) ( $base * $months ) * ( 1 - $discount_value / 100 );
		}

		if ( 'fixed_total' === $discount_type ) {
			return (float) $discount_value;
		}

		return (float) ( $base * $months );
	}

	/**
	 * Returns the MRP (maximum retail price) for a specific tier.
	 *
	 * Uses the stored MRP override if set, otherwise multiplies the base price
	 * by the number of months.
	 *
	 * @param int    $product_id The WooCommerce product ID.
	 * @param int    $months     The tier length in months.
	 * @param string $currency   ISO 4217 currency code.
	 * @return float The MRP for the tier.
	 */
	private static function get_mrp( int $product_id, int $months, string $currency ): float {
		$mrp_override = get_post_meta( $product_id, "_esp_{$months}m_mrp_override", true );

		if ( '' !== $mrp_override && false !== $mrp_override ) {
			return (float) $mrp_override;
		}

		$base = self::get_base_price( $product_id, $currency );

		return (float) ( $base * $months );
	}

	/**
	 * Retrieves the base regular price for a product in the given currency.
	 *
	 * Uses CMC_Product_Fields if the custom-multi-currency plugin is active,
	 * otherwise falls back to the default WooCommerce regular price.
	 *
	 * @param int    $product_id The WooCommerce product ID.
	 * @param string $currency   ISO 4217 currency code.
	 * @return float The base regular price.
	 */
	private static function get_base_price( int $product_id, string $currency ): float {
		if ( class_exists( 'CMC_Currency_Manager' ) && class_exists( 'CMC_Product_Fields' ) ) {
			return (float) CMC_Product_Fields::get_product_price( $product_id, $currency, 'regular' );
		}

		$product = wc_get_product( $product_id );

		return $product ? (float) $product->get_regular_price() : 0.0;
	}
}
