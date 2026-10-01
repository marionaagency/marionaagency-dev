<?php
/**
 * WooCommerce: catálogo y pedidos.
 *
 * Los pedidos son de solo lectura a propósito. Tocar el estado de un pedido
 * dispara correos al cliente y movimientos de stock: eso se hace desde el
 * escritorio de la tienda, no desde una API automatizada.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_Woo extends MAD_Controller {

	public function register_routes() {

		$this->add(
			'/woo/products',
			'GET',
			array( $this, 'products' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'search'   => array( 'type' => 'string' ),
					'status'   => array( 'type' => 'string', 'default' => 'any' ),
					'category' => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'stock'    => array(
						'type'        => 'string',
						'description' => 'instock, outofstock, onbackorder.',
					),
				),
			)
		);

		$this->add(
			'/woo/products/(?P<id>\d+)',
			'GET',
			array( $this, 'product' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/woo/products/(?P<id>\d+)',
			'PATCH, PUT',
			array( $this, 'update_product' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'regular_price' => array( 'type' => 'string' ),
					'sale_price'    => array( 'type' => 'string' ),
					'stock_quantity'=> array( 'type' => 'integer' ),
					'stock_status'  => array( 'type' => 'string' ),
					'sku'           => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'short_description' => array( 'type' => 'string' ),
					'status'        => array( 'type' => 'string' ),
				),
			)
		);

		$this->add(
			'/woo/orders',
			'GET',
			array( $this, 'orders' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'status'   => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'after'    => array( 'type' => 'string', 'description' => 'Fecha ISO.' ),
				),
			)
		);

		$this->add( '/woo/report', 'GET', array( $this, 'report' ), array( 'scope' => 'read' ) );
	}

	private function guard() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->error( 'mad_no_woo', 'WooCommerce no está activo en esta web.', 501 );
		}
		return true;
	}

	public function products( $request ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$args = array(
			'status'   => $request->get_param( 'status' ),
			's'        => $request->get_param( 'search' ),
			'limit'    => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'page'     => max( 1, (int) $request->get_param( 'page' ) ),
			'paginate' => true,
		);

		if ( $request->get_param( 'category' ) ) {
			$args['category'] = array( $request->get_param( 'category' ) );
		}
		if ( $request->get_param( 'stock' ) ) {
			$args['stock_status'] = $request->get_param( 'stock' );
		}

		$results = wc_get_products( $args );
		$items   = array();

		foreach ( $results->products as $product ) {
			$items[] = $this->shape_product( $product, false );
		}

		return $this->ok(
			array(
				'items' => $items,
				'total' => (int) $results->total,
				'pages' => (int) $results->max_num_pages,
			)
		);
	}

	public function product( $request ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			return $this->error( 'mad_not_found', 'No existe ese producto.', 404 );
		}

		return $this->ok( $this->shape_product( $product, true ) );
	}

	public function update_product( $request ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id      = (int) $request['id'];
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return $this->error( 'mad_not_found', 'No existe ese producto.', 404 );
		}

		$setters = array(
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'stock_quantity'    => 'set_stock_quantity',
			'stock_status'      => 'set_stock_status',
			'sku'               => 'set_sku',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
			'status'            => 'set_status',
		);

		$changes = array();
		foreach ( $setters as $param => $method ) {
			$value = $request->get_param( $param );
			if ( null === $value ) {
				continue;
			}
			$getter = str_replace( 'set_', 'get_', $method );
			$changes[ $param ] = array(
				'from' => $product->$getter(),
				'to'   => $value,
			);
		}

		if ( empty( $changes ) ) {
			return $this->error( 'mad_nothing_to_do', 'No se ha indicado ningún campo a cambiar.', 422 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se cambiarían %d campo(s) del producto #%d «%s».', count( $changes ), $id, $product->get_name() ),
				array( 'changes' => $changes )
			);
		}

		$backup = MAD_Safety::snapshot_post( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		foreach ( $setters as $param => $method ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				$product->$method( $value );
			}
		}

		// Si tocamos stock, hay que asegurarse de que la gestión esté activa.
		if ( null !== $request->get_param( 'stock_quantity' ) ) {
			$product->set_manage_stock( true );
		}

		$product->save();

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Producto #%d «%s» actualizado (%s).', $id, $product->get_name(), implode( ', ', array_keys( $changes ) ) ),
				'changes'    => $changes,
				'backup_ref' => $backup,
				'product'    => $this->shape_product( wc_get_product( $id ), false ),
			)
		);
	}

	public function orders( $request ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$args = array(
			'limit'    => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'page'     => max( 1, (int) $request->get_param( 'page' ) ),
			'paginate' => true,
		);

		if ( $request->get_param( 'status' ) ) {
			$args['status'] = $request->get_param( 'status' );
		}
		if ( $request->get_param( 'after' ) ) {
			$args['date_created'] = '>' . $request->get_param( 'after' );
		}

		$results = wc_get_orders( $args );
		$items   = array();

		foreach ( $results->orders as $order ) {
			$items[] = array(
				'id'       => $order->get_id(),
				'number'   => $order->get_order_number(),
				'status'   => $order->get_status(),
				'total'    => $order->get_total(),
				'currency' => $order->get_currency(),
				'created'  => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
				'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'    => $order->get_billing_email(),
				'items'    => count( $order->get_items() ),
			);
		}

		return $this->ok(
			array(
				'items' => $items,
				'total' => (int) $results->total,
				'pages' => (int) $results->max_num_pages,
			)
		);
	}

	/**
	 * Resumen rápido de la tienda: lo que se suele preguntar de un vistazo.
	 */
	public function report() {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$out_of_stock = wc_get_products(
			array(
				'stock_status' => 'outofstock',
				'limit'        => 50,
				'status'       => 'publish',
			)
		);

		$low_stock = wc_get_products(
			array(
				'limit'          => 50,
				'status'         => 'publish',
				'stock_quantity' => array( 1, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) ),
			)
		);

		$counts = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$counts[ $label ] = (int) wc_orders_count( str_replace( 'wc-', '', $slug ) );
		}

		return $this->ok(
			array(
				'currency'       => get_woocommerce_currency(),
				'products_total' => (int) wp_count_posts( 'product' )->publish,
				'out_of_stock'   => array_map( fn( $p ) => array( 'id' => $p->get_id(), 'name' => $p->get_name() ), $out_of_stock ),
				'low_stock'      => array_map( fn( $p ) => array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'qty' => $p->get_stock_quantity() ), $low_stock ),
				'orders_by_status' => $counts,
			)
		);
	}

	private function shape_product( $product, $full ) {
		$data = array(
			'id'          => $product->get_id(),
			'name'        => $product->get_name(),
			'type'        => $product->get_type(),
			'status'      => $product->get_status(),
			'sku'         => $product->get_sku(),
			'price'       => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'  => $product->get_sale_price(),
			'stock_status'=> $product->get_stock_status(),
			'stock_qty'   => $product->get_stock_quantity(),
			'url'         => $product->get_permalink(),
		);

		if ( $full ) {
			$data['description']       = $product->get_description();
			$data['short_description'] = $product->get_short_description();
			$data['categories']        = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
			$data['tags']              = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
			$data['images']            = array_map( 'wp_get_attachment_url', array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
			$data['attributes']        = array_keys( $product->get_attributes() );

			if ( $product->is_type( 'variable' ) ) {
				$data['variations'] = array_map(
					static function ( $variation_id ) {
						$variation = wc_get_product( $variation_id );
						return $variation ? array(
							'id'    => $variation_id,
							'name'  => $variation->get_name(),
							'price' => $variation->get_price(),
							'stock' => $variation->get_stock_quantity(),
						) : null;
					},
					$product->get_children()
				);
			}
		}

		return $data;
	}
}
