<?php
/**
 * Red de seguridad: backups, validación de sintaxis y rollback.
 *
 * Regla del plugin: nada que muta el estado de la web se ejecuta sin dejar
 * antes una copia recuperable. Si no se puede hacer la copia, la operación
 * se aborta.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Safety {

	const BACKUP_DIR = 'mad-backups';

	/**
	 * Crea (y protege) el directorio de copias dentro de uploads.
	 *
	 * @return string|WP_Error Ruta absoluta.
	 */
	public static function ensure_backup_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'mad_uploads_error', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::BACKUP_DIR;

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mad_mkdir_failed', 'No se pudo crear el directorio de copias.' );
		}

		// Las copias contienen código fuente: no pueden ser públicas.
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silencio.\n" ); // phpcs:ignore
		}

		return $dir;
	}

	/**
	 * Copia un fichero antes de tocarlo.
	 *
	 * @param string $path Ruta absoluta del fichero original.
	 * @return string|WP_Error Referencia del backup (nombre relativo).
	 */
	public static function backup_file( $path ) {
		$dir = self::ensure_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! file_exists( $path ) ) {
			// Fichero nuevo: dejamos un marcador para que el rollback sepa
			// que la forma de deshacer es borrarlo, no restaurar contenido.
			$ref  = self::make_ref( $path, 'new' );
			$meta = array(
				'original' => $path,
				'type'     => 'new-file',
				'created'  => current_time( 'mysql', true ),
			);
			file_put_contents( trailingslashit( $dir ) . $ref . '.json', wp_json_encode( $meta ) ); // phpcs:ignore
			return $ref;
		}

		$ref  = self::make_ref( $path, 'bak' );
		$dest = trailingslashit( $dir ) . $ref;

		if ( ! copy( $path, $dest ) ) {
			return new WP_Error( 'mad_backup_failed', 'No se pudo copiar el fichero antes de modificarlo.' );
		}

		$meta = array(
			'original' => $path,
			'type'     => 'file',
			'size'     => filesize( $path ),
			'created'  => current_time( 'mysql', true ),
		);
		file_put_contents( $dest . '.json', wp_json_encode( $meta ) ); // phpcs:ignore

		return $ref;
	}

	/**
	 * Deshace un backup: restaura el contenido o borra el fichero creado.
	 */
	public static function restore_file( $ref ) {
		$dir = self::ensure_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$ref  = basename( $ref );
		$meta_path = trailingslashit( $dir ) . $ref . '.json';

		if ( ! file_exists( $meta_path ) ) {
			return new WP_Error( 'mad_backup_missing', 'No existe esa copia.', array( 'status' => 404 ) );
		}

		$meta = json_decode( file_get_contents( $meta_path ), true ); // phpcs:ignore
		if ( ! is_array( $meta ) || empty( $meta['original'] ) ) {
			return new WP_Error( 'mad_backup_corrupt', 'Metadatos de copia ilegibles.' );
		}

		$original = $meta['original'];

		if ( 'new-file' === $meta['type'] ) {
			if ( file_exists( $original ) && ! unlink( $original ) ) { // phpcs:ignore
				return new WP_Error( 'mad_restore_failed', 'No se pudo borrar el fichero creado.' );
			}
			return array( 'restored' => $original, 'action' => 'deleted' );
		}

		$backup = trailingslashit( $dir ) . $ref;
		if ( ! file_exists( $backup ) ) {
			return new WP_Error( 'mad_backup_missing', 'Falta el fichero de la copia.' );
		}

		if ( ! copy( $backup, $original ) ) {
			return new WP_Error( 'mad_restore_failed', 'No se pudo restaurar el fichero.' );
		}

		return array( 'restored' => $original, 'action' => 'reverted' );
	}

	public static function list_backups( $limit = 100 ) {
		$dir = self::ensure_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return array();
		}

		$files = glob( trailingslashit( $dir ) . '*.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		// Más recientes primero.
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		$out = array();
		foreach ( array_slice( $files, 0, (int) $limit ) as $file ) {
			$meta = json_decode( file_get_contents( $file ), true ); // phpcs:ignore
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$meta['ref'] = basename( $file, '.json' );
			$out[]       = $meta;
		}

		return $out;
	}

	/**
	 * Valida sintaxis PHP SIN ejecutar el código.
	 *
	 * token_get_all() con TOKEN_PARSE lanza ParseError ante código inválido
	 * pero no evalúa nada, que es justo lo que necesitamos: comprobar antes
	 * de escribir y no dejar nunca un functions.php roto.
	 *
	 * @param string $code
	 * @return true|WP_Error
	 */
	public static function lint_php( $code ) {
		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			return new WP_Error( 'mad_empty_code', 'El contenido está vacío.' );
		}

		try {
			token_get_all( $code, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return new WP_Error(
				'mad_php_syntax',
				sprintf( 'Error de sintaxis PHP en la línea %d: %s', $e->getLine(), $e->getMessage() ),
				array( 'status' => 422 )
			);
		} catch ( Error $e ) {
			return new WP_Error( 'mad_php_error', $e->getMessage(), array( 'status' => 422 ) );
		}

		return true;
	}

	/**
	 * Comprobación equivalente para otros formatos.
	 */
	public static function lint( $code, $extension ) {
		$extension = strtolower( ltrim( (string) $extension, '.' ) );

		switch ( $extension ) {
			case 'php':
				return self::lint_php( $code );

			case 'json':
				json_decode( $code );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_Error(
						'mad_json_syntax',
						'JSON inválido: ' . json_last_error_msg(),
						array( 'status' => 422 )
					);
				}
				return true;

			default:
				// CSS, JS, HTML, txt: no bloqueamos, siempre hay backup.
				return true;
		}
	}

	/**
	 * Snapshot de un post antes de modificarlo. Nos apoyamos en el sistema
	 * de revisiones nativo cuando está disponible.
	 */
	public static function snapshot_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'mad_no_post', 'El post no existe.', array( 'status' => 404 ) );
		}

		$revision_id = null;
		if ( post_type_supports( $post->post_type, 'revisions' ) ) {
			// La revisión copia lo que ya hay en la BD: sin KSES, o saldría
			// mutilada en páginas con <style>/<script> y no serviría de nada.
			$revision_id = MAD_Post_Writer::without_kses(
				function () use ( $post_id ) {
					return wp_save_post_revision( $post_id );
				}
			);
		}

		// Guardamos también los metadatos, que las revisiones no cubren.
		$ref = self::make_ref( 'post-' . $post_id, 'post' );
		$dir = self::ensure_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$meta = array(
			'original'    => 'post:' . $post_id,
			'type'        => 'post',
			'post_id'     => (int) $post_id,
			'revision_id' => $revision_id ? (int) $revision_id : null,
			'post'        => $post->to_array(),
			'meta'        => get_post_meta( $post_id ),
			'created'     => current_time( 'mysql', true ),
		);

		file_put_contents( trailingslashit( $dir ) . $ref . '.json', wp_json_encode( $meta ) ); // phpcs:ignore

		return $ref;
	}

	/**
	 * Restaura un post desde un snapshot, contenido y metadatos incluidos.
	 */
	public static function restore_post( $ref ) {
		$dir = self::ensure_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$path = trailingslashit( $dir ) . basename( $ref ) . '.json';
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'mad_backup_missing', 'No existe ese snapshot.', array( 'status' => 404 ) );
		}

		$meta = json_decode( file_get_contents( $path ), true ); // phpcs:ignore
		if ( ! is_array( $meta ) || 'post' !== ( $meta['type'] ?? '' ) ) {
			return new WP_Error( 'mad_backup_corrupt', 'Snapshot no válido.' );
		}

		$post_array = $meta['post'];
		unset( $post_array['post_modified'], $post_array['post_modified_gmt'] );

		// Restaurar es devolver lo que ya estuvo guardado: va en crudo. Si
		// pasara por KSES (como hasta 0.1.4) devolvería la página igual de
		// rota que el cambio que se quiere deshacer.
		$result = MAD_Post_Writer::update( $post_array, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Metadatos: reponemos el estado exacto que había.
		if ( ! empty( $meta['meta'] ) && is_array( $meta['meta'] ) ) {
			$current = get_post_meta( $meta['post_id'] );
			foreach ( array_keys( $current ) as $key ) {
				delete_post_meta( $meta['post_id'], $key );
			}
			foreach ( $meta['meta'] as $key => $values ) {
				foreach ( (array) $values as $value ) {
					// add_post_meta() quita barras: sin wp_slash el JSON de
					// Elementor (_elementor_data) volvía roto del rollback.
					add_post_meta( $meta['post_id'], $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}
		}

		return array( 'restored' => 'post:' . $meta['post_id'], 'action' => 'reverted' );
	}

	/**
	 * Referencia legible y única para una copia.
	 */
	private static function make_ref( $subject, $kind ) {
		$slug = sanitize_file_name( basename( (string) $subject ) );
		$slug = $slug ? $slug : 'item';
		return sprintf( '%s_%s_%s_%s', gmdate( 'Ymd-His' ), $kind, $slug, substr( md5( $subject . wp_rand() ), 0, 8 ) );
	}
}
