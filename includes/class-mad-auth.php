<?php
/**
 * Autenticación por token con scopes.
 *
 * Los tokens se guardan SIEMPRE hasheados (sha256). El secreto en claro se
 * muestra una única vez, en el momento de crearlo. Si se pierde, se revoca y
 * se genera otro: no hay forma de recuperarlo desde la base de datos.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Auth {

	const OPTION_TOKENS   = 'mad_tokens';
	const OPTION_ALLOWLIST = 'mad_ip_allowlist';
	const OPTION_RATE     = 'mad_rate_limit';
	const TOKEN_PREFIX    = 'mad_';

	/** Contexto de la petición en curso, para que la auditoría sepa quién actúa. */
	private static $current = null;

	/**
	 * Scopes disponibles, de menor a mayor privilegio.
	 *
	 * read    – leer cualquier cosa que no sea un secreto
	 * content – crear/editar posts, páginas, términos, menús, media
	 * content:raw – guardar HTML crudo (style, script, formularios) sin que
	 *           KSES lo elimine. Implica content. Lo tienen también, sin
	 *           marcarlo, los tokens con admin o con content+files: quien
	 *           puede escribir PHP en wp-content ya puede más que esto.
	 * files   – leer y escribir ficheros dentro de wp-content
	 * db      – SELECT libre sobre la base de datos
	 * db:write– UPDATE/INSERT/DELETE (implica db)
	 * admin   – opciones, plugins, usuarios, ajustes del propio puente
	 */
	public static function all_scopes() {
		return array( 'read', 'content', 'content:raw', 'files', 'db', 'db:write', 'admin' );
	}

	public static function default_scopes() {
		return array( 'read', 'content', 'files', 'db' );
	}

	/**
	 * Genera un token nuevo. Devuelve el registro + el secreto en claro,
	 * que es la única vez que existirá fuera del cliente.
	 *
	 * @param string   $label  Nombre legible.
	 * @param string[] $scopes Permisos concedidos.
	 * @param int      $ttl    Segundos de vida. 0 = no caduca.
	 * @return array{id:string,secret:string,record:array}
	 */
	public static function create_token( $label, $scopes = array(), $ttl = 0 ) {
		$scopes = array_values( array_intersect( (array) $scopes, self::all_scopes() ) );
		if ( empty( $scopes ) ) {
			$scopes = array( 'read' );
		}

		$id     = wp_generate_password( 12, false, false );
		$secret = self::TOKEN_PREFIX . $id . '_' . bin2hex( random_bytes( 32 ) );

		$record = array(
			'id'         => $id,
			'label'      => sanitize_text_field( $label ),
			'hash'       => hash( 'sha256', $secret ),
			'scopes'     => $scopes,
			'created_at' => current_time( 'mysql', true ),
			'expires_at' => $ttl > 0 ? gmdate( 'Y-m-d H:i:s', time() + $ttl ) : null,
			'last_used'  => null,
			'last_ip'    => null,
			'uses'       => 0,
		);

		$tokens         = self::get_tokens();
		$tokens[ $id ]  = $record;
		update_option( self::OPTION_TOKENS, $tokens, false );

		return array(
			'id'     => $id,
			'secret' => $secret,
			'record' => $record,
		);
	}

	public static function revoke_token( $id ) {
		$tokens = self::get_tokens();
		if ( ! isset( $tokens[ $id ] ) ) {
			return false;
		}
		unset( $tokens[ $id ] );
		update_option( self::OPTION_TOKENS, $tokens, false );
		return true;
	}

	public static function get_tokens() {
		$tokens = get_option( self::OPTION_TOKENS, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	public static function has_tokens() {
		return ! empty( self::get_tokens() );
	}

	/**
	 * Valida la petición entrante. Devuelve el registro del token o WP_Error.
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	public static function authenticate( $request ) {
		if ( MAD_DISABLED ) {
			return new WP_Error(
				'mad_disabled',
				'El puente está desactivado por MAD_DISABLED en wp-config.php.',
				array( 'status' => 503 )
			);
		}

		if ( ! self::is_secure() ) {
			return new WP_Error(
				'mad_insecure',
				'Este endpoint solo acepta HTTPS.',
				array( 'status' => 403 )
			);
		}

		$ip = self::client_ip();
		if ( ! self::ip_allowed( $ip ) ) {
			return new WP_Error(
				'mad_ip_blocked',
				'IP no autorizada.',
				array( 'status' => 403 )
			);
		}

		$secret = self::extract_bearer( $request );
		if ( ! $secret ) {
			return new WP_Error(
				'mad_no_token',
				'No ha llegado ningún token. Si el cliente lo envió, este servidor está descartando la cabecera '
				. 'Authorization: reenvía la misma petición con la cabecera X-MAD-Token, o entra en Ajustes → '
				. 'MarionaAgency Dev y pulsa «Comprobar puente» para que el plugin lo repare solo.',
				array( 'status' => 401 )
			);
		}

		$hash   = hash( 'sha256', $secret );
		$tokens = self::get_tokens();
		$found  = null;

		// Comparación en tiempo constante contra todos los tokens, para no
		// filtrar por temporización cuál existe y cuál no.
		foreach ( $tokens as $record ) {
			if ( hash_equals( (string) $record['hash'], $hash ) ) {
				$found = $record;
			}
		}

		if ( ! $found ) {
			self::penalise( $ip );
			return new WP_Error( 'mad_bad_token', 'Token no válido.', array( 'status' => 401 ) );
		}

		if ( ! empty( $found['expires_at'] ) && strtotime( $found['expires_at'] ) < time() ) {
			return new WP_Error( 'mad_token_expired', 'Token caducado.', array( 'status' => 401 ) );
		}

		$limited = self::rate_limit( $found['id'] );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		self::touch( $found['id'], $ip );
		self::$current = $found;

		return $found;
	}

	/**
	 * Comprueba que el token en curso tiene un scope concreto.
	 */
	public static function require_scope( $scope ) {
		$token = self::$current;
		if ( ! $token ) {
			return new WP_Error( 'mad_no_context', 'Sin contexto de autenticación.', array( 'status' => 401 ) );
		}

		$scopes = (array) $token['scopes'];

		// admin abre todas las puertas; db:write implica db.
		if ( in_array( 'admin', $scopes, true ) ) {
			return true;
		}
		if ( 'db' === $scope && in_array( 'db:write', $scopes, true ) ) {
			return true;
		}
		// content:raw implica content.
		if ( 'content' === $scope && in_array( 'content:raw', $scopes, true ) ) {
			return true;
		}
		// content+files ya permite escribir PHP en wp-content: negarle HTML
		// crudo en el contenido no protegería nada y solo añadiría fricción.
		if ( 'content:raw' === $scope && in_array( 'content', $scopes, true ) && in_array( 'files', $scopes, true ) ) {
			return true;
		}
		if ( in_array( $scope, $scopes, true ) ) {
			return true;
		}

		return new WP_Error(
			'mad_forbidden_scope',
			sprintf( 'Este token no tiene el permiso «%s». Tiene: %s.', $scope, implode( ', ', $scopes ) ),
			array( 'status' => 403 )
		);
	}

	public static function current_token() {
		return self::$current;
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Saca el token de la petición.
	 *
	 * La cabecera Authorization es el camino principal, pero hay servidores
	 * que la descartan antes de que PHP la vea (Apache con CGI/FastCGI,
	 * Plesk con proxy nginx, algunos WAF). Por eso el hub manda siempre
	 * además X-MAD-Token, que nadie filtra: si Authorization no llega, el
	 * puente sigue funcionando en vez de devolver 401 sin explicación.
	 */
	private static function extract_bearer( $request ) {
		// 1. Cabecera propia. Es la que nunca se pierde, así que va primero.
		$own = $request->get_header( 'x_mad_token' );
		if ( ! $own ) {
			$own = $request->get_header( 'x-mad-token' );
		}
		if ( ! $own && ! empty( $_SERVER['HTTP_X_MAD_TOKEN'] ) ) {
			$own = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MAD_TOKEN'] ) );
		}
		if ( $own ) {
			return trim( preg_replace( '/^bearer\s+/i', '', (string) $own ) );
		}

		// 2. Authorization, por todas las vías por las que puede sobrevivir.
		$header = $request->get_header( 'authorization' );

		if ( ! $header ) {
			foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION' ) as $key ) {
				if ( ! empty( $_SERVER[ $key ] ) ) {
					$header = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
					break;
				}
			}
		}

		if ( ! $header && function_exists( 'apache_request_headers' ) ) {
			$headers = array_change_key_case( (array) apache_request_headers(), CASE_LOWER );
			$header  = isset( $headers['authorization'] ) ? $headers['authorization'] : '';
		}

		if ( ! $header || stripos( $header, 'bearer ' ) !== 0 ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	private static function is_secure() {
		if ( is_ssl() ) {
			return true;
		}
		// Detrás de Cloudflare o un balanceador, is_ssl() miente.
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_CF_VISITOR'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ) ), 'https' ) ) {
			return true;
		}
		// Escape para entornos locales de desarrollo.
		return defined( 'MAD_ALLOW_HTTP' ) && MAD_ALLOW_HTTP;
	}

	public static function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$parts = explode( ',', $value );
			$ip    = trim( $parts[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '0.0.0.0';
	}

	private static function ip_allowed( $ip ) {
		$list = get_option( self::OPTION_ALLOWLIST, array() );
		if ( empty( $list ) || ! is_array( $list ) ) {
			return true; // Sin lista definida, no filtramos.
		}
		return in_array( $ip, $list, true );
	}

	/**
	 * Límite por token: 120 peticiones por minuto por defecto.
	 */
	private static function rate_limit( $token_id ) {
		$max = (int) get_option( self::OPTION_RATE, 120 );
		if ( $max <= 0 ) {
			return true;
		}

		$key   = 'mad_rl_' . $token_id . '_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return new WP_Error(
				'mad_rate_limited',
				sprintf( 'Límite de %d peticiones por minuto superado.', $max ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Frena la fuerza bruta: tras 10 fallos desde una IP, la bloquea 15 min.
	 */
	private static function penalise( $ip ) {
		$key   = 'mad_fail_' . md5( $ip );
		$fails = (int) get_transient( $key );
		set_transient( $key, $fails + 1, 15 * MINUTE_IN_SECONDS );

		if ( $fails + 1 >= 10 ) {
			// Espera creciente para que el coste del ataque suba.
			usleep( min( 2000000, 200000 * ( $fails + 1 ) ) );
		}
	}

	private static function touch( $id, $ip ) {
		$tokens = self::get_tokens();
		if ( ! isset( $tokens[ $id ] ) ) {
			return;
		}
		$tokens[ $id ]['last_used'] = current_time( 'mysql', true );
		$tokens[ $id ]['last_ip']   = $ip;
		$tokens[ $id ]['uses']      = (int) $tokens[ $id ]['uses'] + 1;
		update_option( self::OPTION_TOKENS, $tokens, false );
	}
}
