<?php
/**
 * Biblioteca de medios: listar, subir desde URL o base64, editar y borrar.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_Media extends MAD_Controller {

	public function register_routes() {

		$this->add(
			'/media',
			'GET',
			array( $this, 'list_media' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'search'    => array( 'type' => 'string' ),
					'mime'      => array( 'type' => 'string' ),
					'per_page'  => array( 'type' => 'integer', 'default' => 20 ),
					'page'      => array( 'type' => 'integer', 'default' => 1 ),
					'unattached'=> array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		$this->add( '/media/(?P<id>\d+)', 'GET', array( $this, 'get_item' ), array( 'scope' => 'read' ) );

		$this->add(
			'/media/from-url',
			'POST',
			array( $this, 'upload_from_url' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'url'         => array( 'type' => 'string', 'required' => true ),
					'filename'    => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'alt'         => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'attach_to'   => array( 'type' => 'integer' ),
					'set_featured'=> array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		$this->add(
			'/media/from-base64',
			'POST',
			array( $this, 'upload_from_base64' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'data'     => array( 'type' => 'string', 'required' => true ),
					'filename' => array( 'type' => 'string', 'required' => true ),
					'title'    => array( 'type' => 'string' ),
					'alt'      => array( 'type' => 'string' ),
					'attach_to'=> array( 'type' => 'integer' ),
				),
			)
		);

		$this->add(
			'/media/(?P<id>\d+)',
			'PATCH, PUT',
			array( $this, 'update_item' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'title'       => array( 'type' => 'string' ),
					'alt'         => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
				),
			)
		);

		$this->add(
			'/media/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_item' ),
			array( 'scope' => 'content', 'mutating' => true )
		);

		// Auditoría rápida de accesibilidad y SEO: qué imágenes van sin alt.
		$this->add( '/media/missing-alt', 'GET', array( $this, 'missing_alt' ), array( 'scope' => 'read' ) );
	}

	public function list_media( $request ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $request->get_param( 'search' ),
			'posts_per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
		);

		if ( $request->get_param( 'mime' ) ) {
			$args['post_mime_type'] = $request->get_param( 'mime' );
		}
		if ( $request->get_param( 'unattached' ) ) {
			$args['post_parent'] = 0;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->shape_attachment( $post );
		}

		return $this->ok(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			)
		);
	}

	public function get_item( $request ) {
		$id = (int) $request['id'];
		if ( 'attachment' !== get_post_type( $id ) ) {
			return $this->error( 'mad_not_found', 'No existe ese adjunto.', 404 );
		}
		return $this->ok( $this->shape_attachment( get_post( $id ) ) );
	}

	public function upload_from_url( $request ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );

		if ( ! wp_http_validate_url( $url ) ) {
			return $this->error( 'mad_bad_url', 'La URL no es válida.', 422 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se descargaría %s a la biblioteca de medios.', $url ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp = download_url( $url, 60 );
		if ( is_wp_error( $temp ) ) {
			return $temp;
		}

		$filename = $request->get_param( 'filename' );
		if ( ! $filename ) {
			$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		}

		$file = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $temp,
		);

		$attach_to = (int) $request->get_param( 'attach_to' );
		$id        = media_handle_sideload( $file, $attach_to ?: 0 );

		if ( is_wp_error( $id ) ) {
			@unlink( $temp ); // phpcs:ignore
			return $id;
		}

		$this->apply_media_fields( $id, $request );

		if ( $attach_to && $request->get_param( 'set_featured' ) ) {
			set_post_thumbnail( $attach_to, $id );
		}

		return $this->ok(
			array(
				'applied' => true,
				'summary' => sprintf( 'Subido «%s» como adjunto #%d.', $file['name'], $id ),
				'media'   => $this->shape_attachment( get_post( $id ) ),
			),
			201
		);
	}

	public function upload_from_base64( $request ) {
		$data     = (string) $request->get_param( 'data' );
		$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );

		// Aceptamos tanto base64 puro como data: URI.
		if ( 0 === strpos( $data, 'data:' ) && false !== strpos( $data, ',' ) ) {
			$data = substr( $data, strpos( $data, ',' ) + 1 );
		}

		$binary = base64_decode( $data, true );
		if ( false === $binary ) {
			return $this->error( 'mad_bad_base64', 'Los datos no son base64 válido.', 422 );
		}

		$check = wp_check_filetype( $filename );
		if ( empty( $check['type'] ) ) {
			return $this->error( 'mad_bad_filetype', 'WordPress no admite ese tipo de fichero.', 422 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se subiría «%s» (%s).', $filename, size_format( strlen( $binary ) ) ) );
		}

		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return $this->error( 'mad_upload_failed', $upload['error'], 500 );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attach_to = (int) $request->get_param( 'attach_to' );
		$id        = wp_insert_attachment(
			array(
				'post_mime_type' => $check['type'],
				'post_title'     => $request->get_param( 'title' ) ?: pathinfo( $filename, PATHINFO_FILENAME ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$attach_to ?: 0,
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		$this->apply_media_fields( $id, $request );

		return $this->ok(
			array(
				'applied' => true,
				'summary' => sprintf( 'Subido «%s» como adjunto #%d.', $filename, $id ),
				'media'   => $this->shape_attachment( get_post( $id ) ),
			),
			201
		);
	}

	public function update_item( $request ) {
		$id = (int) $request['id'];

		if ( 'attachment' !== get_post_type( $id ) ) {
			return $this->error( 'mad_not_found', 'No existe ese adjunto.', 404 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se actualizarían los metadatos del adjunto #%d.', $id ) );
		}

		$backup = MAD_Safety::snapshot_post( $id );
		$this->apply_media_fields( $id, $request );

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Actualizado el adjunto #%d.', $id ),
				'backup_ref' => is_wp_error( $backup ) ? null : $backup,
				'media'      => $this->shape_attachment( get_post( $id ) ),
			)
		);
	}

	public function delete_item( $request ) {
		$id = (int) $request['id'];

		if ( 'attachment' !== get_post_type( $id ) ) {
			return $this->error( 'mad_not_found', 'No existe ese adjunto.', 404 );
		}

		// Un adjunto en uso no debería desaparecer sin avisar.
		$used_by = $this->find_usages( $id );

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se borraría el adjunto #%d.', $id ),
				array( 'used_by' => $used_by )
			);
		}

		if ( ! empty( $used_by ) ) {
			return $this->error(
				'mad_media_in_use',
				sprintf( 'Ese adjunto se usa en %d contenido(s): %s. Quítalo de ahí primero.', count( $used_by ), implode( ', ', wp_list_pluck( $used_by, 'id' ) ) ),
				409
			);
		}

		$result = wp_delete_attachment( $id, true );
		if ( ! $result ) {
			return $this->error( 'mad_delete_failed', 'No se pudo borrar el adjunto.', 500 );
		}

		return $this->ok(
			array(
				'applied' => true,
				'summary' => sprintf( 'Borrado el adjunto #%d.', $id ),
			)
		);
	}

	public function missing_alt() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 200,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				),
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
				'url'   => wp_get_attachment_url( $post->ID ),
			);
		}

		return $this->ok(
			array(
				'summary' => sprintf( '%d imágenes sin texto alternativo.', count( $items ) ),
				'items'   => $items,
			)
		);
	}

	// --------------------------------------------------------------- helpers

	private function apply_media_fields( $id, $request ) {
		$post = array( 'ID' => $id );

		if ( null !== $request->get_param( 'title' ) ) {
			$post['post_title'] = $request->get_param( 'title' );
		}
		if ( null !== $request->get_param( 'caption' ) ) {
			$post['post_excerpt'] = $request->get_param( 'caption' );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$post['post_content'] = $request->get_param( 'description' );
		}

		if ( count( $post ) > 1 ) {
			// Pasa por el escritor común: si la descripción llevara HTML que
			// WordPress elimina, se rechaza en vez de guardarse mutilada.
			MAD_Post_Writer::update( $post, false );
		}

		if ( null !== $request->get_param( 'alt' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $request->get_param( 'alt' ) ) );
		}
	}

	private function shape_attachment( $post ) {
		$meta = wp_get_attachment_metadata( $post->ID );

		return array(
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'filename' => basename( get_attached_file( $post->ID ) ),
			'url'      => wp_get_attachment_url( $post->ID ),
			'mime'     => $post->post_mime_type,
			'alt'      => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'  => $post->post_excerpt,
			'parent'   => (int) $post->post_parent,
			'uploaded' => $post->post_date_gmt,
			'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'filesize' => isset( $meta['filesize'] ) ? size_format( $meta['filesize'] ) : null,
			'sizes'    => isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array(),
		);
	}

	/**
	 * Busca dónde se está usando un adjunto: como destacada, en el contenido
	 * o referenciado por su ID en cualquier meta.
	 */
	private function find_usages( $attachment_id ) {
		global $wpdb;

		$url  = wp_get_attachment_url( $attachment_id );
		$file = $url ? basename( $url ) : '';
		$out  = array();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash','auto-draft')
				   AND post_type != 'attachment'
				   AND post_content LIKE %s
				 LIMIT 20",
				'%' . $wpdb->esc_like( $file ) . '%'
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[] = array( 'id' => (int) $row['ID'], 'title' => $row['post_title'], 'via' => 'contenido' );
		}

		$thumbs = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 20",
				$attachment_id
			),
			ARRAY_A
		);

		foreach ( (array) $thumbs as $row ) {
			$out[] = array( 'id' => (int) $row['post_id'], 'title' => get_the_title( $row['post_id'] ), 'via' => 'imagen destacada' );
		}

		return $out;
	}
}
