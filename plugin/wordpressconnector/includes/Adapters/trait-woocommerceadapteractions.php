<?php
/**
 * Canonical action implementations for WooCommerceAdapter.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

/**
 * Canonical implementations with native legacy method aliases in the host.
 */
trait WooCommerceAdapterActions {
	/**
	 * Handle the order list connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function order_list( array $payload ): array {
		$this->assert_woo();
		if ( ! function_exists( 'wc_get_orders' ) ) {
			throw new RuntimeException( 'WooCommerce order query API is unavailable.' );
		}
		Policy::assertReadablePostType( 'shop_order' );
		$limit = isset( $payload['per_page'] ) ? max( 1, min( 100, (int) $payload['per_page'] ) ) : 25;
		$page  = isset( $payload['page'] ) ? max( 1, (int) $payload['page'] ) : 1;
		$args  = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'objects',
		);
		if ( isset( $payload['status'] ) ) {
			$status = sanitize_key( (string) $payload['status'] );
			if ( strpos( $status, 'wc-' ) !== 0 ) {
				$status = 'wc-' . $status;
			}
			$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
			if ( ! isset( $statuses[ $status ] ) ) {
				throw new RuntimeException( 'Unknown WooCommerce order status.' );
			}
			$args['status'] = array( $status );
		}
		if ( isset( $payload['customer_id'] ) ) {
			$args['customer'] = $this->positive_integer_id( $payload['customer_id'], 'customer_id must be a positive integer.' );
		}
		$query  = wc_get_orders( $args );
		$orders = is_object( $query ) && isset( $query->orders ) ? $query->orders : (array) $query;
		$items  = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$items[] = $this->order_snapshot( $order, false, false );
			}
		}
		return array(
			'orders'   => $items,
			'page'     => $page,
			'per_page' => $limit,
			'total'    => is_object( $query ) && isset( $query->total ) ? (int) $query->total : count( $items ),
			'pages'    => is_object( $query ) && isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 1,
		);
	}

	/**
	 * Handle the order get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function order_get( array $payload ): array {
		$this->assert_woo();
		if ( ! function_exists( 'wc_get_order' ) ) {
			throw new RuntimeException( 'WooCommerce order API is unavailable.' );
		}
		Policy::assertReadablePostType( 'shop_order' );
		$id = array_key_exists( 'id', $payload ) ? $this->positive_integer_id( $payload['id'], 'A positive order id is required.' ) : 0;
		if ( $id < 1 ) {
			throw new RuntimeException( 'A positive order id is required.' );
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			throw new RuntimeException( 'WooCommerce order not found.' );
		}
		$include_personal_data = Input::bool( $payload, 'include_personal_data', false );
		$snapshot              = $this->order_snapshot( $order, $include_personal_data );
		return array(
			'order'       => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Handle the tax class list connector action.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function tax_class_list(): array {
		$this->assert_woo();
		if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', 'get_tax_rate_classes' ) ) {
			throw new RuntimeException( 'WooCommerce tax class API is unavailable.' );
		}
		$classes = array();
		foreach ( \WC_Tax::get_tax_rate_classes() as $class ) {
			if ( is_object( $class ) ) {
				$classes[] = array(
					'id'   => isset( $class->tax_rate_class_id ) ? (int) $class->tax_rate_class_id : 0,
					'name' => isset( $class->name ) ? (string) $class->name : '',
					'slug' => isset( $class->slug ) ? (string) $class->slug : '',
				);
			}
		}
		return array(
			'classes'        => $classes,
			'standard_class' => '',
		);
	}

	/**
	 * Handle the product list connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function product_list( array $payload ): array {
		$this->assert_woo();
		$limit = isset( $payload['per_page'] ) ? max( 1, min( 100, (int) $payload['per_page'] ) ) : 50;
		$page  = isset( $payload['page'] ) ? max( 1, (int) $payload['page'] ) : 1;
		$args  = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'status'   => isset( $payload['status'] ) ? $payload['status'] : ( Policy::publicRepositoryContext() ? 'publish' : array( 'publish', 'draft', 'pending', 'private' ) ),
		);
		if ( isset( $payload['type'] ) ) {
			$args['type'] = sanitize_key( (string) $payload['type'] );
		}
		if ( isset( $payload['sku'] ) ) {
			$args['sku'] = (string) $payload['sku'];
		}
		if ( isset( $payload['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $payload['search'] );
		}
		if ( isset( $payload['category'] ) ) {
			$args['category'] = array_map( 'intval', (array) $payload['category'] );
		}

		$query    = wc_get_products( $args );
		$products = is_object( $query ) && isset( $query->products ) ? $query->products : (array) $query;
		$items    = array();
		foreach ( $products as $product ) {
			if ( $product instanceof \WC_Product ) {
				$items[] = $this->product_snapshot( $product );
			}
		}
		return array(
			'products' => $items,
			'page'     => $page,
			'per_page' => $limit,
			'total'    => is_object( $query ) && isset( $query->total ) ? (int) $query->total : count( $items ),
			'pages'    => is_object( $query ) && isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 1,
		);
	}

	/**
	 * Handle the product get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function product_get( array $payload ): array {
		$product = $this->product( $payload );
		$post    = get_post( $product->get_id() );
		if ( $post instanceof \WP_Post ) {
			Policy::assertPostReadable( $post );
		}
		$snapshot = $this->product_snapshot( $product );
		return array(
			'product'     => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Handle the product create connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function product_create( array $payload, array $context ): array {
		$this->assert_woo();
		$type    = isset( $payload['type'] ) ? sanitize_key( (string) $payload['type'] ) : 'simple';
		$product = $this->new_product( $type );
		$this->apply_product( $product, $payload );

		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => $this->product_snapshot( $product ),
				'_current_fingerprint' => Fingerprint::make(
					array(
						'new'  => true,
						'type' => $type,
					)
				),
			);
		}

		$id = $product->save();
		if ( ! $id ) {
			throw new RuntimeException( 'WooCommerce product save failed.' );
		}
		$saved = wc_get_product( $id );
		return array(
			'product'   => $this->product_snapshot( $saved ),
			'_rollback' => array(
				'action'  => 'post.trash',
				'payload' => array( 'id' => (int) $id ),
			),
		);
	}

	/**
	 * Handle the product update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 */
	public function product_update( array $payload, array $context ): array {
		$product = $this->product( $payload );
		$before  = $this->product_snapshot( $product );
		$working = clone $product;
		$this->apply_product( $working, $payload );
		$after  = $this->product_snapshot( $working );
		$result = array(
			'before'               => $before,
			'after'                => $after,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}

		$this->apply_product( $product, $payload );
		$product->save();
		$result['after']     = $this->product_snapshot( wc_get_product( $product->get_id() ) );
		$result['_rollback'] = array(
			'action'  => 'woocommerce.product.update',
			'payload' => $this->product_rollback_payload( $before ),
		);
		return $result;
	}

