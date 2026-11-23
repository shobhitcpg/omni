<?php
/**
 * Optional WooCommerce integration – activates only when WooCommerce is present.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Integrations;

use OmniThreadAI\Settings;

defined( 'ABSPATH' ) || exit;

class WooCommerce {

	public static function register() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		add_filter( 'otai_index_post_text', array( __CLASS__, 'product_index_text' ), 10, 2 );
		add_filter( 'otai_retrieved_context', array( __CLASS__, 'enrich_context' ), 10, 2 );
	}

	private static function price_text( $product ) {
		$price = wc_get_price_to_display( $product );
		if ( '' === $price || null === $price ) {
			return '';
		}
		return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function stock_text( $product ) {
		$status = $product->get_stock_status();
		$labels = array(
			'instock'     => 'In stock',
			'outofstock'  => 'Out of stock',
			'onbackorder' => 'Available on backorder',
		);
		$text = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		if ( $product->managing_stock() && 'instock' === $status && null !== $product->get_stock_quantity() ) {
			$text .= ' (' . (int) $product->get_stock_quantity() . ' left)';
		}
		return $text;
	}

	/**
	 * Add structured product facts to the indexed text.
	 */
	public static function product_index_text( $text, $post ) {
		if ( 'product' !== $post->post_type ) {
			return $text;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product || 'hidden' === $product->get_catalog_visibility() ) {
			return $text;
		}
		$facts = array();
		$price = self::price_text( $product );
		if ( $price ) {
			$facts[] = 'Price: ' . $price . ( $product->is_on_sale() ? ' (on sale)' : '' );
		}
		if ( $product->get_sku() ) {
			$facts[] = 'SKU: ' . $product->get_sku();
		}
		$facts[] = 'Availability: ' . self::stock_text( $product );
		foreach ( $product->get_attributes() as $attribute ) {
			if ( is_object( $attribute ) && $attribute->get_visible() ) {
				$values = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
				$facts[] = wc_attribute_label( $attribute->get_name() ) . ': ' . implode( ', ', (array) $values );
			}
		}
		if ( $product->get_average_rating() > 0 ) {
			$facts[] = sprintf( 'Rating: %s/5 from %d reviews', $product->get_average_rating(), $product->get_review_count() );
		}
		return $text . "\n\n" . implode( "\n", $facts );
	}

	/**
	 * Refresh live price/stock for retrieved products and add the visitor's own orders when asked.
	 *
	 * @param array  $chunks  Retrieved chunks.
	 * @param string $message Visitor message.
	 */
	public static function enrich_context( $chunks, $message ) {
		if ( Settings::get( 'woo_products' ) ) {
			foreach ( $chunks as $i => $chunk ) {
				if ( 'product' !== $chunk['type'] ) {
					continue;
				}
				$product = wc_get_product( $chunk['object_id'] );
				if ( $product ) {
					$chunks[ $i ]['content'] .= "\nLive data – Price: " . self::price_text( $product ) . '; Availability: ' . self::stock_text( $product ) . '.';
				}
			}
		}

		if ( Settings::get( 'woo_orders' ) && preg_match( '/\b(order|orders|delivery|shipment|shipping status|track|tracking|parcel|package)\b/i', $message ) ) {
			$order_chunk = self::order_context( $message );
			if ( $order_chunk ) {
				array_unshift( $chunks, $order_chunk );
			}
		}
		return $chunks;
	}

	private static function order_context( $message ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array(
				'id'        => 0,
				'type'      => 'order',
				'object_id' => 0,
				'title'     => 'Order lookup',
				'url'       => wc_get_page_permalink( 'myaccount' ),
				'content'   => 'The visitor is not logged in. For privacy, order details can only be shown to logged-in customers. Ask them to log in to My Account (link above) or contact support with their order number.',
				'score'     => 1,
				'personal'  => true,
			);
		}

		$orders = array();
		if ( preg_match( '/#?\b(\d{2,12})\b/', $message, $m ) ) {
			$order = wc_get_order( (int) $m[1] );
			if ( $order && (int) $order->get_customer_id() === $user_id ) {
				$orders[] = $order;
			}
		}
		if ( ! $orders ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'limit'       => 3,
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);
		}
		if ( ! $orders ) {
			return array(
				'id'        => 0,
				'type'      => 'order',
				'object_id' => 0,
				'title'     => 'Your orders',
				'url'       => wc_get_page_permalink( 'myaccount' ),
				'content'   => 'The logged-in customer has no orders.',
				'score'     => 1,
				'personal'  => true,
			);
		}

		$lines = array();
		foreach ( $orders as $order ) {
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' × ' . $item->get_quantity();
			}
			$date    = $order->get_date_created();
			$lines[] = sprintf(
				'Order #%s – status: %s; placed: %s; total: %s; items: %s; details: %s',
				$order->get_order_number(),
				wc_get_order_status_name( $order->get_status() ),
				$date ? $date->date_i18n( get_option( 'date_format' ) ) : '-',
				html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
				implode( ', ', $items ),
				$order->get_view_order_url()
			);
		}
		return array(
			'id'        => 0,
			'type'      => 'order',
			'object_id' => 0,
			'title'     => 'Your recent orders',
			'url'       => wc_get_page_permalink( 'myaccount' ),
			'content'   => implode( "\n", $lines ),
			'score'     => 1,
			'personal'  => true,
		);
	}
}
