<?php
/**
 * Acceso a ficheros, enjaulado dentro de wp-content.
 *
 * Reglas duras, no configurables desde fuera:
 *   - Solo se puede leer/escribir dentro de wp-content.
 *   - wp-config.php, wp-admin y wp-includes están fuera de alcance.
 *   - El propio plugin es de solo lectura (se actualiza vía GitHub).
 *   - El directorio de copias no se puede tocar.
 *   - Toda escritura hace backup y pasa validación de sintaxis.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_Files extends MAD_Controller {

	/** Extensiones que aceptamos escribir. */
	private $writable_extensions = array(
		'php', 'css', 'scss', 'js', 'json', 'txt', 'md', 'html', 'htm',
		'svg', 'po', 'pot', 'twig', 'xml', 'yml', 'yaml',
	);

	public function register_routes() {

		$this->add(
			'/files',
			'GET',
			array( $this, 'list_dir' ),
			array(
				'scope' => 'files',
				'args'  => array(
					'path'  => array( 'type' => 'string', 'default' => '' ),
					'depth' => array( 'type' => 'integer', 'default' => 1 ),
				),
			)
		);

		$this->add(
			'/files/read',
			'GET',
			array( $this, 'read_file' ),
			array(
				'scope' => 'files',
				'args'  => array(
					'path' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		$this->add(
			'/files/write',
			'POST',
			array( $this, 'write_file' ),
			array(
				'scope'    => 'files',
				'mutating' => true,
				'args'     => array(
					'path'     => array( 'type' => 'string', 'required' => true ),
					'content'  => array( 'type' => 'string', 'required' => true ),
					'lint'     => array( 'type' => 'boolean', 'default' => true ),
					'encoding' => array(
						'type'        => 'string',
						'default'     => 'plain',
						'enum'        => array( 'plain', 'base64' ),
						'description' => 'Usa base64 cuando el servidor lleva ModSecurity: rechaza los POST con código PHP en el cuerpo.',
					),
				),
			)
		);

		$this->add(
			'/files/delete',
			'POST',
			array( $this, 'delete_file' ),
			array(
				'scope'    => 'files',
				'mutating' => true,
				'args'     => array(
					'path' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		$this->add(
			'/files/search',
			'GET',
			array( $this, 'search' ),
			array(
				'scope' => 'files',
				'args'  => array(
					'query'     => array( 'type' => 'string', 'required' => true ),
					'path'      => array( 'type' => 'string', 'default' => '' ),
					'extension' => array( 'type' => 'string' ),
					'max'       => array( 'type' => 'integer', 'default' => 50 ),
				),
			)
		);

		// Atajo cómodo: el tema activo, que es donde se trabaja el 90 % del tiempo.
		$this->add( '/theme/files', 'GET', array( $this, 'theme_files' ), array( 'scope' => 'files' ) );
	}

	// ----------------------------------------------------------------- rutas

	public function list_dir( $request ) {
		$path = $this->resolve( $request->get_param( 'path' ), false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_dir( $path ) ) {
			return $this->error( 'mad_not_a_dir', 'Esa ruta no es un directorio.', 422 );
		}

		$depth = max( 1, min( 4, (int) $request->get_param( 'depth' ) ) );

		return $this->ok(
			array(
				'path'    => $this->relative( $path ),
				'entries' => $this->scan( $path, $depth ),
			)
		);
	}

	public function read_file( $request ) {
		$path = $this->resolve( $request->get_param( 'path' ), false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_file( $path ) ) {
			return $this->error( 'mad_not_found', 'No existe ese fichero.', 404 );
		}

		$size = filesize( $path );
		if ( $size > 2 * MB_IN_BYTES ) {
			return $this->error( 'mad_too_large', sprintf( 'El fichero ocupa %s; el límite de lectura son 2 MB.', size_format( $size ) ), 413 );
		}

		return $this->ok(
			array(
				'path'     => $this->relative( $path ),
				'absolute' => $path,
				'size'     => $size,
				'modified' => gmdate( 'c', filemtime( $path ) ),
				'lines'    => substr_count( file_get_contents( $path ), "\n" ) + 1, // phpcs:ignore
				'content'  => file_get_contents( $path ), // phpcs:ignore
			)
		);
	}

	public function write_file( $request ) {
		$raw     = (string) $request->get_param( 'path' );
		$content = (string) $request->get_param( 'content' );

		// Los WAF con reglas OWASP (habitual en Plesk) devuelven 403 ante un
		// POST que contiene «<?php». Mandar el contenido en base64 lo evita
		// sin desactivar el WAF de la web del cliente.
		if ( 'base64' === $request->get_param( 'encoding' ) ) {
			$decoded = base64_decode( $content, true );
			if ( false === $decoded ) {
				return $this->error( 'mad_bad_base64', 'El contenido no es base64 válido.', 422 );
			}
			$content = $decoded;
		}

		$path = $this->resolve( $raw, true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, $this->writable_extensions, true ) ) {
			return $this->error(
				'mad_extension_blocked',
				sprintf( 'No se permite escribir ficheros «.%s». Permitidas: %s.', $extension, implode( ', ', $this->writable_extensions ) ),
				403
			);
		}

		// Validamos ANTES de tocar nada. Un functions.php roto tumba la web.
		if ( $request->get_param( 'lint' ) ) {
			$lint = MAD_Safety::lint( $content, $extension );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
		}

		$exists = file_exists( $path );

		if ( $this->is_dry( $request ) ) {
			return $this->dry(
				sprintf( 'Se %s %s (%s).', $exists ? 'sobrescribiría' : 'crearía', $this->relative( $path ), size_format( strlen( $content ) ) ),
				array(
					'exists'     => $exists,
					'size_now'   => $exists ? filesize( $path ) : 0,
					'size_after' => strlen( $content ),
					'lint'       => 'ok',
				)
			);
		}

		$backup = MAD_Safety::backup_file( $path );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return $this->error( 'mad_mkdir_failed', 'No se pudo crear el directorio destino.', 500 );
		}

		$written = file_put_contents( $path, $content ); // phpcs:ignore
		if ( false === $written ) {
			return $this->error( 'mad_write_failed', 'No se pudo escribir. Revisa permisos del fichero.', 500 );
		}

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( '%s %s (%s).', $exists ? 'Sobrescrito' : 'Creado', $this->relative( $path ), size_format( $written ) ),
				'backup_ref' => $backup,
				'path'       => $this->relative( $path ),
				'bytes'      => $written,
			)
		);
	}

	public function delete_file( $request ) {
		$path = $this->resolve( $request->get_param( 'path' ), true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_file( $path ) ) {
			return $this->error( 'mad_not_found', 'No existe ese fichero.', 404 );
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se borraría %s.', $this->relative( $path ) ) );
		}

		$backup = MAD_Safety::backup_file( $path );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		if ( ! unlink( $path ) ) { // phpcs:ignore
			return $this->error( 'mad_delete_failed', 'No se pudo borrar el fichero.', 500 );
		}

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'Borrado %s.', $this->relative( $path ) ),
				'backup_ref' => $backup,
			)
		);
	}

	/**
	 * Busca una cadena por el árbol de ficheros. Es el equivalente a un grep
	 * y evita tener que descargar medio tema para encontrar una función.
	 */
	public function search( $request ) {
		$root = $this->resolve( $request->get_param( 'path' ), false );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$query     = (string) $request->get_param( 'query' );
		$extension = $request->get_param( 'extension' );
		$max       = max( 1, min( 200, (int) $request->get_param( 'max' ) ) );

		if ( strlen( $query ) < 2 ) {
			return $this->error( 'mad_query_short', 'La búsqueda necesita al menos 2 caracteres.', 422 );
		}

		$matches  = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( count( $matches ) >= $max ) {
				break;
			}
			if ( ! $file->isFile() || $file->getSize() > MB_IN_BYTES ) {
				continue;
			}
			if ( $extension && strtolower( $file->getExtension() ) !== strtolower( $extension ) ) {
				continue;
			}
			if ( $this->is_forbidden( $file->getPathname() ) ) {
				continue;
			}

			$lines = @file( $file->getPathname() ); // phpcs:ignore
			if ( ! is_array( $lines ) ) {
				continue;
			}

			foreach ( $lines as $number => $line ) {
				if ( false !== stripos( $line, $query ) ) {
					$matches[] = array(
						'path' => $this->relative( $file->getPathname() ),
						'line' => $number + 1,
						'text' => trim( substr( $line, 0, 300 ) ),
					);
					if ( count( $matches ) >= $max ) {
						break;
					}
				}
			}
		}

		return $this->ok(
			array(
				'query'     => $query,
				'matches'   => $matches,
				'truncated' => count( $matches ) >= $max,
			)
		);
	}

	public function theme_files() {
		$dir = get_stylesheet_directory();
		return $this->ok(
			array(
				'theme'   => wp_get_theme()->get( 'Name' ),
				'path'    => $this->relative( $dir ),
				'entries' => $this->scan( $dir, 2 ),
			)
		);
	}

	// --------------------------------------------------------------- la jaula

	/**
	 * Traduce una ruta relativa a absoluta y verifica que cae dentro de la
	 * jaula. Es el único sitio donde se decide qué es alcanzable.
	 *
	 * @param string $input     Ruta relativa a wp-content, o absoluta.
	 * @param bool   $for_write Si es para escritura, aplica reglas extra.
	 * @return string|WP_Error Ruta absoluta normalizada.
	 */
	private function resolve( $input, $for_write ) {
		$input = (string) $input;
		$root  = realpath( WP_CONTENT_DIR );

		if ( ! $root ) {
			return $this->error( 'mad_no_root', 'No se pudo resolver wp-content.', 500 );
		}

		// Rechazamos travesías antes de tocar el sistema de ficheros.
		if ( false !== strpos( $input, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $input ) ) {
			return $this->error( 'mad_path_traversal', 'Ruta no permitida.', 403 );
		}

		$candidate = ( '' === $input )
			? $root
			: ( 0 === strpos( $input, '/' ) ? $input : $root . '/' . ltrim( $input, '/' ) );

		// realpath falla para ficheros que aún no existen: resolvemos el padre.
		$resolved = realpath( $candidate );
		if ( false === $resolved ) {
			$parent = realpath( dirname( $candidate ) );
			if ( false === $parent ) {
				return $this->error( 'mad_bad_path', 'La ruta no existe y su directorio padre tampoco.', 404 );
			}
			$resolved = $parent . '/' . basename( $candidate );
		}

		// La comprobación que importa: ¿sigue dentro de wp-content?
		if ( 0 !== strpos( $resolved, $root ) ) {
			return $this->error(
				'mad_outside_jail',
				'Fuera de wp-content. Solo se puede trabajar dentro de temas, plugins, uploads y mu-plugins.',
				403
			);
		}

		if ( $this->is_forbidden( $resolved ) ) {
			return $this->error( 'mad_path_forbidden', 'Esa ruta está protegida.', 403 );
		}

		if ( $for_write && $this->is_read_only( $resolved ) ) {
			return $this->error(
				'mad_read_only',
				'Esa ruta es de solo lectura: el propio plugin y sus copias no se editan desde la API.',
				403
			);
		}

		return $resolved;
	}

	/**
	 * Nunca alcanzable, ni para leer.
	 */
	private function is_forbidden( $path ) {
		$forbidden = array(
			realpath( ABSPATH . 'wp-config.php' ),
			realpath( ABSPATH . 'wp-admin' ),
			realpath( ABSPATH . 'wp-includes' ),
		);

		foreach ( array_filter( $forbidden ) as $needle ) {
			if ( 0 === strpos( $path, $needle ) ) {
				return true;
			}
		}

		// Copias de seguridad: si se pudieran editar, el rollback sería papel mojado.
		$uploads = wp_upload_dir();
		$backups = realpath( trailingslashit( $uploads['basedir'] ) . MAD_Safety::BACKUP_DIR );
		if ( $backups && 0 === strpos( $path, $backups ) ) {
			return true;
		}

		// Ficheros de configuración del servidor.
		$basename = basename( $path );
		if ( in_array( $basename, array( '.htaccess', '.htpasswd', 'wp-config.php', '.env' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Legible pero no escribible.
	 */
	private function is_read_only( $path ) {
		$self = realpath( MAD_DIR );
		return $self && 0 === strpos( $path, $self );
	}

	private function relative( $path ) {
		$root = realpath( WP_CONTENT_DIR );
		return ltrim( str_replace( $root, '', $path ), '/' );
	}

	private function scan( $dir, $depth, $level = 0 ) {
		$entries = array();
		$handle  = @scandir( $dir ); // phpcs:ignore

		if ( ! is_array( $handle ) ) {
			return $entries;
		}

		foreach ( $handle as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$full = $dir . '/' . $name;
			if ( $this->is_forbidden( $full ) ) {
				continue;
			}

			$is_dir = is_dir( $full );
			$entry  = array(
				'name' => $name,
				'path' => $this->relative( $full ),
				'type' => $is_dir ? 'dir' : 'file',
			);

			if ( ! $is_dir ) {
				$entry['size']     = filesize( $full );
				$entry['modified'] = gmdate( 'c', filemtime( $full ) );
			} elseif ( $level + 1 < $depth ) {
				$entry['children'] = $this->scan( $full, $depth, $level + 1 );
			}

			$entries[] = $entry;
		}

		return $entries;
	}
}
