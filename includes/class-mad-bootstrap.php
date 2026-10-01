<?php
/**
 * Puesta en marcha automática del puente.
 *
 * Existe para que instalar el plugin sea de verdad «subir, activar y ya
 * está». Al activarse, la web se llama a sí misma y comprueba si la
 * cabecera Authorization sobrevive al servidor. Si no sobrevive —el caso
 * habitual en Apache con CGI/FastCGI y en Plesk detrás de nginx— repara
 * el .htaccess ella sola, verifica que la reparación ha funcionado y que
 * la web sigue en pie, y solo se rinde después de agotar las tres
 * variantes conocidas.
 *
 * Nada de esto se le pide al instalador. Si algo no se puede arreglar,
 * queda escrito en el estado del puente y viaja al hub, para que el
 * problema aparezca en el panel en vez de descubrirse a la primera
 * llamada fallida.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Bootstrap {

	const OPTION_STATE = 'mad_bridge_state';
	const MARKER_START = '# BEGIN MarionaAgency Dev';
	const MARKER_END   = '# END MarionaAgency Dev';

	/** Cabecera propia que acompaña siempre al Bearer. Ningún servidor la filtra. */
	const FALLBACK_HEADER = 'X-MAD-Token';

	/** Segundos que como mucho puede durar la comprobación en la activación. */
	const BUDGET_ACTIVACION = 15;

	public static function init() {
		add_action( 'mad_bridge_check', array( __CLASS__, 'daily_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_mad_bridge_check', array( __CLASS__, 'handle_manual_check' ) );

		// Si la activación se quedó a medias por falta de tiempo, se termina
		// aquí, en la siguiente carga del escritorio, sin que nadie lo pida.
		add_action( 'admin_init', array( __CLASS__, 'continue_repair' ) );
	}

	/**
	 * Repaso diario. Siempre a fondo: un plugin de caché o una restauración
	 * pueden reescribir el .htaccess y llevarse por delante la reparación.
	 */
	public static function daily_check() {
		self::ensure( true, 30 );
	}

	/**
	 * Continúa una reparación pendiente. Como mucho una vez cada 5 minutos,
	 * para no penalizar la navegación por el escritorio.
	 */
	public static function continue_repair() {
		if ( MAD_DISABLED || self::is_ok() ) {
			return;
		}

		$status = self::get_state()['status'] ?? '';
		if ( 'pending' !== $status && 'unknown' !== $status ) {
			return; // 'manual' ya se dio por imposible: no insistimos en cada pantalla.
		}

		if ( get_transient( 'mad_bridge_lock' ) ) {
			return;
		}
		set_transient( 'mad_bridge_lock', 1, 5 * MINUTE_IN_SECONDS );

		self::ensure( true, 25 );
	}

	/**
	 * Comprueba el puente y lo repara si hace falta.
	 *
	 * @param bool $force Repetir aunque ya conste como operativo.
	 * @return array Estado resultante.
	 */
	public static function ensure( $force = false, $budget = 0 ) {
		$state = self::get_state();

		if ( ! $force && 'ok' === ( $state['status'] ?? '' ) ) {
			return $state;
		}

		$budget = $budget > 0 ? (int) $budget : self::BUDGET_ACTIVACION;

		$probe = self::probe();

		if ( true === $probe ) {
			return self::set_state( 'ok', 'La cabecera Authorization llega a PHP. Puente operativo.' );
		}

		if ( null === $probe ) {
			// La web no puede llamarse a sí misma: firewall que no deja salir
			// a la propia IP, Cloudflare delante, DNS interno. No sabemos si
			// la cabecera llega, y quedarnos quietos deja la web a medias.
			return self::repair_a_ciegas();
		}

		return self::repair( $budget );
	}

	/**
	 * Llamada a sí misma con una cabecera Authorization de prueba.
	 *
	 * @return bool|null true llega, false no llega, null no se pudo comprobar.
	 */
	public static function probe() {
		$response = wp_remote_get(
			add_query_arg( 'mad_probe', time(), rest_url( MAD_NS . '/diagnose' ) ),
			array(
				'timeout'   => 6,
				'sslverify' => false, // Certificados internos: aquí solo hablamos con nosotros mismos.
				'headers'   => array(
					'Authorization'     => 'Bearer mad-probe',
					'X-MAD-Auth-Probe'  => '1',
					'Cache-Control'     => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['environment']['auth_header_received'] ) ) {
			return null;
		}

		return (bool) $body['environment']['auth_header_received'];
	}

	// ------------------------------------------------------------ reparación

	/**
	 * Aplica las variantes conocidas, de la más inocua a la más agresiva,
	 * verificando después de cada una. Revierte si la web se resiente.
	 */
	private static function repair( $budget = 15 ) {
		$empezado = microtime( true );
		$path     = self::htaccess_path();

		if ( ! $path ) {
			return self::set_state(
				'manual',
				'La cabecera Authorization no llega y no hay .htaccess que reparar (nginx o LiteSpeed sin Apache). '
				. 'El puente seguirá funcionando por la cabecera ' . self::FALLBACK_HEADER . '.'
			);
		}

		$original = file_exists( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions

		// Copia previa antes de tocar nada, igual que en cualquier otra escritura.
		$backup = file_exists( $path ) ? MAD_Safety::backup_file( $path ) : '';

		foreach ( self::variants() as $name => $block ) {
			// Nunca dejamos colgada la pantalla que nos ha llamado. Lo que no
			// dé tiempo aquí se termina en la siguiente carga del escritorio.
			if ( microtime( true ) - $empezado > $budget ) {
				self::restore( $path, $original );

				if ( ! wp_next_scheduled( 'mad_bridge_check' ) ) {
					wp_schedule_single_event( time() + 300, 'mad_bridge_check' );
				}

				return self::set_state(
					'pending',
					'Reparación del puente a medias por falta de tiempo. Se retomará sola en unos minutos.'
				);
			}

			if ( ! self::write_block( $path, $block ) ) {
				continue;
			}

			// Primero: ¿sigue la web en pie? Una directiva no soportada por
			// esta versión de Apache devuelve 500 en toda la web.
			if ( ! self::site_alive() ) {
				self::restore( $path, $original );
				continue;
			}

			if ( true === self::probe() ) {
				return self::set_state(
					'repaired',
					sprintf( 'Puente reparado solo (%s). La cabecera Authorization ya llega a PHP.', $name ),
					array( 'variant' => $name, 'backup' => is_string( $backup ) ? $backup : '' )
				);
			}
		}

		// Ninguna variante ha servido: dejamos el fichero como estaba.
		self::restore( $path, $original );

		return self::set_state(
			'manual',
			'La cabecera Authorization se pierde y el .htaccess no lo arregla. Suele ser el proxy de Plesk o un WAF. '
			. 'Mientras tanto el puente funciona por la cabecera ' . self::FALLBACK_HEADER . '.'
		);
	}

	/**
	 * Cuando no podemos comprobar nada, aplicamos la reparación estándar.
	 *
	 * El bloque de reescritura va dentro de `<IfModule mod_rewrite.c>`: en el
	 * peor caso no hace nada, y en el caso habitual —Apache con CGI— es
	 * justo lo que faltaba. Preferimos eso a dejar la web registrada en el
	 * hub pero incapaz de contestar.
	 */
	private static function repair_a_ciegas() {
		$path = self::htaccess_path();

		if ( ! $path ) {
			return self::set_state(
				'unknown',
				'No se ha podido comprobar el puente desde la propia web y no hay .htaccess que preparar. '
				. 'Compruébalo desde fuera con /wp-json/mad/v1/diagnose.'
			);
		}

		$variantes = self::variants();
		$escrito   = self::write_block( $path, $variantes['rewrite'] );

		return self::set_state(
			$escrito ? 'blind' : 'unknown',
			$escrito
				? 'La web no puede comprobarse a sí misma (loopback bloqueado). Se ha aplicado la reparación estándar del .htaccess por precaución: verifica desde fuera con /wp-json/mad/v1/diagnose.'
				: 'No se ha podido comprobar el puente desde la propia web ni escribir en el .htaccess. Compruébalo desde fuera.'
		);
	}

	/**
	 * Las tres formas conocidas de recuperar la cabecera, en orden de riesgo.
	 *
	 * El bloque va SIEMPRE al principio del fichero: si fuera detrás del de
	 * WordPress no llegaría a evaluarse nunca, porque la regla que reescribe
	 * a index.php termina el ciclo con [L].
	 */
	private static function variants() {
		return array(
			'rewrite' => "<IfModule mod_rewrite.c>\n"
				. "RewriteEngine On\n"
				. "RewriteCond %{HTTP:Authorization} ^(.+)$\n"
				. "RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]\n"
				. "</IfModule>\n",

			'rewrite+setenvif' => "<IfModule mod_rewrite.c>\n"
				. "RewriteEngine On\n"
				. "RewriteCond %{HTTP:Authorization} ^(.+)$\n"
				. "RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]\n"
				. "</IfModule>\n"
				. "<IfModule mod_setenvif.c>\n"
				. "SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1\n"
				. "</IfModule>\n",

			'cgipassauth' => "<IfModule mod_rewrite.c>\n"
				. "RewriteEngine On\n"
				. "RewriteCond %{HTTP:Authorization} ^(.+)$\n"
				. "RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]\n"
				. "</IfModule>\n"
				. "<IfModule mod_setenvif.c>\n"
				. "SetEnvIf Authorization \"(.*)\" HTTP_AUTHORIZATION=\$1\n"
				. "</IfModule>\n"
				. "CGIPassAuth On\n",
		);
	}

	/**
	 * Escribe nuestro bloque al principio del .htaccess, sustituyendo el
	 * anterior si ya había uno.
	 */
	private static function write_block( $path, $block ) {
		$current = file_exists( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$clean   = self::strip_block( $current );

		$contents = self::MARKER_START . "\n"
			. "# Recupera la cabecera Authorization, que este servidor descarta.\n"
			. "# Generado automáticamente por el plugin. No editar a mano.\n"
			. $block
			. self::MARKER_END . "\n\n"
			. ltrim( $clean, "\n" );

		return (bool) @file_put_contents( $path, $contents ); // phpcs:ignore
	}

	private static function strip_block( $contents ) {
		$pattern = '/' . preg_quote( self::MARKER_START, '/' ) . '.*?' . preg_quote( self::MARKER_END, '/' ) . "\n*/s";
		return preg_replace( $pattern, '', $contents );
	}

	private static function restore( $path, $original ) {
		if ( '' === $original ) {
			@unlink( $path ); // phpcs:ignore
			return;
		}
		@file_put_contents( $path, $original ); // phpcs:ignore
	}

	/**
	 * ¿La portada sigue respondiendo? Cualquier 5xx significa que la última
	 * directiva no le ha sentado bien a este Apache.
	 */
	private static function site_alive() {
		$response = wp_remote_get(
			add_query_arg( 'mad_alive', time(), home_url( '/' ) ),
			array( 'timeout' => 6, 'sslverify' => false, 'redirection' => 2 )
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return (int) wp_remote_retrieve_response_code( $response ) < 500;
	}

	private static function htaccess_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$path = trailingslashit( $home ) . '.htaccess';

		// Solo tiene sentido si Apache está por medio y podemos escribir.
		$server   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		$apacheish = false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' ) || file_exists( $path );

		if ( ! $apacheish ) {
			return '';
		}

		if ( file_exists( $path ) && ! is_writable( $path ) ) {
			return '';
		}
		if ( ! file_exists( $path ) && ! is_writable( $home ) ) {
			return '';
		}

		return $path;
	}

	// ---------------------------------------------------------------- estado

	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * ¿Puede el hub hablar con esta web con normalidad?
	 */
	public static function is_ok() {
		$status = self::get_state()['status'] ?? '';
		return in_array( $status, array( 'ok', 'repaired' ), true );
	}

	private static function set_state( $status, $message, $extra = array() ) {
		$state = array_merge(
			array(
				'status'     => $status,
				'message'    => $message,
				'checked_at' => current_time( 'mysql', true ),
			),
			$extra
		);

		update_option( self::OPTION_STATE, $state, false );

		return $state;
	}

	// --------------------------------------------------------------- interfaz

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = self::get_state();
		if ( empty( $state ) || self::is_ok() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>MarionaAgency Dev:</strong> %s <a href="%s">Volver a comprobar</a></p></div>',
			esc_html( $state['message'] ?? '' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mad_bridge_check' ), 'mad_bridge_check' ) )
		);
	}

	public static function handle_manual_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'mad_bridge_check' );

		self::ensure( true );
		MAD_Hub::heartbeat();

		wp_safe_redirect( admin_url( 'options-general.php?page=marionaagency-dev' ) );
		exit;
	}
}
