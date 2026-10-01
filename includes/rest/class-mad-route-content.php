<?php
/**
 * Contenido: posts, páginas, cualquier CPT, términos y menús.
 *
 * Todo lo que muta pasa por snapshot previo y admite dry_run.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_Content extends MAD_Controller {

	public function register_routes() {

		$this->add(
			'/posts',
			'GET',
			array( $this, 'list_posts' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'type'     => array( 'type' => 'string', 'default' => 'post' ),
					'status'   => array( 'type' => 'string', 'default' => 'any' ),
					'search'   => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'default' => 20 ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
					'orderby'  => array( 'type' => 'string', 'default' => 'date' ),
					'order'    => array( 'type' => 'string', 'default' => 'DESC' ),
					'parent'   => array( 'type' => 'integer' ),
				),
			)
		);

		$this->add(
			'/posts/(?P<id>\d+)',
			'GET',
			array( $this, 'get_post_item' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/posts',
			'POST',
			array( $this, 'create_post' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => $this->post_args(),
			)
		);

		$this->add(
			'/posts/(?P<id>\d+)',
			'PATCH, PUT',
			array( $this, 'update_post_item' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => $this->post_args(),
			)
		);

		$this->add(
			'/posts/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_post_item' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'true borra definitivamente; false lo manda a la papelera.',
					),
				),
			)
		);

		$this->add(
			'/posts/(?P<id>\d+)/meta',
			'GET',
			array( $this, 'get_meta' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/posts/(?P<id>\d+)/meta',
			'POST',
			array( $this, 'set_meta' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'meta' => array( 'type' => 'object', 'required' => true ),
				),
			)
		);

		$this->add(
			'/posts/(?P<id>\d+)/revisions',
			'GET',
			array( $this, 'revisions' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/revisions/(?P<id>\d+)/restore',
			'POST',
			array( $this, 'restore_revision' ),
			array( 'scope' => 'content', 'mutating' => true )
		);

		// ------------------------------------------------------- taxonomías

		$this->add(
			'/terms',
			'GET',
			array( $this, 'list_terms' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'taxonomy' => array( 'type' => 'string', 'default' => 'category' ),
					'search'   => array( 'type' => 'string' ),
					'per_page' => array( 'type' => 'integer', 'default' => 100 ),
				),
			)
		);

		$this->add(
			'/terms',
			'POST',
			array( $this, 'create_term' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'taxonomy'    => array( 'type' => 'string', 'required' => true ),
					'name'        => array( 'type' => 'string', 'required' => true ),
					'slug'        => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
					'description' => array( 'type' => 'string' ),
				),
			)
		);

		// ------------------------------------------------------------ menús

		$this->add( '/menus', 'GET', array( $this, 'list_menus' ), array( 'scope' => 'read' ) );

		$this->add(
			'/menus/(?P<id>\d+)',
			'GET',
			array( $this, 'get_menu' ),
			array( 'scope' => 'read' )
		);

		// ---------------------------------------------------------- opciones

		$this->add(
			'/options/(?P<key>[A-Za-z0-9_\-]+)',
			'GET',
			array( $this, 'get_option_item' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/options/(?P<key>[A-Za-z0-9_\-]+)',
			'POST',
			array( $this, 'set_option_item' ),
			array(
				'scope'    => 'admin',
				'mutating' => true,
				'args'     => array(
					'value' => array( 'required' => true ),
				),
			)
		);
	}

	private function post_args() {
		return array(
			'type'        => array( 'type' => 'string', 'default' => 'post' ),
			'title'       => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string' ),
			'excerpt'     => array( 'type' => 'string' ),
			'status'      => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'parent'      => array( 'type' => 'integer' ),
			'menu_order'  => array( 'type' => 'integer' ),
			'template'    => array( 'type' => 'string' ),
			'author'      => array( 'type' => 'integer' ),
			'date'        => array( 'type' => 'string' ),
			'meta'        => array( 'type' => 'object' ),
			'terms'       => array( 'type' => 'object' ),
			'featured_image' => array( 'type' => 'integer' ),
			'raw'         => self::raw_arg(),
		);
	}

	// --------------------------------------------------------------- lectura

	public function list_posts( $request ) {
		$query = new WP_Query(
			array(
				'post_type'      => $request->get_param( 'type' ),
				'post_status'    => $request->get_param( 'status' ),
				's'              => $request->get_param( 'search' ),
				'posts_per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
				'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
				'orderby'        => $request->get_param( 'orderby' ),
				'order'          => $request->get_param( 'order' ),
				'post_parent'    => $request->get_param( 'parent' ),
				'no_found_rows'  => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->shape_post( $post, false );
		}

		return $this->ok(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			)
		);
	}

	public function get_post_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}

		$data          = $this->shape_post( $post, true );
		$data['meta']  = $this->readable_meta( $post->ID );
		$data['terms'] = $this->post_terms( $post );
		$data['featured_image'] = get_post_thumbnail_id( $post->ID ) ?: null;

		return $this->ok( $data );
	}

	public function get_meta( $request ) {
		$id = (int) $request['id'];
		if ( ! get_post( $id ) ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}
		return $this->ok( array( 'meta' => $this->readable_meta( $id ) ) );
	}

	public function revisions( $request ) {
		$id        = (int) $request['id'];
		$revisions = wp_get_post_revisions( $id, array( 'posts_per_page' => 30 ) );

		$items = array();
		foreach ( $revisions as $rev ) {
			$items[] = array(
				'id'       => (int) $rev->ID,
				'author'   => (int) $rev->post_author,
				'date'     => $rev->post_modified_gmt,
				'title'    => $rev->post_title,
				'excerpt'  => wp_trim_words( wp_strip_all_tags( $rev->post_content ), 25 ),
			);
		}

		return $this->ok( array( 'revisions' => $items ) );
	}

	// -------------------------------------------------------------- escritura

	public function create_post( $request ) {
		$args = $this->build_postarr( $request );

		if ( empty( $args['post_title'] ) && empty( $args['post_content'] ) ) {
			return $this->error( 'mad_empty_post', 'Hace falta al menos título o contenido.', 422 );
		}

		$raw = $this->raw_requested( $request );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se crearía un «%s» titulado «%s».', $args['post_type'], $args['post_title'] ?? '(sin título)' ),
				array( 'would_create' => $args ) + $this->kses_notice( $args, $raw )
			);
		}

		$id = MAD_Post_Writer::insert( $args, $raw );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->apply_meta_and_terms( $id, $request );

		return $this->ok(
			array(
				'applied' => true,
				'summary' => sprintf( 'Creado %s #%d «%s».', $args['post_type'], $id, get_the_title( $id ) ),
				'post'    => $this->shape_post( $id, false ),
			),
			201
		);
	}

	public function update_post_item( $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}

		$args       = $this->build_postarr( $request, $post );
		$args['ID'] = $id;

		$raw = $this->raw_requested( $request );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se actualizaría #%d «%s».', $id, $post->post_title ),
				array( 'would_change' => $this->diff( $post, $args ) ) + $this->kses_notice( $args, $raw )
			);
		}

		// Nada se toca sin copia previa.
		$backup = MAD_Safety::snapshot_post( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$result = MAD_Post_Writer::update( $args, $raw );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->apply_meta_and_terms( $id, $request );

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Actualizado #%d «%s».', $id, get_the_title( $id ) ),
				'backup_ref' => $backup,
				'post'       => $this->shape_post( $id, false ),
			)
		);
	}

	public function delete_post_item( $request ) {
		$id    = (int) $request['id'];
		$force = (bool) $request->get_param( 'force' );
		$post  = get_post( $id );

		if ( ! $post ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf(
					'Se %s #%d «%s».',
					$force ? 'borraría definitivamente' : 'mandaría a la papelera',
					$id,
					$post->post_title
				)
			);
		}

		$backup = MAD_Safety::snapshot_post( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$result = wp_delete_post( $id, $force );
		if ( ! $result ) {
			return $this->error( 'mad_delete_failed', 'WordPress rechazó el borrado.', 500 );
		}

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( '%s #%d «%s».', $force ? 'Borrado' : 'Enviado a papelera', $id, $post->post_title ),
				'backup_ref' => $backup,
			)
		);
	}

	public function set_meta( $request ) {
		$id   = (int) $request['id'];
		$meta = (array) $request->get_param( 'meta' );

		if ( ! get_post( $id ) ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se escribirían %d claves de meta en #%d.', count( $meta ), $id ),
				array( 'would_set' => array_keys( $meta ) )
			);
		}

		$backup = MAD_Safety::snapshot_post( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		foreach ( $meta as $key => $value ) {
			if ( $this->is_protected_meta( $key ) ) {
				continue;
			}
			if ( null === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Escritas %d claves de meta en #%d.', count( $meta ), $id ),
				'backup_ref' => $backup,
				'meta'       => $this->readable_meta( $id ),
			)
		);
	}

	public function restore_revision( $request ) {
		$revision_id = (int) $request['id'];
		$revision    = wp_get_post_revision( $revision_id );

		if ( ! $revision ) {
			return $this->error( 'mad_not_found', 'No existe esa revisión.', 404 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se restauraría la revisión #%d sobre el post #%d.', $revision_id, $revision->post_parent ) );
		}

		$backup = MAD_Safety::snapshot_post( $revision->post_parent );
		$result = wp_restore_post_revision( $revision_id );

		if ( ! $result ) {
			return $this->error( 'mad_restore_failed', 'No se pudo restaurar la revisión.', 500 );
		}

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Restaurada la revisión #%d.', $revision_id ),
				'backup_ref' => is_wp_error( $backup ) ? null : $backup,
			)
		);
	}

	// ------------------------------------------------------------ taxonomías

	public function list_terms( $request ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $request->get_param( 'taxonomy' ),
				'search'     => $request->get_param( 'search' ),
				'number'     => (int) $request->get_param( 'per_page' ),
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
				'description' => $term->description,
			);
		}

		return $this->ok( array( 'items' => $items ) );
	}

	public function create_term( $request ) {
		$taxonomy = $request->get_param( 'taxonomy' );
		$name     = $request->get_param( 'name' );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error( 'mad_bad_taxonomy', sprintf( 'La taxonomía «%s» no existe.', $taxonomy ), 422 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se crearía el término «%s» en %s.', $name, $taxonomy ) );
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'        => $request->get_param( 'slug' ),
				'parent'      => (int) $request->get_param( 'parent' ),
				'description' => (string) $request->get_param( 'description' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'applied' => true,
				'summary' => sprintf( 'Creado el término «%s» (#%d).', $name, $result['term_id'] ),
				'term_id' => (int) $result['term_id'],
			),
			201
		);
	}

	// ----------------------------------------------------------------- menús

	public function list_menus() {
		$menus     = wp_get_nav_menus();
		$locations = get_nav_menu_locations();

		$items = array();
		foreach ( $menus as $menu ) {
			$items[] = array(
				'id'        => (int) $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'count'     => (int) $menu->count,
				'locations' => array_keys( array_filter( $locations, static fn( $id ) => (int) $id === (int) $menu->term_id ) ),
			);
		}

		return $this->ok( array( 'items' => $items, 'registered_locations' => get_registered_nav_menus() ) );
	}

	public function get_menu( $request ) {
		$items = wp_get_nav_menu_items( (int) $request['id'] );
		if ( false === $items ) {
			return $this->error( 'mad_not_found', 'No existe ese menú.', 404 );
		}

		$out = array();
		foreach ( $items as $item ) {
			$out[] = array(
				'id'     => (int) $item->ID,
				'title'  => $item->title,
				'url'    => $item->url,
				'parent' => (int) $item->menu_item_parent,
				'order'  => (int) $item->menu_order,
				'type'   => $item->type,
				'object' => $item->object,
				'object_id' => (int) $item->object_id,
			);
		}

		return $this->ok( array( 'items' => $out ) );
	}

	// -------------------------------------------------------------- opciones

	public function get_option_item( $request ) {
		$key = (string) $request['key'];

		if ( $this->is_secret_option( $key ) ) {
			return $this->error( 'mad_option_protected', 'Esa opción contiene credenciales y no se expone.', 403 );
		}

		return $this->ok( array( 'key' => $key, 'value' => get_option( $key ) ) );
	}

	public function set_option_item( $request ) {
		$key   = (string) $request['key'];
		$value = $request->get_param( 'value' );

		if ( $this->is_secret_option( $key ) || 0 === strpos( $key, 'mad_' ) ) {
			return $this->error( 'mad_option_protected', 'Esa opción no se puede escribir por esta vía.', 403 );
		}

		$previous = get_option( $key );

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se cambiaría la opción «%s».', $key ),
				array( 'from' => $previous, 'to' => $value )
			);
		}

		update_option( $key, $value );

		return $this->ok(
			array(
				'applied'  => true,
				'summary'  => sprintf( 'Opción «%s» actualizada.', $key ),
				'previous' => $previous,
				'value'    => $value,
			)
		);
	}

	// --------------------------------------------------------------- helpers

	private function build_postarr( $request, $existing = null ) {
		$map = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'status'     => 'post_status',
			'slug'       => 'post_name',
			'parent'     => 'post_parent',
			'menu_order' => 'menu_order',
			'author'     => 'post_author',
			'date'       => 'post_date',
			'type'       => 'post_type',
		);

		$args = array();
		foreach ( $map as $param => $field ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				$args[ $field ] = $value;
			}
		}

		// El parámetro «type» tiene default 'post', así que get_param() nunca
		// viene vacío: si nos fiáramos de él, una edición que solo cambia el
		// contenido convertiría una página (o una plantilla del Theme Builder)
		// en una entrada. Ha pasado. Solo lo aplicamos si el cliente lo mandó.
		if ( $existing && ! self::param_sent( $request, 'type' ) ) {
			$args['post_type'] = $existing->post_type;
		}
		if ( empty( $args['post_type'] ) ) {
			$args['post_type'] = $existing ? $existing->post_type : 'post';
		}
		if ( ! $existing && empty( $args['post_status'] ) ) {
			$args['post_status'] = 'draft';
		}

		$template = $request->get_param( 'template' );
		if ( null !== $template ) {
			$args['page_template'] = $template;
		}

		return $args;
	}

	/**
	 * En simulación, avisa de lo que KSES eliminaría si no se pide raw.
	 */
	private function kses_notice( $args, $raw ) {
		if ( $raw || ! isset( $args['post_content'] ) || ! MAD_Post_Writer::kses_active() ) {
			return array();
		}
		$risk = MAD_Post_Writer::risk_summary( $args['post_content'] );
		return $risk
			? array( 'warning' => 'Sin raw=true esta escritura se rechazaría (409): lleva ' . implode( ', ', $risk ) . ', que WordPress elimina al guardar.' )
			: array();
	}

	/**
	 * ¿Vino este parámetro de verdad en la petición, o es solo el valor por
	 * defecto que ha puesto el propio WordPress?
	 */
	private static function param_sent( $request, $name ) {
		foreach ( array( $request->get_json_params(), $request->get_body_params(), $request->get_query_params() ) as $source ) {
			if ( is_array( $source ) && array_key_exists( $name, $source ) ) {
				return true;
			}
		}
		return false;
	}

	private function apply_meta_and_terms( $post_id, $request ) {
		$meta = $request->get_param( 'meta' );
		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				if ( $this->is_protected_meta( $key ) ) {
					continue;
				}
				update_post_meta( $post_id, $key, $value );
			}
		}

		$terms = $request->get_param( 'terms' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $taxonomy => $values ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				wp_set_object_terms( $post_id, $values, $taxonomy, false );
			}
		}

		$thumb = $request->get_param( 'featured_image' );
		if ( $thumb ) {
			set_post_thumbnail( $post_id, (int) $thumb );
		}
	}

	/**
	 * Meta oculta de WordPress que no debe tocarse a ciegas.
	 */
	private function is_protected_meta( $key ) {
		return is_protected_meta( $key, 'post' ) && 0 !== strpos( $key, '_elementor' ) && 0 !== strpos( $key, '_et_' ) && '_thumbnail_id' !== $key && '_wp_page_template' !== $key;
	}

	private function readable_meta( $post_id ) {
		$raw = get_post_meta( $post_id );
		$out = array();

		foreach ( $raw as $key => $values ) {
			// Los datos de Elementor y Divi son enormes: se piden aparte.
			if ( '_elementor_data' === $key ) {
				$out[ $key ] = '«usa /elementor/' . $post_id . '»';
				continue;
			}
			$value = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
			if ( is_string( $value ) && strlen( $value ) > 5000 ) {
				$value = substr( $value, 0, 5000 ) . '… [truncado]';
			}
			$out[ $key ] = $value;
		}

		return $out;
	}

	private function post_terms( $post ) {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$out[ $taxonomy ] = $terms;
			}
		}
		return $out;
	}

	private function diff( $post, $args ) {
		$changes = array();
		foreach ( $args as $field => $value ) {
			if ( 'ID' === $field ) {
				continue;
			}
			if ( isset( $post->$field ) && $post->$field !== $value ) {
				$changes[ $field ] = array(
					'from' => is_string( $post->$field ) ? wp_trim_words( wp_strip_all_tags( $post->$field ), 20 ) : $post->$field,
					'to'   => is_string( $value ) ? wp_trim_words( wp_strip_all_tags( $value ), 20 ) : $value,
				);
			}
		}
		return $changes;
	}

	/**
	 * Opciones que nunca salen por la API: contienen credenciales.
	 */
	private function is_secret_option( $key ) {
		$patterns = array( 'password', 'secret', 'api_key', 'apikey', 'token', 'salt', 'auth_key', 'private_key', 'license' );
		foreach ( $patterns as $pattern ) {
			if ( false !== stripos( $key, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
