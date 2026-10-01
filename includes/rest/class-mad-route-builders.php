<?php
/**
 * Constructores visuales: Elementor, Divi y bloques nativos.
 *
 * La idea es que da igual con qué esté hecha la página: /builders/{id}/outline
 * devuelve siempre la misma forma (una lista plana de nodos con texto e
 * imágenes localizables) para poder razonar sobre el contenido sin saber
 * antes qué constructor usa la web.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_Builders extends MAD_Controller {

	public function register_routes() {

		$this->add(
			'/builders/(?P<id>\d+)/outline',
			'GET',
			array( $this, 'outline' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/builders/(?P<id>\d+)/replace-text',
			'POST',
			array( $this, 'replace_text' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'search'      => array( 'type' => 'string', 'required' => true ),
					'replace'     => array( 'type' => 'string', 'required' => true ),
					'case_sensitive' => array( 'type' => 'boolean', 'default' => true ),
					'raw'            => self::raw_arg(),
				),
			)
		);

		$this->add(
			'/builders/(?P<id>\d+)/replace-image',
			'POST',
			array( $this, 'replace_image' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'from_url' => array( 'type' => 'string', 'required' => true ),
					'to_url'   => array( 'type' => 'string', 'required' => true ),
					'to_id'    => array( 'type' => 'integer' ),
					'raw'      => self::raw_arg(),
				),
			)
		);

		// ------------------------------------------------------- Elementor

		$this->add(
			'/elementor/(?P<id>\d+)',
			'GET',
			array( $this, 'elementor_get' ),
			array(
				'scope' => 'read',
				'args'  => array( 'raw' => array( 'type' => 'boolean', 'default' => false ) ),
			)
		);

		$this->add(
			'/elementor/(?P<id>\d+)/element/(?P<element>[A-Za-z0-9]+)',
			'POST',
			array( $this, 'elementor_update_element' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array( 'settings' => array( 'type' => 'object', 'required' => true ) ),
			)
		);

		// ------------------------------------------------------------ Divi

		$this->add(
			'/divi/(?P<id>\d+)',
			'GET',
			array( $this, 'divi_get' ),
			array(
				'scope' => 'read',
				'args'  => array( 'raw' => array( 'type' => 'boolean', 'default' => false ) ),
			)
		);

		// ---------------------------------------------------------- bloques

		$this->add(
			'/blocks/(?P<id>\d+)',
			'GET',
			array( $this, 'blocks_get' ),
			array( 'scope' => 'read' )
		);

		$this->add(
			'/blocks/(?P<id>\d+)',
			'POST',
			array( $this, 'blocks_set' ),
			array(
				'scope'    => 'content',
				'mutating' => true,
				'args'     => array(
					'blocks' => array( 'type' => 'array', 'required' => true ),
					'raw'    => self::raw_arg(),
				),
			)
		);
	}

	// ------------------------------------------------------------- detección

	/**
	 * Con qué está construida una entrada concreta.
	 *
	 * @return string 'elementor'|'divi'|'blocks'|'classic'
	 */
	public static function detect_builder( $post_id ) {
		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' ) {
			return 'elementor';
		}
		if ( get_post_meta( $post_id, '_et_pb_use_builder', true ) === 'on' ) {
			return 'divi';
		}

		$post = get_post( $post_id );
		if ( $post && function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			return 'blocks';
		}

		return 'classic';
	}

	// --------------------------------------------------------------- outline

	/**
	 * Radiografía uniforme de la página, sea cual sea el constructor.
	 */
	public function outline( $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}

		$builder = self::detect_builder( $id );

		switch ( $builder ) {
			case 'elementor':
				$nodes = $this->elementor_outline( $id );
				break;
			case 'divi':
				$nodes = $this->divi_outline( $post->post_content );
				break;
			case 'blocks':
				$nodes = $this->blocks_outline( $post->post_content );
				break;
			default:
				$nodes = array(
					array(
						'id'    => 'content',
						'type'  => 'classic',
						'text'  => wp_strip_all_tags( $post->post_content ),
						'depth' => 0,
					),
				);
		}

		return $this->ok(
			array(
				'post_id' => $id,
				'title'   => $post->post_title,
				'builder' => $builder,
				'summary' => sprintf( '%d nodo(s) en una página de tipo %s.', count( $nodes ), $builder ),
				'nodes'   => $nodes,
			)
		);
	}

	// -------------------------------------------------------- buscar/sustituir

	/**
	 * Sustituye texto en toda la página, entienda el constructor que entienda.
	 * Es la operación que más se pide en el día a día de una agencia.
	 */
	public function replace_text( $request ) {
		$id      = (int) $request['id'];
		$search  = (string) $request->get_param( 'search' );
		$replace = (string) $request->get_param( 'replace' );
		$cs      = (bool) $request->get_param( 'case_sensitive' );
		$post    = get_post( $id );

		if ( ! $post ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}
		if ( '' === $search ) {
			return $this->error( 'mad_empty_search', 'El texto a buscar no puede estar vacío.', 422 );
		}

		$builder = self::detect_builder( $id );

		$raw_html = $this->raw_requested( $request );
		if ( is_wp_error( $raw_html ) ) {
			return $raw_html;
		}

		if ( 'elementor' === $builder ) {
			$raw     = get_post_meta( $id, '_elementor_data', true );
			$subject = is_string( $raw ) ? $raw : wp_json_encode( $raw );
		} else {
			$subject = $post->post_content;
		}

		$count = $cs
			? substr_count( $subject, $search )
			: preg_match_all( '/' . preg_quote( $search, '/' ) . '/i', $subject );

		if ( 0 === $count ) {
			return $this->ok(
				array(
					'applied'      => false,
					'summary'      => sprintf( 'No se ha encontrado «%s» en la página #%d.', $search, $id ),
					'replacements' => 0,
				)
			);
		}

		if ( $this->is_dry( $request ) ) {
			$extra = array( 'occurrences' => $count, 'builder' => $builder );
			if ( 'elementor' !== $builder && ! $raw_html && MAD_Post_Writer::kses_active() ) {
				$risk = MAD_Post_Writer::risk_summary( $subject );
				if ( $risk ) {
					$extra['warning'] = 'Sin raw=true esta sustitución se rechazará (409): la página lleva ' . implode( ', ', $risk ) . ', que WordPress elimina al guardar.';
				}
			}
			return $this->dry(
				sprintf( 'Se harían %d sustitución(es) de «%s» por «%s» en la página #%d (%s).', $count, $search, $replace, $id, $builder ),
				$extra
			);
		}

		$backup = MAD_Safety::snapshot_post( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		if ( 'elementor' === $builder ) {
			// En Elementor el dato es JSON: escapamos el reemplazo para no
			// romper la estructura con comillas o barras.
			$safe_search  = trim( wp_json_encode( $search ), '"' );
			$safe_replace = trim( wp_json_encode( $replace ), '"' );

			$updated = $cs
				? str_replace( $safe_search, $safe_replace, $subject )
				: preg_replace( '/' . preg_quote( $safe_search, '/' ) . '/i', $safe_replace, $subject );

			if ( null === json_decode( $updated, true ) ) {
				return $this->error( 'mad_elementor_broken', 'La sustitución habría dejado el JSON de Elementor inválido. Cancelada.', 422 );
			}

			update_post_meta( $id, '_elementor_data', wp_slash( $updated ) );
			$this->clear_elementor_cache( $id );
		} else {
			$updated = $cs
				? str_replace( $search, $replace, $subject )
				: preg_replace( '/' . preg_quote( $search, '/' ) . '/i', $replace, $subject );

			$written = MAD_Post_Writer::update(
				array(
					'ID'           => $id,
					'post_content' => $updated,
				),
				$raw_html
			);
			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}

		return $this->ok(
			array(
				'applied'      => true,
				'summary'      => sprintf( '%d sustitución(es) en la página #%d (%s).', $count, $id, $builder ),
				'replacements' => $count,
				'backup_ref'   => $backup,
			)
		);
	}

	public function replace_image( $request ) {
		$id   = (int) $request['id'];
		$from = (string) $request->get_param( 'from_url' );
		$to   = (string) $request->get_param( 'to_url' );

		// Reutilizamos la lógica de texto: en los tres constructores la URL
		// de la imagen vive como cadena dentro del contenido o del JSON.
		$proxy = new WP_REST_Request( 'POST' );
		$proxy->set_param( 'id', $id );
		$proxy->set_url_params( array( 'id' => $id ) );
		$proxy->set_param( 'search', $from );
		$proxy->set_param( 'replace', $to );
		$proxy->set_param( 'case_sensitive', true );
		$proxy->set_param( 'dry_run', $this->is_dry( $request ) );
		$proxy->set_param( 'raw', (bool) $request->get_param( 'raw' ) );

		$result = $this->replace_text( $proxy );

		// Si además nos dan el ID nuevo, actualizamos las referencias por ID
		// que usa Elementor junto a la URL.
		$to_id = (int) $request->get_param( 'to_id' );
		if ( $to_id && ! $this->is_dry( $request ) && ! is_wp_error( $result ) ) {
			$this->clear_elementor_cache( $id );
		}

		return $result;
	}

	// ------------------------------------------------------------- Elementor

	public function elementor_get( $request ) {
		$id = (int) $request['id'];

		if ( 'elementor' !== self::detect_builder( $id ) ) {
			return $this->error( 'mad_not_elementor', 'Esa página no está hecha con Elementor.', 422 );
		}

		$raw  = get_post_meta( $id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $data ) ) {
			return $this->error( 'mad_elementor_unreadable', 'No se ha podido leer el árbol de Elementor.', 500 );
		}

		return $this->ok(
			array(
				'post_id'  => $id,
				'version'  => get_post_meta( $id, '_elementor_version', true ),
				'template' => get_post_meta( $id, '_elementor_template_type', true ),
				'nodes'    => $this->elementor_outline( $id ),
				'raw'      => $request->get_param( 'raw' ) ? $data : null,
			)
		);
	}

	public function elementor_update_element( $request ) {
		$id       = (int) $request['id'];
		$element  = (string) $request['element'];
		$settings = (array) $request->get_param( 'settings' );

		$raw  = get_post_meta( $id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $data ) ) {
			return $this->error( 'mad_elementor_unreadable', 'No se ha podido leer el árbol de Elementor.', 500 );
		}

		$found = false;
		$this->walk_elementor(
			$data,
			static function ( &$node ) use ( $element, $settings, &$found ) {
				if ( isset( $node['id'] ) && $node['id'] === $element ) {
					$node['settings'] = array_merge( $node['settings'] ?? array(), $settings );
					$found            = true;
				}
			}
		);

		if ( ! $found ) {
			return $this->error( 'mad_element_not_found', sprintf( 'No hay ningún elemento con id «%s» en esta página.', $element ), 404 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se actualizarían %d ajuste(s) del elemento «%s».', count( $settings ), $element ),
				array( 'settings' => $settings )
			);
		}

		$backup = MAD_Safety::snapshot_post( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		$this->clear_elementor_cache( $id );

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Elemento «%s» actualizado en la página #%d.', $element, $id ),
				'backup_ref' => $backup,
			)
		);
	}

	private function elementor_outline( $post_id ) {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $data ) ) {
			return array();
		}

		$nodes = array();
		$this->collect_elementor( $data, $nodes, 0 );
		return $nodes;
	}

	private function collect_elementor( $elements, &$nodes, $depth ) {
		foreach ( (array) $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$type = $element['widgetType'] ?? ( $element['elType'] ?? 'desconocido' );
			$text = $this->extract_elementor_text( $element['settings'] ?? array() );

			$nodes[] = array(
				'id'     => $element['id'] ?? null,
				'type'   => $type,
				'depth'  => $depth,
				'text'   => $text,
				'images' => $this->extract_elementor_images( $element['settings'] ?? array() ),
			);

			if ( ! empty( $element['elements'] ) ) {
				$this->collect_elementor( $element['elements'], $nodes, $depth + 1 );
			}
		}
	}

	private function extract_elementor_text( $settings ) {
		$keys  = array( 'title', 'editor', 'text', 'heading', 'description', 'caption', 'title_text', 'button_text', 'html' );
		$found = array();

		foreach ( $keys as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$found[] = wp_strip_all_tags( $settings[ $key ] );
			}
		}

		// Listas y repetidores (acordeones, testimonios, iconos con texto).
		foreach ( $settings as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			foreach ( $value as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				foreach ( $keys as $key ) {
					if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
						$found[] = wp_strip_all_tags( $item[ $key ] );
					}
				}
			}
		}

		return $found ? trim( implode( ' · ', array_unique( $found ) ) ) : '';
	}

	private function extract_elementor_images( $settings ) {
		$images = array();

		array_walk_recursive(
			$settings,
			static function ( $value, $key ) use ( &$images ) {
				if ( 'url' === $key && is_string( $value ) && preg_match( '/\.(jpe?g|png|gif|webp|svg|avif)(\?|$)/i', $value ) ) {
					$images[] = $value;
				}
			}
		);

		return array_values( array_unique( $images ) );
	}

	/**
	 * Recorre el árbol pasando cada nodo por referencia al callback.
	 */
	private function walk_elementor( &$elements, $callback ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$callback( $element );
			if ( ! empty( $element['elements'] ) ) {
				$this->walk_elementor( $element['elements'], $callback );
			}
		}
		unset( $element );
	}

	/**
	 * Sin esto, Elementor sigue sirviendo el CSS antiguo y parece que el
	 * cambio no se ha aplicado.
	 */
	private function clear_elementor_cache( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( Throwable $e ) {
				// Si la API interna de Elementor cambia, no es motivo para
				// tumbar la petición: el contenido ya está guardado.
				return;
			}
		}
	}

	// ------------------------------------------------------------------ Divi

	public function divi_get( $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}
		if ( 'divi' !== self::detect_builder( $id ) ) {
			return $this->error( 'mad_not_divi', 'Esa página no está hecha con el constructor de Divi.', 422 );
		}

		return $this->ok(
			array(
				'post_id' => $id,
				'tree'    => $this->parse_divi( $post->post_content ),
				'nodes'   => $this->divi_outline( $post->post_content ),
				'raw'     => $request->get_param( 'raw' ) ? $post->post_content : null,
			)
		);
	}

	private function divi_outline( $content ) {
		$tree  = $this->parse_divi( $content );
		$nodes = array();
		$this->flatten_divi( $tree, $nodes, 0 );
		return $nodes;
	}

	private function flatten_divi( $tree, &$nodes, $depth ) {
		foreach ( $tree as $node ) {
			$text = trim( wp_strip_all_tags( $node['content'] ?? '' ) );

			$nodes[] = array(
				'id'    => $node['attrs']['module_id'] ?? ( $node['attrs']['admin_label'] ?? null ),
				'type'  => $node['tag'],
				'depth' => $depth,
				'text'  => $text,
				'images'=> $this->extract_divi_images( $node ),
			);

			if ( ! empty( $node['children'] ) ) {
				$this->flatten_divi( $node['children'], $nodes, $depth + 1 );
			}
		}
	}

	private function extract_divi_images( $node ) {
		$images = array();

		foreach ( (array) ( $node['attrs'] ?? array() ) as $key => $value ) {
			if ( is_string( $value ) && preg_match( '/\.(jpe?g|png|gif|webp|svg|avif)(\?|$)/i', $value ) ) {
				$images[] = $value;
			}
		}

		return array_values( array_unique( $images ) );
	}

	/**
	 * Parser de los shortcodes de Divi.
	 *
	 * Divi guarda el layout como shortcodes anidados en post_content. Usamos
	 * un tokenizador con pila en vez de una expresión regular recursiva:
	 * es más largo pero no se atraganta con anidamientos profundos ni con
	 * contenido que lleva corchetes dentro.
	 */
	private function parse_divi( $content ) {
		$pattern = '/\[(\/?)(et_pb_[a-z0-9_]+)((?:\s+[a-zA-Z0-9_\-]+="[^"]*")*)\s*(\/?)\]/i';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array();
		}

		$root       = array();
		$containers = array( &$root ); // Dónde se cuelgan los nodos nuevos.
		$open       = array();         // Nodos abiertos, para asignarles texto.
		$last       = 0;

		foreach ( $matches as $match ) {
			$full        = $match[0][0];
			$offset      = $match[0][1];
			$is_closing  = '/' === $match[1][0];
			$tag         = $match[2][0];
			$attr_string = $match[3][0];
			$self_closed = '/' === $match[4][0];

			// El texto entre el token anterior y este pertenece al nodo que
			// está abierto ahora mismo, no a su hermano anterior.
			$between = substr( $content, $last, $offset - $last );
			$last    = $offset + strlen( $full );

			if ( '' !== trim( $between ) && ! empty( $open ) ) {
				$current            = &$open[ count( $open ) - 1 ];
				$current['content'] = trim( ( $current['content'] ?? '' ) . $between );
				unset( $current );
			}

			if ( $is_closing ) {
				// Un cierre huérfano no debe vaciar la pila raíz.
				if ( count( $containers ) > 1 ) {
					array_pop( $containers );
					array_pop( $open );
				}
				continue;
			}

			$node = array(
				'tag'      => $tag,
				'attrs'    => $this->parse_shortcode_attrs( $attr_string ),
				'content'  => '',
				'children' => array(),
			);

			$container   = &$containers[ count( $containers ) - 1 ];
			$container[] = $node;
			$index       = array_key_last( $container );

			if ( ! $self_closed ) {
				$open[]       = &$container[ $index ];
				$containers[] = &$container[ $index ]['children'];
			}

			unset( $container );
		}

		return $root;
	}

	private function parse_shortcode_attrs( $string ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z0-9_\-]+)="([^"]*)"/', $string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attrs[ $match[1] ] = $match[2];
			}
		}
		return $attrs;
	}

	// --------------------------------------------------------------- bloques

	public function blocks_get( $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}

		return $this->ok(
			array(
				'post_id' => $id,
				'blocks'  => parse_blocks( $post->post_content ),
				'nodes'   => $this->blocks_outline( $post->post_content ),
			)
		);
	}

	public function blocks_set( $request ) {
		$id     = (int) $request['id'];
		$blocks = (array) $request->get_param( 'blocks' );

		if ( ! get_post( $id ) ) {
			return $this->error( 'mad_not_found', 'No existe ese contenido.', 404 );
		}

		$content = serialize_blocks( $blocks );

		$raw_html = $this->raw_requested( $request );
		if ( is_wp_error( $raw_html ) ) {
			return $raw_html;
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se reescribiría la página #%d con %d bloque(s).', $id, count( $blocks ) ),
				array( 'preview' => substr( $content, 0, 1000 ) )
			);
		}

		$backup = MAD_Safety::snapshot_post( $id );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$written = MAD_Post_Writer::update(
			array(
				'ID'           => $id,
				'post_content' => $content,
			),
			$raw_html
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Página #%d reescrita con %d bloque(s).', $id, count( $blocks ) ),
				'backup_ref' => $backup,
			)
		);
	}

	private function blocks_outline( $content ) {
		$nodes = array();
		$this->collect_blocks( parse_blocks( $content ), $nodes, 0 );
		return $nodes;
	}

	private function collect_blocks( $blocks, &$nodes, $depth ) {
		foreach ( $blocks as $index => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue; // Trozos de HTML suelto entre bloques.
			}

			$nodes[] = array(
				'id'     => $block['attrs']['metadata']['name'] ?? (string) $index,
				'type'   => $block['blockName'],
				'depth'  => $depth,
				'text'   => trim( wp_strip_all_tags( $block['innerHTML'] ?? '' ) ),
				'images' => $this->extract_block_images( $block ),
			);

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_blocks( $block['innerBlocks'], $nodes, $depth + 1 );
			}
		}
	}

	private function extract_block_images( $block ) {
		$images = array();

		if ( preg_match_all( '/src="([^"]+\.(?:jpe?g|png|gif|webp|svg|avif))"/i', $block['innerHTML'] ?? '', $matches ) ) {
			$images = $matches[1];
		}

		return array_values( array_unique( $images ) );
	}
}
