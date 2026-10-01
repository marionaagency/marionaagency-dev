<?php
/**
 * Advanced Custom Fields.
 *
 * Todas las rutas comprueban primero que ACF esté activo y devuelven un 501
 * claro si no lo está, en vez de un error críptico.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_ACF extends MAD_Controller {

	public function register_routes() {

		$this->add( '/acf/groups', 'GET', array( $this, 'groups' ), array( 'scope' => 'read' ) );

		$this->add(
			'/acf/groups/(?P<key>[A-Za-z0-9_]+)/fields',
			'GET',
			array( $this, 'group_fields' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/acf/values',
			'GET',
			array( $this, 'get_values' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'post_id' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'ID numérico, o «option», «term_123», «user_5».',
					),
				),
			)
		);

		$this->add(
			'/acf/values',
			'POST',
			array( $this, 'set_values' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'post_id' => array( 'type' => 'string', 'required' => true ),
					'fields'  => array( 'type' => 'object', 'required' => true ),
				),
			)
		);
	}

	private function guard() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return $this->error( 'mad_no_acf', 'ACF no está activo en esta web.', 501 );
		}
		return true;
	}

	public function groups() {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$items = array();
		foreach ( acf_get_field_groups() as $group ) {
			$items[] = array(
				'key'      => $group['key'],
				'title'    => $group['title'],
				'active'   => ! empty( $group['active'] ),
				'location' => $group['location'] ?? array(),
				'fields'   => count( (array) acf_get_fields( $group['key'] ) ),
			);
		}

		return $this->ok( array( 'groups' => $items ) );
	}

	public function group_fields( $request ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$fields = acf_get_fields( (string) $request['key'] );
		if ( false === $fields ) {
			return $this->error( 'mad_no_group', 'No existe ese grupo de campos.', 404 );
		}

		return $this->ok( array( 'fields' => $this->shape_fields( $fields ) ) );
	}

	public function get_values( $request ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$target = $this->normalise_target( $request->get_param( 'post_id' ) );
		$values = get_fields( $target );

		return $this->ok(
			array(
				'target' => $target,
				'values' => is_array( $values ) ? $values : array(),
			)
		);
	}

	public function set_values( $request ) {
		$guard = $this->guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$target = $this->normalise_target( $request->get_param( 'post_id' ) );
		$fields = (array) $request->get_param( 'fields' );

		if ( empty( $fields ) ) {
			return $this->error( 'mad_no_fields', 'No se ha indicado ningún campo.', 422 );
		}

		$before = array();
		foreach ( array_keys( $fields ) as $name ) {
			$before[ $name ] = get_field( $name, $target );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se escribirían %d campo(s) ACF en %s.', count( $fields ), $target ),
				array( 'from' => $before, 'to' => $fields )
			);
		}

		// Solo hacemos snapshot si el destino es un post real.
		$backup = is_numeric( $target ) ? MAD_Safety::snapshot_post( (int) $target ) : null;

		$written = array();
		foreach ( $fields as $name => $value ) {
			if ( update_field( $name, $value, $target ) ) {
				$written[] = $name;
			}
		}

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( '%d de %d campo(s) ACF escritos en %s.', count( $written ), count( $fields ), $target ),
				'written'    => $written,
				'previous'   => $before,
				'backup_ref' => is_wp_error( $backup ) ? null : $backup,
			)
		);
	}

	// --------------------------------------------------------------- helpers

	private function shape_fields( $fields ) {
		$out = array();

		foreach ( (array) $fields as $field ) {
			$item = array(
				'key'      => $field['key'],
				'name'     => $field['name'],
				'label'    => $field['label'],
				'type'     => $field['type'],
				'required' => ! empty( $field['required'] ),
			);

			// Repetidores y grupos llevan subcampos dentro.
			if ( ! empty( $field['sub_fields'] ) ) {
				$item['sub_fields'] = $this->shape_fields( $field['sub_fields'] );
			}
			if ( ! empty( $field['layouts'] ) ) {
				$item['layouts'] = array_map(
					function ( $layout ) {
						return array(
							'name'       => $layout['name'],
							'label'      => $layout['label'],
							'sub_fields' => $this->shape_fields( $layout['sub_fields'] ?? array() ),
						);
					},
					array_values( $field['layouts'] )
				);
			}
			if ( ! empty( $field['choices'] ) ) {
				$item['choices'] = $field['choices'];
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * ACF acepta destinos que no son un post: «option», «term_12», «user_3».
	 */
	private function normalise_target( $raw ) {
		$raw = (string) $raw;
		return is_numeric( $raw ) ? (int) $raw : $raw;
	}
}
