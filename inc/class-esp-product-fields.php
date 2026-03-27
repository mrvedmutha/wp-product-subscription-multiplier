<?php
/**
 * Admin product fields for Eternal Subscription.
 *
 * Registers supply plan pricing fields in the WooCommerce product General tab,
 * handles saving of those fields, enqueues admin assets, and adds a Supply Plan
 * column to the Products list table.
 *
 * @package EternalSubscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ESP_Product_Fields
 *
 * Manages all wp-admin UI for the supply plan feature: field rendering,
 * meta saving, asset enqueueing and the product list column.
 */
class ESP_Product_Fields {

	/**
	 * Supply plan tier lengths in months.
	 *
	 * @var int[]
	 */
	private const TIERS = array( 3, 6, 9, 12 );

	/**
	 * Constructor — registers all admin hooks.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'add_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_product_posts_columns', array( $this, 'add_supply_plan_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_supply_plan_column' ), 10, 2 );
	}

	/**
	 * Outputs the Supply Plan Pricing section in the WooCommerce General tab.
	 *
	 * @return void
	 */
	public function add_fields(): void {
		global $post;

		$product_id = (int) $post->ID;
		$enabled    = get_post_meta( $product_id, '_esp_enabled', true );
		$cmc_active = class_exists( 'CMC_Currency_Manager' ) ? 1 : 0;

		echo '<div class="options_group esp-supply-plan-wrapper">';

		echo '<p class="form-field">';
		echo '<label>' . esc_html__( 'Enable Supply Plans', 'eternal-subscription' ) . '</label>';
		woocommerce_wp_checkbox(
			array(
				'id'            => '_esp_enabled',
				'label'         => '',
				'description'   => esc_html__( 'Enable supply plan purchase options for this product.', 'eternal-subscription' ),
				'value'         => $enabled,
				'cbvalue'       => '1',
				'wrapper_class' => 'inline',
			)
		);
		echo '</p>';

		echo '<div class="esp-tiers-container" id="esp-tiers-container" data-cmc-active="' . esc_attr( (string) $cmc_active ) . '">';

		foreach ( self::TIERS as $n ) {
			$prefix        = "_esp_{$n}m";
			$tier_active   = get_post_meta( $product_id, "{$prefix}_active", true );
			$tier_label    = get_post_meta( $product_id, "{$prefix}_label", true );
			$contents_note = get_post_meta( $product_id, "{$prefix}_contents_note", true );
			$mrp_override  = get_post_meta( $product_id, "{$prefix}_mrp_override", true );
			$disc_type_raw = get_post_meta( $product_id, "{$prefix}_discount_type", true );
			$disc_type     = $disc_type_raw ? $disc_type_raw : 'percentage';
			$disc_value    = get_post_meta( $product_id, "{$prefix}_discount_value", true );

			echo '<div class="esp-tier" data-months="' . esc_attr( (string) $n ) . '">';

			// Tier header.
			echo '<div class="esp-tier-header">';
			echo '<span>' . esc_html( sprintf( '%d Month Plan', $n ) ) . '</span>';
			echo '<button type="button" class="esp-tier-toggle">&#9660;</button>';
			echo '</div>';

			// Tier body.
			echo '<div class="esp-tier-body">';

			// Active checkbox.
			woocommerce_wp_checkbox(
				array(
					'id'          => "{$prefix}_active",
					'label'       => esc_html__( 'Activate this tier', 'eternal-subscription' ),
					'value'       => $tier_active,
					'cbvalue'     => '1',
				)
			);

			// Tier label.
			woocommerce_wp_text_input(
				array(
					'id'          => "{$prefix}_label",
					'label'       => esc_html__( 'Tier Label', 'eternal-subscription' ),
					'placeholder' => esc_attr( sprintf( '%d Month Plan', $n ) ),
					'value'       => esc_attr( (string) $tier_label ),
				)
			);

			// Contents note.
			woocommerce_wp_textarea_input(
				array(
					'id'    => "{$prefix}_contents_note",
					'label' => esc_html__( 'Contents Note', 'eternal-subscription' ),
					'value' => esc_textarea( (string) $contents_note ),
				)
			);

			// MRP override.
			woocommerce_wp_text_input(
				array(
					'id'          => "{$prefix}_mrp_override",
					'label'       => esc_html__( 'MRP Override (leave empty for auto)', 'eternal-subscription' ),
					'type'        => 'number',
					'placeholder' => '',
					'value'       => esc_attr( (string) $mrp_override ),
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
				)
			);

			// Discount type radio group.
			echo '<p class="form-field">';
			echo '<label>' . esc_html__( 'Discount Type', 'eternal-subscription' ) . '</label>';
			echo '<span class="esp-discount-type-group">';
			echo '<label>';
			echo '<input type="radio" name="' . esc_attr( "{$prefix}_discount_type" ) . '" value="percentage"' . checked( $disc_type, 'percentage', false ) . '>';
			echo ' ' . esc_html__( 'Percentage', 'eternal-subscription' );
			echo '</label>';
			echo '<label>';
			echo '<input type="radio" name="' . esc_attr( "{$prefix}_discount_type" ) . '" value="fixed_total"' . checked( $disc_type, 'fixed_total', false ) . '>';
			echo ' ' . esc_html__( 'Fixed Total', 'eternal-subscription' );
			echo '</label>';
			echo '</span>';
			echo '</p>';

			// Discount value.
			woocommerce_wp_text_input(
				array(
					'id'    => "{$prefix}_discount_value",
					'label' => esc_html__( 'Discount Value', 'eternal-subscription' ),
					'type'  => 'number',
					'value' => esc_attr( (string) $disc_value ),
					'custom_attributes' => array(
						'step' => 'any',
						'min'  => '0',
					),
				)
			);

			// Computed final price (read-only).
			echo '<p class="form-field">';
			echo '<label>' . esc_html__( 'Computed Final Price', 'eternal-subscription' ) . '</label>';
			echo '<span id="esp-final-' . esc_attr( "{$n}m" ) . '" class="esp-final-price">&mdash;</span>';
			echo '</p>';

			// Per-currency fields when CMC is active.
			if ( $cmc_active && class_exists( 'CMC_Currency_Manager' ) ) {
				$additional_currencies = CMC_Currency_Manager::get_additional_currencies();

				if ( ! empty( $additional_currencies ) ) {
					echo '<div class="esp-currency-prices">';
					echo '<h4>' . esc_html__( 'Per-Currency Final Price Overrides', 'eternal-subscription' ) . '</h4>';

					foreach ( $additional_currencies as $currency ) {
						$cur          = strtolower( $currency );
						$meta_key     = "{$prefix}_final_{$cur}";
						$stored_value = get_post_meta( $product_id, $meta_key, true );

						woocommerce_wp_text_input(
							array(
								'id'          => $meta_key,
								/* translators: %s: currency code */
								'label'       => sprintf( esc_html__( 'Final Price (%s)', 'eternal-subscription' ), esc_html( strtoupper( $currency ) ) ),
								'type'        => 'number',
								'value'       => esc_attr( (string) $stored_value ),
								'placeholder' => esc_attr__( 'Auto-calculated if empty', 'eternal-subscription' ),
								'custom_attributes' => array(
									'step' => 'any',
									'min'  => '0',
								),
							)
						);
					}

					echo '</div>';
				}
			}

			echo '</div>'; // .esp-tier-body
			echo '</div>'; // .esp-tier
		}

		echo '</div>'; // .esp-tiers-container
		echo '</div>'; // .esp-supply-plan-wrapper
	}

