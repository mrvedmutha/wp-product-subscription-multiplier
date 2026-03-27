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
	 * Constructor — registers all WooCommerce cart and order hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_cart_item_price' ) );
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
	 * Overrides the cart item price for supply plan items before totals are calculated.
	 *
	 * @param WC_Cart $cart The WooCommerce cart instance.
	 * @return void
	 */
	public function set_cart_item_price( WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['_esp_final_price'] ) ) {
				$item['data']->set_price( (float) $item['_esp_final_price'] );
			}
		}
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
