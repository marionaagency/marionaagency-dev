<?php
/**
 * Endpoints de núcleo: identificación, estado, auditoría y rollback.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_Core extends MAD_Controller {

	public function register_routes() {

		// Detección. Público a propósito: es lo que permite comprobar
		// «¿esta web lleva el plugin?» antes de tener credenciales.
		// Devuelve lo mínimo imprescindible, nada explotable.
		$this->add(
			'/ping',
			'GET',
			array( $this, 'ping' ),
			array( 'public' => true )
		);

		// Diagnóstico de entorno. También público: si el token no llega
		// porque el servidor se come la cabecera, un endpoint autenticado
		// no podría decírnoslo nunca.
		$this->add(
			'/diagnose',
			'GET',
			array( $this, 'diagnose' ),
			array( 'public' => true )
		);

		$this->add( '/site', 'GET', array( $this, 'site' ), array( 'scope' => 'read' ) );
		$this->add( '/health', 'GET', array( $this, 'health' ), array( 'scope' => 'read' ) );

		$this->add(
			'/audit',
			'GET',
			array( $this, 'audit' ),
			array(
				'scope' => 'read',
				'args'  => array(
					'limit'    => array( 'type' => 'integer', 'default' => 50 ),
					'mutating' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		$this->add( '/backups', 'GET', array( $this, 'backups' ), array( 'scope' => 'read' ) );

		$this->add(
			'/rollback',
			'POST',
			array( $this, 'rollback' ),
			array(
				'scope'    => 'files',
				'mutating' => true,
				'args'     => array(
					'ref' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	/**
	 * GET /wp-json/mad/v1/ping
	 */
	public function ping() {
		return $this->ok(
			array(
				'plugin'  => 'marionaagency-dev',
				'name'    => 'MarionaAgency Dev',
				'version' => MAD_VERSION,
				'ready'   => ! MAD_DISABLED && MAD_Auth::has_tokens(),
			)
		);
	}

	/**
	 * GET /wp-json/mad/v1/diagnose
	 *
	 * Responde a «¿por qué no puedo conectar con esta web?». Cubre los tres
	 * culpables habituales: la cabecera Authorization que se pierde en CGI,
	 * el WAF que bloquea /wp-json/, y HTTPS mal detectado tras un proxy.
	 *
	 * No revela nada explotable: solo dice si las cosas llegan, no su valor.
	 */
	public function diagnose( $request ) {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		$has_auth_header = (bool) $request->get_header( 'authorization' )
			|| ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
			|| ! empty( $_SERVER['HTTP_AUTHORIZATION'] );

		$behind_cloudflare = ! empty( $_SERVER['HTTP_CF_RAY'] );

		$modsecurity = in_array( 'mod_security', (array) ( function_exists( 'apache_get_modules' ) ? apache_get_modules() : array() ), true )
			|| ! empty( $_SERVER['HTTP_X_MOD_SECURITY'] );

		$problems = array();
		$advice   = array();
		$notes    = array();

		// No podemos concluir nada si el cliente no ha mandado la cabecera:
		// un navegador nunca la envía. Solo es un problema si el cliente
		// dice haberla enviado (lo indica con X-MAD-Auth-Probe).
		$probed = ! empty( $request->get_header( 'x-mad-auth-probe' ) );

		if ( $has_auth_header ) {
			$notes[] = 'La cabecera Authorization llega correctamente a PHP.';
		} elseif ( $probed ) {
			$problems[] = 'La cabecera Authorization se pierde antes de llegar a PHP.';
			$advice[]   = 'Añade a .htaccess: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1 — o en Plesk, activa «Proxy mode» o pasa a PHP-FPM.';
		} else {
			$notes[] = 'Esta petición no llevaba cabecera Authorization, así que no se ha podido comprobar. '
				. 'Para verificarlo, repite la llamada con: curl -H "Authorization: Bearer prueba" ' . rest_url( MAD_NS . '/diagnose' );
		}

		if ( ! is_ssl() && empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && empty( $_SERVER['HTTP_CF_VISITOR'] ) ) {
			$problems[] = 'La web no se ve como HTTPS desde PHP.';
			$advice[]   = 'Si hay proxy o Cloudflare delante, define en wp-config.php: $_SERVER["HTTPS"] = "on"; cuando HTTP_X_FORWARDED_PROTO sea https.';
		}

		if ( $modsecurity ) {
			$problems[] = 'ModSecurity está activo: puede rechazar la escritura de ficheros PHP.';
			$advice[]   = 'Usa encoding=base64 al escribir ficheros, o excluye /wp-json/mad/ de las reglas OWASP en Plesk.';
		}

		if ( $behind_cloudflare ) {
			$advice[] = 'Detrás de Cloudflare: crea una regla WAF de tipo Skip para la ruta /wp-json/mad/* y desactiva «Rate limiting» en esa ruta.';
		}

		if ( ! MAD_Auth::has_tokens() ) {
			$problems[] = 'No hay ningún token generado todavía.';
			$advice[]   = 'Ve a Ajustes → MarionaAgency Dev → Tokens y genera uno.';
		}

		return $this->ok(
			array(
				'plugin'       => 'marionaagency-dev',
				'version'      => MAD_VERSION,
				'ready'        => empty( $problems ),
				'summary'      => empty( $problems )
					? 'Entorno correcto: la web está lista para trabajar.'
					: sprintf( '%d problema(s) detectado(s).', count( $problems ) ),
				'problems'     => $problems,
				'advice'       => $advice,
				'notes'        => $notes,
				'environment'  => array(
					'auth_header_received' => $has_auth_header,
					'https_detected'       => is_ssl(),
					'behind_cloudflare'    => $behind_cloudflare,
					'modsecurity'          => $modsecurity,
					'server'               => $server,
					'php'                  => PHP_VERSION,
					'php_sapi'             => PHP_SAPI,
					'rest_url'             => rest_url( MAD_NS ),
					'permalinks_pretty'    => (bool) get_option( 'permalink_structure' ),
					'disabled'             => MAD_DISABLED,
				),
			)
		);
	}

	/**
	 * Retrato completo de la web. Es lo primero que conviene pedir al
	 * empezar a trabajar en un sitio nuevo.
	 */
	public function site() {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}

		return $this->ok(
			array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url(),
				'admin_url'   => admin_url(),
				'locale'      => get_locale(),
				'timezone'    => wp_timezone_string(),
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'multisite'   => is_multisite(),
				'permalinks'  => get_option( 'permalink_structure' ),
				'builders'    => MAD_Hub::capabilities(),
				'theme'       => array(
					'name'       => $theme->get( 'Name' ),
					'version'    => $theme->get( 'Version' ),
					'stylesheet' => get_stylesheet(),
					'template'   => get_template(),
					'is_child'   => (bool) $parent,
					'parent'     => $parent ? $parent->get( 'Name' ) : null,
					'dir'        => get_stylesheet_directory(),
				),
				'post_types'  => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
				'taxonomies'  => array_values( get_taxonomies( array( 'public' => true ), 'names' ) ),
				'counts'      => array(
					'posts'    => (int) wp_count_posts( 'post' )->publish,
					'pages'    => (int) wp_count_posts( 'page' )->publish,
					'media'    => (int) wp_count_posts( 'attachment' )->inherit,
					'users'    => (int) count_users()['total_users'],
					'comments' => (int) wp_count_comments()->approved,
				),
				'plugins'     => $plugins,
			)
		);
	}

	/**
	 * Señales de que algo va mal: actualizaciones, errores, espacio, cron.
	 */
	public function health() {
		global $wpdb;

		$issues = array();

		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$plugin_updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		$theme_updates  = function_exists( 'get_theme_updates' ) ? get_theme_updates() : array();
		$core_updates   = function_exists( 'get_core_updates' ) ? get_core_updates() : array();

		if ( count( $plugin_updates ) > 0 ) {
			$issues[] = sprintf( '%d plugin(s) con actualización pendiente.', count( $plugin_updates ) );
		}
		if ( count( $theme_updates ) > 0 ) {
			$issues[] = sprintf( '%d tema(s) con actualización pendiente.', count( $theme_updates ) );
		}

		$debug_log = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '';
		$log_size  = ( $debug_log && file_exists( $debug_log ) ) ? filesize( $debug_log ) : 0;
		if ( $log_size > 10 * MB_IN_BYTES ) {
			$issues[] = sprintf( 'debug.log ocupa %s: conviene revisarlo y vaciarlo.', size_format( $log_size ) );
		}

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$overdue       = 0;
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp < time() - HOUR_IN_SECONDS ) {
				$overdue += count( $hooks );
			}
		}
		if ( $overdue > 5 ) {
			$issues[] = sprintf( '%d tareas de cron atrasadas más de una hora.', $overdue );
		}

		$db_size = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
				DB_NAME
			)
		);

		return $this->ok(
			array(
				'status'          => empty( $issues ) ? 'ok' : 'attention',
				'issues'          => $issues,
				'updates'         => array(
					'core'    => ! empty( $core_updates ) && isset( $core_updates[0]->response ) && 'upgrade' === $core_updates[0]->response,
					'plugins' => array_keys( $plugin_updates ),
					'themes'  => array_keys( $theme_updates ),
				),
				'debug'           => array(
					'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
					'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
					'log_size'     => $log_size ? size_format( $log_size ) : null,
				),
				'cron'            => array(
					'disabled' => $cron_disabled,
					'overdue'  => $overdue,
				),
				'database_size'   => $db_size ? size_format( $db_size ) : null,
				'php_memory_limit'=> ini_get( 'memory_limit' ),
				'max_upload'      => size_format( wp_max_upload_size() ),
			)
		);
	}

	public function audit( $request ) {
		return $this->ok(
			array(
				'entries' => MAD_Audit::recent(
					$request->get_param( 'limit' ),
					$request->get_param( 'mutating' )
				),
			)
		);
	}

	public function backups() {
		return $this->ok( array( 'backups' => MAD_Safety::list_backups( 100 ) ) );
	}

	/**
	 * Deshace un cambio a partir de la referencia que devolvió la operación
	 * original (o la que aparece en /audit).
	 */
	public function rollback( $request ) {
		$ref = (string) $request->get_param( 'ref' );

		if ( $this->is_dry( $request ) ) {
			return $this->dry( sprintf( 'Se restauraría la copia «%s».', $ref ) );
		}

		// El prefijo de la referencia nos dice qué tipo de copia es.
		$result = ( false !== strpos( $ref, '_post_' ) )
			? MAD_Safety::restore_post( $ref )
			: MAD_Safety::restore_file( $ref );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->ok(
			array(
				'applied' => true,
				'summary' => sprintf( 'Restaurado %s (%s).', $result['restored'], $result['action'] ),
				'detail'  => $result,
			)
		);
	}
}
