<?php
/**
 * Punto único de escritura de posts.
 *
 * Por qué existe: las peticiones del puente no corren como un usuario con
 * `unfiltered_html`, así que WordPress pasa `post_content` por KSES al
 * guardar y elimina en silencio <style>, <script>, <form>, <input>… En una
 * página cuya maquetación vive en el contenido (módulo Código de Divi, HTML
 * a medida) eso destruye la página entera y el plugin devolvía «éxito».
 * Ocurrió dos veces en chacademyseminar.com (04/09 y 22/09/2026).
 *
 * Reglas que impone esta clase:
 *
 *  1. Ninguna ruta llama a wp_update_post()/wp_insert_post() directamente.
 *  2. Sin permiso de HTML crudo, si KSES va a eliminar algo, se responde 409
 *     ANTES de tocar nada.
 *  3. Con permiso, KSES se retira solo durante la escritura y se repone en
 *     un finally, pase lo que pase.
 *  4. Después de escribir se relee lo guardado. Si falta algo, se deshace
 *     la escritura y se devuelve error. Nunca más un éxito falso.
 *
 * Todas las entradas llegan SIN slashear (como las da WP_REST_Request); la
 * clase aplica wp_slash() una sola vez, que es lo que espera el núcleo.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Post_Writer {

	/** Etiquetas que KSES elimina de post_content y que vigilamos. */
	const WATCH = array( 'style', 'script', 'form', 'input', 'select', 'textarea', 'iframe', 'object', 'embed' );

	/** Campos con filtro *_save_pre de KSES. */
	const TEXT_FIELDS = array( 'post_content', 'post_title', 'post_excerpt' );

	/**
	 * Actualiza un post existente.
	 *
	 * @param array $args Igual que wp_update_post, SIN slashear. ID obligatorio.
	 * @param bool  $raw  true = guardar HTML crudo (el llamador ya comprobó el permiso).
	 * @return int|WP_Error ID del post o error.
	 */
	public static function update( array $args, $raw = false ) {
		$id = isset( $args['ID'] ) ? (int) $args['ID'] : 0;
		$before = $id ? get_post( $id, ARRAY_A ) : null;
		if ( ! $before ) {
			return new WP_Error( 'mad_not_found', 'No existe ese contenido.', array( 'status' => 404 ) );
		}

		$check = self::precheck( $args, $raw, $before );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = self::run( 'wp_update_post', $args, $raw );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'mad_write_failed', 'WordPress no ha guardado el cambio.', array( 'status' => 500 ) );
		}

		$lost = self::verify( $id, $args );
		if ( $lost ) {
			self::restore_row( $id, $before );
			return new WP_Error(
				'mad_content_dropped',
				'WordPress eliminó parte del contenido al guardar (' . implode( ', ', $lost ) . '). Se ha restaurado la versión anterior: no se ha aplicado nada.',
				array( 'status' => 500, 'lost' => $lost )
			);
		}

		return (int) $result;
	}

	/**
	 * Crea un post.
	 *
	 * @param array $args Igual que wp_insert_post, SIN slashear.
	 * @param bool  $raw  true = guardar HTML crudo.
	 * @return int|WP_Error
	 */
	public static function insert( array $args, $raw = false ) {
		$check = self::precheck( $args, $raw, null );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$id = self::run( 'wp_insert_post', $args, $raw );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! $id ) {
			return new WP_Error( 'mad_write_failed', 'WordPress no ha creado el contenido.', array( 'status' => 500 ) );
		}

		$lost = self::verify( $id, $args );
		if ( $lost ) {
			wp_delete_post( $id, true );
			return new WP_Error(
				'mad_content_dropped',
				'WordPress eliminó parte del contenido al guardar (' . implode( ', ', $lost ) . '). No se ha creado nada.',
				array( 'status' => 500, 'lost' => $lost )
			);
		}

		return (int) $id;
	}

	/**
	 * Ejecuta $callback con KSES retirado y lo repone siempre. Para copiar
	 * contenido que ya está en la BD (revisiones, snapshots): no añade
	 * nada nuevo, solo evita que la copia salga mutilada.
	 */
	public static function without_kses( callable $callback ) {
		$removed = false;
		if ( self::kses_active() ) {
			kses_remove_filters();
			$removed = true;
		}
		try {
			return call_user_func( $callback );
		} finally {
			if ( $removed ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * ¿Va a filtrar KSES post_content en esta petición?
	 */
	public static function kses_active() {
		return false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	}

	/**
	 * Cuántas etiquetas vigiladas hay en un HTML. Insensible a mayúsculas.
	 *
	 * @return array etiqueta => número (solo las presentes).
	 */
	public static function count_tags( $html ) {
		$out = array();
		$html = (string) $html;
		if ( '' === $html ) {
			return $out;
		}
		foreach ( self::WATCH as $tag ) {
			$n = preg_match_all( '/<' . $tag . '\b/i', $html );
			if ( $n ) {
				$out[ $tag ] = $n;
			}
		}
		return $out;
	}

	/**
	 * Aviso legible para dry_run y para el 409.
	 */
	public static function risk_summary( $html ) {
		$parts = array();
		foreach ( self::count_tags( $html ) as $tag => $n ) {
			$parts[] = $tag . ' (' . $n . ')';
		}
		return $parts;
	}

	// ----------------------------------------------------------------- interno

	private static function precheck( array $args, $raw, $before ) {
		if ( $raw || ! isset( $args['post_content'] ) || ! self::kses_active() ) {
			return true;
		}

		// Lo que se envía es el contenido final: si lleva etiquetas que KSES
		// elimina, se perderían. No miramos solo «lo nuevo»: una sustitución
		// de texto reenvía la página entera, con sus <style> de siempre.
		$risk = self::risk_summary( $args['post_content'] );
		if ( ! $risk ) {
			return true;
		}

		return new WP_Error(
			'mad_kses_would_strip',
			'Este contenido lleva etiquetas que WordPress elimina al guardar: ' . implode( ', ', $risk ) . '. No se ha tocado nada. Repite la llamada con raw=true (requiere permiso content:raw).',
			array( 'status' => 409, 'tags' => $risk )
		);
	}

	private static function run( $fn, array $args, $raw ) {
		$slashed = wp_slash( $args );

		$removed = false;
		if ( $raw && self::kses_active() ) {
			kses_remove_filters();
			$removed = true;
		}

		try {
			return call_user_func( $fn, $slashed, true );
		} finally {
			if ( $removed ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * Relee lo guardado y devuelve la lista de lo perdido (vacía si todo bien).
	 */
	private static function verify( $id, array $args ) {
		if ( ! isset( $args['post_content'] ) ) {
			return array();
		}

		clean_post_cache( $id );
		$expected = (string) $args['post_content'];
		$saved    = (string) get_post_field( 'post_content', $id, 'raw' );

		if ( $saved === $expected ) {
			return array();
		}

		$lost = array();
		$want = self::count_tags( $expected );
		$have = self::count_tags( $saved );
		foreach ( $want as $tag => $n ) {
			$got = isset( $have[ $tag ] ) ? $have[ $tag ] : 0;
			if ( $got < $n ) {
				$lost[] = $tag . ' (' . ( $n - $got ) . ')';
			}
		}

		// WordPress/Divi normalizan y suelen AÑADIR bytes. Perder más de un
		// 2 % no es normalización.
		$len_e = strlen( $expected );
		$len_s = strlen( $saved );
		if ( $len_e > 0 && $len_s < $len_e * 0.98 ) {
			$lost[] = 'longitud ' . $len_e . '→' . $len_s;
		}

		return $lost;
	}

	/**
	 * Devuelve la fila del post a su estado previo, sin pasar por filtros.
	 */
	private static function restore_row( $id, array $before ) {
		global $wpdb;

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->posts}" ); // phpcs:ignore
		$row     = array_intersect_key( $before, array_flip( $columns ) );
		unset( $row['ID'] );

		$wpdb->update( $wpdb->posts, $row, array( 'ID' => (int) $id ) ); // phpcs:ignore
		clean_post_cache( $id );
	}
}
