<?php
/**
 * Registro de auditoría.
 *
 * Toda petición que llega al puente queda anotada: quién, desde dónde, qué
 * tocó y con qué resultado. Las mutaciones guardan además la referencia al
 * backup, para poder deshacerlas desde el panel.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Audit {

	const TABLE       = 'mad_audit';
	const OPTION_KEEP = 'mad_audit_retention_days';

	/** @var float */
	private static $started = 0.0;

	public static function init() {
		add_action( 'mad_audit_prune', array( __CLASS__, 'prune' ) );
		if ( ! wp_next_scheduled( 'mad_audit_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mad_audit_prune' );
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install_table() {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			token_id VARCHAR(32) NOT NULL DEFAULT '',
			token_label VARCHAR(100) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			method VARCHAR(10) NOT NULL DEFAULT '',
			route VARCHAR(191) NOT NULL DEFAULT '',
			status SMALLINT NOT NULL DEFAULT 0,
			duration_ms INT NOT NULL DEFAULT 0,
			mutating TINYINT(1) NOT NULL DEFAULT 0,
			summary TEXT NULL,
			payload LONGTEXT NULL,
			backup_ref VARCHAR(191) NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY route (route),
			KEY mutating (mutating)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function start_timer() {
		self::$started = microtime( true );
	}

	/**
	 * Anota una petición.
	 *
	 * @param array $args Datos de la llamada.
	 * @return int ID de la fila.
	 */
	public static function log( $args ) {
		global $wpdb;

		$token = MAD_Auth::current_token();

		$defaults = array(
			'method'     => '',
			'route'      => '',
			'status'     => 200,
			'mutating'   => 0,
			'summary'    => '',
			'payload'    => array(),
			'backup_ref' => null,
		);
		$args = wp_parse_args( $args, $defaults );

		$duration = self::$started > 0 ? (int) round( ( microtime( true ) - self::$started ) * 1000 ) : 0;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'token_id'    => $token ? $token['id'] : '',
				'token_label' => $token ? $token['label'] : 'anónimo',
				'ip'          => MAD_Auth::client_ip(),
				'method'      => substr( (string) $args['method'], 0, 10 ),
				'route'       => substr( (string) $args['route'], 0, 191 ),
				'status'      => (int) $args['status'],
				'duration_ms' => $duration,
				'mutating'    => (int) $args['mutating'],
				'summary'     => (string) $args['summary'],
				'payload'     => wp_json_encode( self::redact( $args['payload'] ) ),
				'backup_ref'  => $args['backup_ref'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Nunca guardamos secretos en el log, ni aunque vengan en el payload.
	 */
	private static function redact( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		$sensitive = array( 'token', 'secret', 'password', 'pass', 'pwd', 'api_key', 'apikey', 'authorization', 'agency_secret', 'auth_key' );

		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$payload[ $key ] = self::redact( $value );
				continue;
			}
			foreach ( $sensitive as $needle ) {
				if ( false !== stripos( (string) $key, $needle ) ) {
					$payload[ $key ] = '«oculto»';
					break;
				}
			}
			// El contenido largo se trunca: el log no es un almacén de datos.
			if ( is_string( $payload[ $key ] ) && strlen( $payload[ $key ] ) > 2000 ) {
				$payload[ $key ] = substr( $payload[ $key ], 0, 2000 ) . '… [truncado]';
			}
		}

		return $payload;
	}

	/**
	 * Últimas entradas, para el panel y para el endpoint /audit.
	 */
	public static function recent( $limit = 50, $only_mutating = false ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 500, (int) $limit ) );

		if ( $only_mutating ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT * FROM {$table} WHERE mutating = 1 ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);
	}

	/**
	 * Poda las entradas viejas. Por defecto guardamos 90 días.
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) get_option( self::OPTION_KEEP, 90 );
		if ( $days <= 0 ) {
			return;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
		);
	}
}
