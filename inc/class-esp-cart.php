<?php
/**
 * Cart and order integration for Eternal Subscription.
 *
 * Intercepts WooCommerce cart operations to attach supply plan data,
 * override item prices, display the chosen plan in the cart UI, and
 * persist supply plan meta to order line items.
 *
 * @package EternalSubscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESP_Cart
 *
 * Handles the complete cart-to-order lifecycle for supply plan purchases:
 * reading the posted tier selection, storing it in cart item data, applying
 * the computed price, displaying a summary in the cart, and writing order
 * item meta at checkout.
 */
class ESP_Cart {

	/**
	 * Per-request cache of subscription-tier prices keyed by product ID.
	 *
	 * Populated during woocommerce_before_calculate_totals so that the
	 * companion filter override_subscription_price (priority 20) can
	 * enforce the correct tier price after CMC's woocommerce_product_get_price
	 * filter (priority 10) has already run and overwritten it with the
	 * product's standard per-currency price.
	 *
	 * @var array<int, float>
	 */
	private static array $subscription_prices = array();

	/**
	 * Constructor — registers all WooCommerce cart and order hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_cart_item_price' ) );
		add_filter( 'woocommerce_product_get_price', array( $this, 'override_subscription_price' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 3 );
	}

	/**
	 * Attaches supply plan data to the cart item when a tier is selected.
	 *
	 * Reads `eternal_supply_months` from the POST request and, if valid,
	 * stores the tier label, computed final price and currency alongside
	 * the cart item.
	 *
	 * @param array $data       Existing extra cart item data.
	 * @param int   $product_id The product being added to cart.
	 * @return array Modified cart item data.
	 */
	public function add_cart_item_data( array $data, int $product_id ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- add-to-cart nonce verified by WC core; cast to int is sufficient sanitization.
		$months = isset( $_POST['eternal_supply_months'] ) ? (int) wp_unslash( $_POST['eternal_supply_months'] ) : 0;

		if ( $months > 0 && ESP_Frontend::is_enabled( $product_id ) ) {
			$currency = class_exists( 'CMC_Currency_Manager' )
				? CMC_Currency_Manager::get_active_currency()
				: get_option( 'woocommerce_currency' );

			$final_price = ESP_Frontend::get_tier_price( $product_id, $months, $currency );
			$tier        = self::get_tier_meta( $product_id, $months );

			$data['_esp_months']      = $months;
			$data['_esp_label']       = $tier['label'];
			$data['_esp_final_price'] = $final_price;
			$data['_esp_currency']    = $currency;
		}

		return $data;
	}

	/**
	 * Populates the subscription price cache and sets initial prices before
	 * WooCommerce calculates cart totals.
	 *
	 * The price is resolved fresh from the current active currency on every
	 * calculation so that switching currencies after add-to-cart always applies
	 * the correct per-currency tier price. The cache populated here is then used
	 * by override_subscription_price to win against CMC's price filter.
	 *
	 * @param WC_Cart $cart The WooCommerce cart instance.
	 * @return void
	 */
	public function set_cart_item_price( WC_Cart $cart ): void {
		self::$subscription_prices = array();

		$current_currency = class_exists( 'CMC_Currency_Manager' )
			? CMC_Currency_Manager::get_active_currency()
			: get_option( 'woocommerce_currency' );

		foreach ( $cart->get_cart() as $item ) {
			if ( ! isset( $item['_esp_months'], $item['data'] ) ) {
				continue;
			}

			$product_id = (int) $item['data']->get_id();
			$price      = ESP_Frontend::get_tier_price(
				$product_id,
				(int) $item['_esp_months'],
				(string) $current_currency
			);

			self::$subscription_prices[ $product_id ] = $price;
			$item['data']->set_price( $price );
		}
	}

	/**
	 * Enforces the subscription tier price after CMC's product price filter runs.
	 *
	 * CMC hooks into woocommerce_product_get_price at priority 10 and returns
	 * the product's standard per-currency price (e.g. $41.99 for the one-time
	 * USD price), overwriting the tier price set by set_cart_item_price. This
	 * filter runs at priority 20 to restore the correct subscription tier price
	 * for any product that has one cached for the current request.
	 *
	 * @param mixed      $price   The price value as modified by earlier filters.
	 * @param WC_Product $product The product instance.
	 * @return mixed The subscription tier price if applicable, otherwise unchanged.
	 */
	public function override_subscription_price( $price, WC_Product $product ) {
		$product_id = $product->get_id();

		if ( isset( self::$subscription_prices[ $product_id ] ) ) {
			return (string) self::$subscription_prices[ $product_id ];
		}

		return $price;
	}

	/**
	 * Appends the chosen supply plan label to the cart and checkout line item display.
	 *
	 * @param array $data Existing item display data.
	 * @param array $item The cart item array.
	 * @return array Modified item display data.
	 */
	public function display_cart_item_data( array $data, array $item ): array {
		if ( ! empty( $item['_esp_label'] ) ) {
			$data[] = array(
				'name'  => __( 'Supply Plan', 'eternal-subscription' ),
				'value' => esc_html( $item['_esp_label'] ),
			);
		}

		return $data;
	}

	/**
	 * Persists supply plan meta to the WooCommerce order line item at checkout.
	 *
	 * Saves a human-readable supply plan label and the raw months integer so
	 * order details and order admin screens show the chosen tier.
	 *
	 * @param WC_Order_Item_Product $item         The order line item.
	 * @param string                $cart_item_key The cart item key.
	 * @param array                 $values        The cart item data values.
	 * @return void
	 */
	public function save_order_item_meta( WC_Order_Item_Product $item, string $cart_item_key, array $values ): void {
		if ( ! empty( $values['_esp_months'] ) ) {
			$item->add_meta_data( __( 'Supply Plan', 'eternal-subscription' ), $values['_esp_label'], true );
			$item->add_meta_data( '_esp_months', (int) $values['_esp_months'], true );
		}
	}

	/**
	 * Retrieves label and contents note for a supply plan tier from product meta.
	 *
	 * @param int $product_id The product ID.
	 * @param int $months     The tier length in months.
	 * @return array{label: string, contents_note: string} Tier metadata.
	 */
	private static function get_tier_meta( int $product_id, int $months ): array {
		$label = (string) get_post_meta( $product_id, "_esp_{$months}m_label", true );
		return array(
			'label'         => $label ? $label : "{$months} Month Plan",
			'contents_note' => (string) get_post_meta( $product_id, "_esp_{$months}m_contents_note", true ),
		);
	}
}