	/**
	 * Handle the variation list connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function variation_list( array $payload ): array {
		$this->assert_woo();
		try {
			$parent_id = Input::positive_id( $payload['product_id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Variable product not found.' );
		}
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable ) {
			throw new RuntimeException( 'Variable product not found.' );
		}
		$items = array();
		foreach ( $parent->get_children() as $id ) {
			$variation = wc_get_product( (int) $id );
			if ( $variation instanceof \WC_Product_Variation ) {
				$items[] = $this->variation_snapshot( $variation );
			}
		}
		return array( 'variations' => $items );
	}

	/**
	 * Handle the variation get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function variation_get( array $payload ): array {
		$variation = $this->variation( $payload );
		$snapshot  = $this->variation_snapshot( $variation );
		return array(
			'variation'   => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Handle the variation create connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function variation_create( array $payload, array $context ): array {
		$this->assert_woo();
		try {
			$parent_id = Input::positive_id( $payload['product_id'] ?? null );
		} catch ( RuntimeException $error ) {
			throw new RuntimeException( 'Variable parent product not found.' );
		}
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable ) {
			throw new RuntimeException( 'Variable parent product not found.' );
		}
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$this->apply_variation( $variation, $payload );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => $this->variation_snapshot( $variation ),
				'_current_fingerprint' => Fingerprint::make(
					array(
						'new'       => true,
						'parent_id' => $parent_id,
					)
				),
			);
		}
		$id = $variation->save();
		if ( ! $id ) {
			throw new RuntimeException( 'Variation save failed.' );
		}
		$saved_variation = wc_get_product( $id );
		if ( ! $saved_variation instanceof \WC_Product_Variation ) {
			throw new RuntimeException( 'Saved WooCommerce variation could not be read back.' );
		}
		return array(
			'variation' => $this->variation_snapshot( $saved_variation ),
			'_rollback' => array(
				'action'  => 'post.trash',
				'payload' => array( 'id' => (int) $id ),
			),
		);
	}

	/**
	 * Handle the variation update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function variation_update( array $payload, array $context ): array {
		$variation = $this->variation( $payload );
		$before    = $this->variation_snapshot( $variation );
		$working   = clone $variation;
		$this->apply_variation( $working, $payload );
		$result = array(
			'before'               => $before,
			'after'                => $this->variation_snapshot( $working ),
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$this->apply_variation( $variation, $payload );
		$variation->save();
		$updated_variation = wc_get_product( $variation->get_id() );
		if ( ! $updated_variation instanceof \WC_Product_Variation ) {
			throw new RuntimeException( 'Updated WooCommerce variation could not be read back.' );
		}
		$result['after']     = $this->variation_snapshot( $updated_variation );
		$result['_rollback'] = array(
			'action'  => 'woocommerce.variation.update',
			'payload' => $this->variation_rollback_payload( $before ),
		);
		return $result;
	}

	/**
	 * Handle the attribute list connector action.
	 *
	 * @return array Result of this operation.
	 */
	public function attribute_list(): array {
		$this->assert_woo();
		$items = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$items[] = $this->attribute_snapshot( $attribute );
		}
		return array( 'attributes' => $items );
	}

	/**
	 * Handle the attribute create connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function attribute_create( array $payload, array $context ): array {
		$this->assert_woo();
		$args = $this->attribute_args( $payload, false );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => $args,
				'_current_fingerprint' => Fingerprint::make(
					array(
						'new'  => true,
						'slug' => $args['slug'],
					)
				),
			);
		}
		$id = wc_create_attribute( $args );
		if ( is_wp_error( $id ) || ! is_int( $id ) || $id <= 0 ) {
			throw new RuntimeException( 'WooCommerce attribute creation failed.' );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		return array(
			'attribute_id' => (int) $id,
			'_rollback'    => array(
				'action'  => 'woocommerce.attribute.delete',
				'payload' => array( 'id' => (int) $id ),
			),
		);
	}

	/**
	 * Handle the attribute update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function attribute_update( array $payload, array $context ): array {
		$this->assert_woo();
		$id         = Input::positive_id( $payload['id'] ?? null );
		$before_obj = $this->find_attribute( $id );
		$before     = $this->attribute_snapshot( $before_obj );
		$args       = $this->attribute_args( $payload, true );
		$result     = array(
			'before'               => $before,
			'changes'              => $args,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$updated = wc_update_attribute( $id, $args );
		if ( is_wp_error( $updated ) || $id !== $updated ) {
			throw new RuntimeException( 'WooCommerce attribute update failed.' );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		$result['after']     = $this->attribute_snapshot( $this->find_attribute( $id ) );
		$result['_rollback'] = array(
			'action'  => 'woocommerce.attribute.update',
			'payload' => array_merge( array( 'id' => $id ), $before ),
		);
		return $result;
	}

	/**
	 * Handle the attribute delete connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function attribute_delete( array $payload, array $context ): array {
		$this->assert_woo();
		$id     = Input::positive_id( $payload['id'] ?? null );
		$before = $this->attribute_snapshot( $this->find_attribute( $id ) );
		$result = array(
			'before'               => $before,
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$deleted = wc_delete_attribute( $id );
		if ( is_wp_error( $deleted ) || true !== $deleted ) {
			throw new RuntimeException( 'WooCommerce attribute deletion failed.' );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		$result['_rollback'] = array(
			'action'  => 'woocommerce.attribute.create',
			'payload' => $before,
		);
		return $result;
	}

	/**
	 * Handle the coupon list connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 */
	public function coupon_list( array $payload ): array {
		$this->assert_woo();
		$query = new \WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'posts_per_page' => isset( $payload['per_page'] ) ? max( 1, min( 100, (int) $payload['per_page'] ) ) : 50,
				'paged'          => isset( $payload['page'] ) ? max( 1, (int) $payload['page'] ) : 1,
				's'              => isset( $payload['search'] ) ? sanitize_text_field( (string) $payload['search'] ) : '',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$coupon  = new \WC_Coupon( (int) $post->ID );
			$items[] = $this->coupon_snapshot( $coupon );
		}
		return array(
			'coupons' => $items,
			'total'   => (int) $query->found_posts,
			'pages'   => (int) $query->max_num_pages,
		);
	}

	/**
	 * Handle the coupon get connector action.
	 *
	 * @param array $payload Request parameters.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function coupon_get( array $payload ): array {
		$this->assert_woo();
		$coupon = new \WC_Coupon( $payload['id'] ?? ( $payload['code'] ?? 0 ) );
		if ( ! $coupon->get_id() ) {
			throw new RuntimeException( 'Coupon not found.' );
		}
		$snapshot = $this->coupon_snapshot( $coupon );
		return array(
			'coupon'      => $snapshot,
			'fingerprint' => Fingerprint::make( $snapshot ),
		);
	}

	/**
	 * Handle the coupon create connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 */
	public function coupon_create( array $payload, array $context ): array {
		$this->assert_woo();
		$coupon = new \WC_Coupon();
		$this->apply_coupon( $coupon, $payload );
		if ( ! empty( $context['dry_run'] ) ) {
			return array(
				'would_create'         => $this->coupon_snapshot( $coupon ),
				'_current_fingerprint' => Fingerprint::make( array( 'new' => true ) ),
			);
		}
		$id = $coupon->save();
		return array(
			'coupon'    => $this->coupon_snapshot( new \WC_Coupon( $id ) ),
			'_rollback' => array(
				'action'  => 'post.trash',
				'payload' => array( 'id' => (int) $id ),
			),
		);
	}

	/**
	 * Handle the coupon update connector action.
	 *
	 * @param array $payload Request parameters.
	 * @param array $context Execution context, including dry-run state.
	 *
	 * @return array Result of this operation.
	 *
	 * @throws \RuntimeException When validation or the requested operation fails.
	 */
	public function coupon_update( array $payload, array $context ): array {
		$this->assert_woo();
		$coupon = new \WC_Coupon( $payload['id'] ?? ( $payload['code'] ?? 0 ) );
		if ( ! $coupon->get_id() ) {
			throw new RuntimeException( 'Coupon not found.' );
		}
		$before  = $this->coupon_snapshot( $coupon );
		$working = clone $coupon;
		$this->apply_coupon( $working, $payload );
		$result = array(
			'before'               => $before,
			'after'                => $this->coupon_snapshot( $working ),
			'_current_fingerprint' => Fingerprint::make( $before ),
		);
		if ( ! empty( $context['dry_run'] ) ) {
			return $result;
		}
		$this->apply_coupon( $coupon, $payload );
		$coupon->save();
		$result['after']     = $this->coupon_snapshot( new \WC_Coupon( $coupon->get_id() ) );
		$result['_rollback'] = array(
			'action'  => 'woocommerce.coupon.update',
			'payload' => array_merge( array( 'id' => $coupon->get_id() ), $before ),
		);
		return $result;
	}
}
