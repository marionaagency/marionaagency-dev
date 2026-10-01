<?php
/**
 * Cliente del hub.
 *
 * Esto es lo que hace que instalar el plugin baste: al activarse, la web
 * llama al hub de la agencia, se presenta y entrega su token. A partir de
 * ese momento aparece en la lista de webs sin tocar nada más.
 *
 * El secreto de agencia va compilado en el plugin (o definido en wp-config)
 * y es lo único que impide que cualquiera registre una web ajena.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Hub {

	const OPTION_URL        = 'mad_hub_url';
	const OPTION_SECRET     = 'mad_hub_secret';
	const OPTION_STATE      = 'mad_hub_state';
	const OPTION_SITE_TOKEN = 'mad_hub_site_token';

	public static function init() {
		add_action( 'admin_post_mad_hub_register', array( __CLASS__, 'handle_manual_register' ) );

		// Red de seguridad: si el alta falló al activar (hub caído, red
		// bloqueada), se reintenta al entrar en el escritorio. Sin esto la
		// web dependería de wp-cron, que solo corre si alguien la visita.
		add_action( 'admin_init', array( __CLASS__, 'maybe_retry' ) );
	}

	/**
	 * Reintento discreto desde el admin, como mucho una vez cada 5 minutos.
	 */
	public static function maybe_retry() {
		if ( ! self::is_configured() || MAD_DISABLED ) {
			return;
		}

		$state = self::get_state();
		if ( 'registered' === ( $state['status'] ?? '' ) ) {
			return;
		}

		if ( get_transient( 'mad_hub_retry_lock' ) ) {
			return;
		}
		set_transient( 'mad_hub_retry_lock', 1, 5 * MINUTE_IN_SECONDS );

		self::heartbeat();
	}

	public static function hub_url() {
		if ( defined( 'MAD_HUB_URL' ) && MAD_HUB_URL ) {
			return untrailingslashit( MAD_HUB_URL );
		}
		return untrailingslashit( (string) get_option( self::OPTION_URL, '' ) );
	}

	public static function hub_secret() {
		if ( defined( 'MAD_HUB_SECRET' ) && MAD_HUB_SECRET ) {
			return (string) MAD_HUB_SECRET;
		}
		return (string) get_option( self::OPTION_SECRET, '' );
	}

	public static function is_configured() {
		return self::hub_url() && self::hub_secret();
	}

	/**
	 * Copia a la base de datos la configuración que viene en el zip.
	 *
	 * Sin esto, cualquier actualización que reemplace includes/config.php
	 * dejaría la web sin saber a qué hub pertenece: seguiría funcionando
	 * pero desaparecería del panel. Con esto, el fichero es una comodidad,
	 * no una dependencia.
	 */
	public static function persist_config() {
		if ( defined( 'MAD_HUB_URL' ) && MAD_HUB_URL && ! get_option( self::OPTION_URL ) ) {
			update_option( self::OPTION_URL, untrailingslashit( MAD_HUB_URL ), false );
		}
		if ( defined( 'MAD_HUB_SECRET' ) && MAD_HUB_SECRET && ! get_option( self::OPTION_SECRET ) ) {
			update_option( self::OPTION_SECRET, (string) MAD_HUB_SECRET, false );
		}
	}

	/**
	 * Se ejecuta al activar y una vez al día. Idempotente: si la web ya está
	 * registrada, simplemente refresca los metadatos y la marca como viva.
	 */
	public static function heartbeat( $timeout = 15 ) {
		if ( ! self::is_configured() || MAD_DISABLED ) {
			return;
		}

		$token = self::site_token();
		if ( is_wp_error( $token ) ) {
			self::set_state( 'error', $token->get_error_message() );
			return;
		}

		// Antes de presentarnos, asegurarnos de que se nos puede contestar.
		if ( ! MAD_Bootstrap::is_ok() ) {
			MAD_Bootstrap::ensure();
		}

		$payload = array(
			'site_url'       => home_url(),
			'admin_url'      => admin_url(),
			'name'           => get_bloginfo( 'name' ),
			'api_base'       => rest_url( MAD_NS ),
			'token'          => $token,
			'plugin_version' => MAD_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'is_multisite'   => is_multisite(),
			'locale'         => get_locale(),
			'capabilities'   => self::capabilities(),
			// Cómo está el puente en esta web. El hub lo necesita para saber
			// si puede hablarle con normalidad o si hay que mirar algo.
			'bridge'         => MAD_Bootstrap::get_state(),
			'auth_fallback'  => MAD_Bootstrap::FALLBACK_HEADER,
			'timestamp'      => time(),
		);

		$response = self::post( '/register', $payload, $timeout );

		if ( is_wp_error( $response ) ) {
			self::set_state( 'error', $response->get_error_message() );
			return;
		}

		self::set_state( 'registered', 'Registrada correctamente en el hub.' );
	}

	/**
	 * Avisa al hub de que esta web deja de estar disponible.
	 */
	public static function deregister() {
		if ( ! self::is_configured() ) {
			return;
		}

		self::post(
			'/deregister',
			array(
				'site_url'  => home_url(),
				'timestamp' => time(),
			)
		);

		self::set_state( 'inactive', 'Plugin desactivado.' );
	}

	/**
	 * Qué sabe hacer esta web. El hub lo usa para no ofrecer herramientas
	 * de Woo en una web que no tiene Woo.
	 */
	public static function capabilities() {
		$caps = array( 'core', 'content', 'media', 'files', 'db', 'debug', 'blocks' );

		if ( defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ) ) {
			$caps[] = 'elementor';
		}
		if ( function_exists( 'et_get_theme_version' ) || defined( 'ET_BUILDER_VERSION' ) ) {
			$caps[] = 'divi';
		}
		if ( class_exists( 'ACF' ) || function_exists( 'get_field' ) ) {
			$caps[] = 'acf';
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$caps[] = 'woocommerce';
		}

		return $caps;
	}

	/**
	 * Token dedicado al hub. Se crea una sola vez y se guarda en claro,
	 * porque la web necesita poder reenviarlo en cada heartbeat.
	 *
	 * Compensación consciente: quien tenga acceso a la BD ya tiene la web.
	 */
	private static function site_token() {
		$stored = get_option( self::OPTION_SITE_TOKEN, '' );
		if ( $stored ) {
			return $stored;
		}

		$created = MAD_Auth::create_token( 'Hub MarionaAgency', MAD_Auth::default_scopes() );
		if ( empty( $created['secret'] ) ) {
			return new WP_Error( 'mad_token_failed', 'No se pudo generar el token para el hub.' );
		}

		update_option( self::OPTION_SITE_TOKEN, $created['secret'], false );
		return $created['secret'];
	}

	/**
	 * POST firmado al hub. La firma HMAC evita que alguien que espíe la URL
	 * del hub pueda registrar webs falsas sin conocer el secreto.
	 */
	private static function post( $path, $payload, $timeout = 15 ) {
		$url    = self::hub_url() . $path;
		$secret = self::hub_secret();
		$body   = wp_json_encode( $payload );
		$sig    = hash_hmac( 'sha256', $body, $secret );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => (int) $timeout,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'X-MAD-Signature' => $sig,
					'X-MAD-Site'      => home_url(),
				),
				'body'      => $body,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mad_hub_http_' . $code,
				sprintf( 'El hub respondió %d: %s', $code, wp_remote_retrieve_body( $response ) )
			);
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function set_state( $status, $message ) {
		update_option(
			self::OPTION_STATE,
			array(
				'status'     => $status,
				'message'    => $message,
				'checked_at' => current_time( 'mysql', true ),
			),
			false
		);
	}

	/**
	 * Botón «registrar ahora» del panel.
	 */
	public static function handle_manual_register() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'mad_hub_register' );

		self::heartbeat();

		wp_safe_redirect( add_query_arg( 'mad_notice', 'hub', admin_url( 'options-general.php?page=marionaagency-dev' ) ) );
		exit;
	}
}
