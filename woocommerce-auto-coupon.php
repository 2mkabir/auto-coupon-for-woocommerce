<?php
/**
 * Auto Coupon For WooCommerce
 *
 * @package   auto_coupon_for_woocommerce
 * @author    Mohammad Mahdi Kabir <2m.kabir@gmail.com>
 * @license   GPL-2.0+
 * @link      https://github.com/2m.kabir/auto-coupon-for-woocommerce
 * @copyright 2023 Mohammad Mahdi Kabir
 *
 * @wordpress-plugin
 * Plugin Name:       Auto Coupon For WooCommerce
 * Plugin URI:        https://github.com/2m.kabir/auto-coupon-for-woocommerce
 * Description:       Automatically add the coupon to the customer's cart or the admin order page if the restrictions are met.
 * Version:           1.0
 * Author:            Mohammad Mahdi Kabir
 * Author URI:        https://www.linkedin.com/in/mohammad-mahdi-kabir/
 * Text Domain:       auto-coupon-for-woocommerce
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/2m.kabir/auto-coupon-for-woocommerce
 * GitHub Branch:     master
 * WC requires at least: 5.0.0
 * WC tested up to: 8.1.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Auto_Coupon_For_Woocommerce_Ya59' ) ) {
	class Auto_Coupon_For_Woocommerce_Ya59 {
		static $instance = false;

		private function __construct() {
            if( get_option( 'woocommerce_enable_coupons' ) === 'yes' ) {
                add_action('plugins_loaded', array($this, 'textdomain'));
                add_action('woocommerce_coupon_options', array($this, 'show_auto_apply_checkbox_in_coupon_options'), 10, 2);
                add_action('woocommerce_coupon_options_save', array($this, 'save_auto_apply_checkbox_in_coupon_options'), 10, 2);
                add_action('manage_shop_coupon_posts_custom_column', array($this, 'show_auto_apply_in_coupons_list'), 100, 2);
                add_action('woocommerce_after_calculate_totals', array($this, 'on_update_cart'), 10);
                add_action('woocommerce_order_before_calculate_totals', array($this, 'on_update_order'), 10, 2);
                add_filter('woocommerce_cart_totals_coupon_html', array($this, 'hide_coupon_remove_link_in_cart'), 10, 3);
                add_filter('woocommerce_cart_totals_coupon_label', array($this, 'replace_coupon_label_with_coupon_description_in_cart'), 10, 2);
            }
		}

		public static function getInstance() {
			if ( ! self::$instance ) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		public function textdomain() {
			load_plugin_textdomain( 'auto-coupon-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		public function show_auto_apply_checkbox_in_coupon_options( $coupon_id, $coupon ) {
			woocommerce_wp_checkbox(
				array(
					'id'          => '_auto_coupon_for_woocommerce_auto_apply',
					'label'       => __( 'Auto apply', 'auto-coupon-for-woocommerce' ),
					'description' => __( "Automatically add the coupon to the customer's cart or the admin order page if the restrictions are met. Please enter a description when you check this box, the description will be shown in the customer's cart if the coupon is applied.", 'auto-coupon-for-woocommerce' ),
					'value'       => wc_bool_to_string( $coupon->get_meta( '_auto_coupon_for_woocommerce_auto_apply', true, 'edit' ) ),
				)
			);
		}

		public function save_auto_apply_checkbox_in_coupon_options( $coupon_id, $coupon ) {
			if ( isset( $_POST['_auto_coupon_for_woocommerce_auto_apply'] ) ) {
				$coupon->update_meta_data( '_auto_coupon_for_woocommerce_auto_apply', true );
			} else {
				$coupon->delete_meta_data( '_auto_coupon_for_woocommerce_auto_apply' );
			}
			$coupon->save_meta_data();
		}

		public function show_auto_apply_in_coupons_list( $column, $post_id ) {
			if ( $column === 'coupon_code' ) {
				$coupon = new WC_Coupon( $post_id );
				echo $coupon->get_meta( '_auto_coupon_for_woocommerce_auto_apply', true, 'edit' ) ? ' ' . esc_html__( '(auto apply)', 'auto-coupon-for-woocommerce' ) : '';
			}
		}

		public function on_update_cart() {
			$coupons  = $this->get_auto_apply_coupons();
			$discount = new WC_Discounts( WC()->cart );
			foreach ( $coupons as $coupon ) {
				if ( ! is_wp_error( $discount->is_coupon_valid( $coupon ) ) ) {
					if ( ! in_array( $coupon->get_code( 'edit' ), WC()->cart->get_applied_coupons() ) ) {
						WC()->cart->add_discount( $coupon->get_code( 'edit' ) );
					}
				}
			}
		}

		public function hide_coupon_remove_link_in_cart( $coupon_html, $coupon, $discount_amount_html ) {
			if ( $coupon->get_meta( '_auto_coupon_for_woocommerce_auto_apply', true, 'edit' ) ) {
				$coupon_html = $discount_amount_html;
			}
			return $coupon_html;
		}

		public function replace_coupon_label_with_coupon_description_in_cart( $coupon_label, $coupon ) {
			if ( $coupon->get_meta( '_auto_coupon_for_woocommerce_auto_apply', true, 'edit' ) ) {
				$coupon_description = $coupon->get_description();
				$coupon_code        = $coupon->get_code( 'edit' );
				if ( empty( $coupon_description ) ) {
					$coupon_label = $coupon_code;
				} else {
					$coupon_label = $coupon_description;
				}
			}
			return $coupon_label;
		}

        public function on_update_order($and_taxes, $order) {
            remove_action('woocommerce_order_before_calculate_totals', array($this, 'on_update_order'), 10, 2);
            $coupons  = $this->get_auto_apply_coupons();
            $discounts = new WC_Discounts( $order );
            foreach ( $coupons as $coupon ) {
                if ( ! is_wp_error( $discounts->is_coupon_valid( $coupon ) ) ) {
                    $order->apply_coupon( $coupon );
                }
                else {
                    $order->remove_coupon( $coupon->get_code( 'edit' ) );
                }
            }
            $order->recalculate_coupons($order, $discounts);
            add_action('woocommerce_order_before_calculate_totals', array($this, 'on_update_order'), 10, 2);
        }

        private function get_auto_apply_coupons() {
            $coupons      = array();
            $args         = array(
                'fields'            => 'ids',
                'posts_per_page'    => -1,
                'post_type'         => 'shop_coupon',
                'post_status'       => 'publish',
                'meta_key'          => '_auto_coupon_for_woocommerce_auto_apply',
                'meta_value'        => true,
                'meta_compare'      => '='
            );
            $coupon_ids = get_posts( $args );
            foreach ( $coupon_ids as $coupon_id ) {
                $coupons[] = new WC_Coupon( $coupon_id );
            }
            return $coupons;
        }
	}

	$Auto_Coupon_For_Woocommerce = Auto_Coupon_For_Woocommerce_Ya59::getInstance();
}

