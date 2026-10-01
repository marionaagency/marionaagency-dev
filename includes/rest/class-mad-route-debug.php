<?php
/**
 * Diagnóstico: logs, cron, constantes y entorno.
 *
 * Todo de solo lectura salvo el vaciado del log, que sí muta y por eso
 * exige scope de ficheros.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_Debug extends MAD_Controller {

	public function register_routes() {

		$this->add(
			'/debug/log',
			'GET',
			array( $this, 'log_tail' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'lines'  => array( 'type' => 'integer', 'default' => 100 ),
					'filter' => array( 'type' => 'string' ),
				),
			)
		);

		$this->add(
			'/debug/log',
			'DELETE',
			array( $this, 'clear_log' ),
			array( 'scope' => 'files', 'mutating' => true )
		);

		$this->add( '/debug/cron', 'GET', array( $this, 'cron' ), array( 'scope' => 'read' ) );
		$this->add( '/debug/constants', 'GET', array( $this, 'constants' ), array( 'scope' => 'read' ) );
		$this->add( '/debug/environment', 'GET', array( $this, 'environment' ), array( 'scope' => 'read' ) );

		$this->add(
			'/debug/hooks',
			'GET',
			array( $this, 'hooks' ),
			array(
				'scope' => 'read',
				'args'  => array( 'hook' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
	}

	/**
	 * Últimas líneas de debug.log, leídas desde el final para no cargar en
	 * memoria un fichero que puede pesar cientos de megas.
	 */
	public function log_tail( $request ) {
		$path = $this->log_path();

		if ( ! $path || ! file_exists( $path ) ) {
			return $this->ok(
				array(
					'exists'  => false,
					'summary' => 'No hay debug.log. Activa WP_DEBUG_LOG en wp-config.php para generarlo.',
					'lines'   => array(),
				)
			);
		}

		$wanted = max( 1, min( 2000, (int) $request->get_param( 'lines' ) ) );
		$filter = $request->get_param( 'filter' );
		$lines  = $this->tail( $path, $wanted );

		if ( $filter ) {
			$lines = array_values(
				array_filter(
					$lines,
					static function ( $line ) use ( $filter ) {
						return false !== stripos( $line, $filter );
					}
				)
			);
		}

		return $this->ok(
			array(
				'exists'   => true,
				'path'     => $path,
				'size'     => size_format( filesize( $path ) ),
				'modified' => gmdate( 'c', filemtime( $path ) ),
				'summary'  => sprintf( '%d línea(s) devueltas.', count( $lines ) ),
				'lines'    => $lines,
			)
		);
	}

	public function clear_log( $request ) {
		$path = $this->log_path();

		if ( ! $path || ! file_exists( $path ) ) {
			return $this->error( 'mad_no_log', 'No hay debug.log que vaciar.', 404 );
		}

		$size = filesize( $path );

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se vaciaría debug.log (%s).', size_format( $size ) ) );
		}

		$backup = MAD_Safety::backup_file( $path );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		file_put_contents( $path, '' ); // phpcs:ignore

		return $this->ok(
			array(
				'applied'    => true,
				'summary'    => sprintf( 'debug.log vaciado (liberados %s).', size_format( $size ) ),
				'backup_ref' => $backup,
			)
		);
	}

	public function cron() {
		$events = array();
		$now    = time();

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $instances ) {
				foreach ( $instances as $instance ) {
					$events[] = array(
						'hook'      => $hook,
						'next_run'  => gmdate( 'c', $timestamp ),
						'in_seconds'=> $timestamp - $now,
						'overdue'   => $timestamp < $now,
						'schedule'  => $instance['schedule'] ?: 'una vez',
						'args'      => $instance['args'],
					);
				}
			}
		}

		usort( $events, static fn( $a, $b ) => $a['in_seconds'] <=> $b['in_seconds'] );

		return $this->ok(
			array(
				'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'schedules' => array_keys( wp_get_schedules() ),
				'overdue'   => count( array_filter( $events, static fn( $e ) => $e['overdue'] ) ),
				'events'    => $events,
			)
		);
	}

	/**
	 * Constantes relevantes. Las que pueden contener secretos se muestran
	 * solo como «definida / no definida».
	 */
	public function constants() {
		$safe = array(
			'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG',
			'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_CACHE',
			'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'DISABLE_WP_CRON',
			'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS',
			'WP_ENVIRONMENT_TYPE', 'WP_HOME', 'WP_SITEURL', 'MULTISITE',
			'MAD_DISABLED', 'MAD_HUB_URL',
		);

		$secret = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'DB_PASSWORD', 'DB_USER', 'MAD_HUB_SECRET' );

		$out = array();
		foreach ( $safe as $name ) {
			$out[ $name ] = defined( $name ) ? constant( $name ) : null;
		}

		$defined_secrets = array();
		foreach ( $secret as $name ) {
			$defined_secrets[ $name ] = defined( $name ) ? 'definida' : 'no definida';
		}

		return $this->ok(
			array(
				'constants' => $out,
				'secrets'   => $defined_secrets,
			)
		);
	}

	public function environment() {
		global $wpdb;

		return $this->ok(
			array(
				'php'      => array(
					'version'          => PHP_VERSION,
					'memory_limit'     => ini_get( 'memory_limit' ),
					'max_execution'    => ini_get( 'max_execution_time' ),
					'post_max_size'    => ini_get( 'post_max_size' ),
					'upload_max'       => ini_get( 'upload_max_filesize' ),
					'extensions'       => get_loaded_extensions(),
				),
				'mysql'    => array(
					'version' => $wpdb->db_version(),
					'prefix'  => $wpdb->prefix,
					'charset' => $wpdb->charset,
				),
				'server'   => array(
					'software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : null,
					'os'       => PHP_OS_FAMILY,
				),
				'wordpress'=> array(
					'version'     => get_bloginfo( 'version' ),
					'multisite'   => is_multisite(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
					'https'       => is_ssl(),
				),
			)
		);
	}

	/**
	 * Qué funciones están enganchadas a un hook. Muy útil para entender por
	 * qué un tema o plugin se comporta de forma rara.
	 */
	public function hooks( $request ) {
		global $wp_filter;

		$hook = (string) $request->get_param( 'hook' );

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $this->ok( array( 'hook' => $hook, 'callbacks' => array(), 'summary' => 'Sin callbacks registrados.' ) );
		}

		$out = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$out[] = array(
					'priority' => $priority,
					'name'     => $this->callback_name( $callback['function'] ),
					'args'     => $callback['accepted_args'],
				);
			}
		}

		return $this->ok( array( 'hook' => $hook, 'callbacks' => $out ) );
	}

	// --------------------------------------------------------------- helpers

	private function log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		return defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : null;
	}

	/**
	 * Lee las últimas N líneas leyendo bloques desde el final del fichero.
	 */
	private function tail( $path, $wanted ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $handle ) {
			return array();
		}

		$buffer = '';
		$chunk  = 8192;
		$pos    = filesize( $path );

		while ( $pos > 0 && substr_count( $buffer, "\n" ) <= $wanted ) {
			$read = min( $chunk, $pos );
			$pos -= $read;
			fseek( $handle, $pos );
			$buffer = fread( $handle, $read ) . $buffer; // phpcs:ignore
		}

		fclose( $handle ); // phpcs:ignore

		$lines = explode( "\n", trim( $buffer ) );
		return array_slice( $lines, -$wanted );
	}

	private function callback_name( $function ) {
		if ( is_string( $function ) ) {
			return $function;
		}
		if ( is_array( $function ) ) {
			$class = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
			return $class . '::' . $function[1];
		}
		if ( $function instanceof Closure ) {
			$reflection = new ReflectionFunction( $function );
			return sprintf( 'Closure(%s:%d)', basename( $reflection->getFileName() ), $reflection->getStartLine() );
		}
		return 'desconocido';
	}
}
