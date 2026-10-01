<?php
/**
 * Actualización automática desde GitHub.
 *
 * Publicas una release en el repo de la agencia y las webs la ven en su
 * pantalla de plugins como cualquier otra actualización. Es lo que hace
 * viable mantener 30 instalaciones sin entrar en cada una.
 *
 * Repo privado: define MAD_GITHUB_TOKEN en wp-config.php con un PAT de
 * solo lectura.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Updater {

	/** Cambia esto por el repo real de la agencia. */
	const REPO = 'marionaagency/marionaagency-dev';

	const CACHE_KEY = 'mad_latest_release';

	/** Copia en memoria de la configuración de agencia mientras dura la actualización. */
	private static $carried_config = null;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );

		// La actualización reemplaza la carpeta entera, includes/config.php
		// incluido. Lo rescatamos antes y lo devolvemos después: sin esto,
		// una release publicada sin config dejaría fuera del hub a toda la
		// cartera de golpe.
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'carry_config' ), 10, 2 );
		add_filter( 'upgrader_post_install', array( __CLASS__, 'restore_config' ), 10, 3 );
	}

	public static function carry_config( $return, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || MAD_BASENAME !== $hook_extra['plugin'] ) {
			return $return;
		}

		$config = MAD_DIR . 'includes/config.php';
		if ( file_exists( $config ) ) {
			self::$carried_config = file_get_contents( $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		// Y por si acaso, también a la base de datos.
		MAD_Hub::persist_config();

		return $return;
	}

	public static function restore_config( $return, $hook_extra, $result ) {
		if ( empty( $hook_extra['plugin'] ) || MAD_BASENAME !== $hook_extra['plugin'] || null === self::$carried_config ) {
			return $return;
		}

		$destination = ! empty( $result['destination'] ) ? trailingslashit( $result['destination'] ) : trailingslashit( MAD_DIR );
		$config      = $destination . 'includes/config.php';

		// Solo si la versión nueva no trae la suya.
		if ( ! file_exists( $config ) ) {
			@file_put_contents( $config, self::$carried_config ); // phpcs:ignore
		}

		self::$carried_config = null;

		return $return;
	}

	/**
	 * Inyecta la actualización en el transient que WordPress consulta.
	 */
	public static function check( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::latest_release();
		if ( ! $release || version_compare( $release['version'], MAD_VERSION, '<=' ) ) {
			return $transient;
		}

		$transient->response[ MAD_BASENAME ] = (object) array(
			'slug'        => dirname( MAD_BASENAME ),
			'plugin'      => MAD_BASENAME,
			'new_version' => $release['version'],
			'package'     => $release['package'],
			'url'         => 'https://github.com/' . self::REPO,
			'tested'      => get_bloginfo( 'version' ),
		);

		return $transient;
	}

	/**
	 * Ficha del plugin en el modal «Ver detalles».
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( MAD_BASENAME ) !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'MarionaAgency Dev',
			'slug'          => dirname( MAD_BASENAME ),
			'version'       => $release['version'],
			'author'        => '<a href="https://marionaagency.com">MarionaAgency</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => 'Puente seguro entre Claude y esta web.',
				'changelog'   => wpautop( $release['notes'] ),
			),
		);
	}

	/**
	 * GitHub descomprime en «repo-1.2.3»; WordPress espera el slug del plugin.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $args = array() ) {
		if ( empty( $args['plugin'] ) || MAD_BASENAME !== $args['plugin'] ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . dirname( MAD_BASENAME );

		if ( $source === $desired ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Última release publicada, cacheada 6 horas para no gastar cuota de API.
	 */
	private static function latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'MarionaAgencyDev/' . MAD_VERSION,
		);

		if ( defined( 'MAD_GITHUB_TOKEN' ) && MAD_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . MAD_GITHUB_TOKEN;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array( 'timeout' => 15, 'headers' => $headers )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cacheamos el fallo un rato para no martillear GitHub.
			set_transient( self::CACHE_KEY, false, HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, false, HOUR_IN_SECONDS );
			return null;
		}

		// Preferimos el zip adjunto a la release; si no hay, el del tag.
		$package = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
		if ( ! $package ) {
			$package = $body['zipball_url'] ?? '';
		}

		$release = array(
			'version' => ltrim( $body['tag_name'], 'v' ),
			'package' => $package,
			'notes'   => $body['body'] ?? '',
		);

		set_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}
}