	/**
	 * Saves supply plan meta fields when a product is saved.
	 *
	 * @param int $post_id The product post ID.
	 * @return void
	 */
	public function save_fields( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by WooCommerce before this hook fires.
		update_post_meta( $post_id, '_esp_enabled', isset( $_POST['_esp_enabled'] ) ? '1' : '0' );

		foreach ( array( 3, 6, 9, 12 ) as $n ) {
			$prefix = "_esp_{$n}m";

			update_post_meta( $post_id, "{$prefix}_active", isset( $_POST[ "{$prefix}_active" ] ) ? '1' : '0' );
			update_post_meta( $post_id, "{$prefix}_label", sanitize_text_field( wp_unslash( $_POST[ "{$prefix}_label" ] ?? '' ) ) );
			update_post_meta( $post_id, "{$prefix}_contents_note", sanitize_textarea_field( wp_unslash( $_POST[ "{$prefix}_contents_note" ] ?? '' ) ) );
			update_post_meta( $post_id, "{$prefix}_discount_type", sanitize_key( wp_unslash( $_POST[ "{$prefix}_discount_type" ] ?? 'percentage' ) ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_decimal() sanitizes decimal values.
			update_post_meta( $post_id, "{$prefix}_discount_value", wc_format_decimal( wp_unslash( $_POST[ "{$prefix}_discount_value" ] ?? 0 ) ) );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_decimal() sanitizes decimal values.
			update_post_meta( $post_id, "{$prefix}_mrp_override", wc_format_decimal( wp_unslash( $_POST[ "{$prefix}_mrp_override" ] ?? '' ) ) );

			if ( class_exists( 'CMC_Currency_Manager' ) ) {
				foreach ( CMC_Currency_Manager::get_additional_currencies() as $currency ) {
					$cur = strtolower( $currency );
					$key = "{$prefix}_final_{$cur}";
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_format_decimal() sanitizes decimal values.
					update_post_meta( $post_id, $key, wc_format_decimal( wp_unslash( $_POST[ $key ] ?? '' ) ) );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Enqueues admin CSS and JS on the product edit screen.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( 'product' !== get_post_type() ) {
			return;
		}

		wp_enqueue_style(
			'esp-admin-css',
			ESP_URL . 'assets/css/esp-admin.css',
			array(),
			ESP_VERSION
		);

		wp_enqueue_script(
			'esp-admin-js',
			ESP_URL . 'assets/js/esp-admin.js',
			array(),
			ESP_VERSION,
			true
		);

		wp_localize_script(
			'esp-admin-js',
			'espAdminData',
			array(
				'cmc_active' => class_exists( 'CMC_Currency_Manager' ),
			)
		);
	}

	/**
	 * Inserts the Supply Plan column after the Price column in the products list table.
	 *
	 * @param array $columns Existing column definitions.
	 * @return array Modified column definitions.
	 */
	public function add_supply_plan_column( array $columns ): array {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'price' === $key ) {
				$new_columns['supply_plan'] = __( 'Supply Plan', 'eternal-subscription' );
			}
		}

		return $new_columns;
	}

	/**
	 * Renders the content of the Supply Plan column for each product row.
	 *
	 * @param string $column  The column key being rendered.
	 * @param int    $post_id The current product post ID.
	 * @return void
	 */
	public function render_supply_plan_column( string $column, int $post_id ): void {
		if ( 'supply_plan' !== $column ) {
			return;
		}

		$enabled = get_post_meta( $post_id, '_esp_enabled', true );

		if ( '1' !== $enabled ) {
			echo '&mdash;';
			return;
		}

		$labels = array();

		foreach ( self::TIERS as $n ) {
			$active = get_post_meta( $post_id, "_esp_{$n}m_active", true );

			if ( '1' === $active ) {
				$label    = get_post_meta( $post_id, "_esp_{$n}m_label", true );
				$labels[] = $label ? $label : "{$n}M";
			}
		}

		if ( empty( $labels ) ) {
			echo '&mdash;';
			return;
		}

		echo esc_html( implode( ' / ', $labels ) );
	}
}
