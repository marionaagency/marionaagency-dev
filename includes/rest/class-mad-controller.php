<?php
/**
 * Base de todos los controladores REST.
 *
 * Centraliza autenticación, comprobación de scope, auditoría y el modo
 * dry_run, para que cada endpoint concreto solo tenga que ocuparse de su
 * lógica de negocio.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

abstract class MAD_Controller {

	/** @var string */
	protected $namespace = MAD_NS;

	/** Scope exigido por la ruta que se está sirviendo. */
	private $pending_scope = 'read';

	abstract public function register_routes();

	/**
	 * Registra una ruta aplicando toda la maquinaria común.
	 *
	 * @param string   $path     Ruta relativa, p. ej. '/posts'.
	 * @param string   $methods  'GET', 'POST', 'GET, POST'…
	 * @param callable $callback Manejador.
	 * @param array    $options  scope, mutating, args.
	 */
	protected function add( $path, $methods, $callback, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'scope'    => 'read',
				'mutating' => false,
				'args'     => array(),
				'public'   => false,
			)
		);

		if ( $options['mutating'] ) {
			$options['args']['dry_run'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Si es true, calcula el efecto pero no escribe nada.',
			);
		}

		register_rest_route(
			$this->namespace,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => $this->wrap( $callback, $options ),
				'permission_callback' => $options['public']
					? '__return_true'
					: $this->permission_for( $options['scope'] ),
				'args'                => $options['args'],
			)
		);
	}

	/**
	 * Envuelve el manejador con cronómetro, captura de errores y auditoría.
	 */
	private function wrap( $callback, $options ) {
		return function ( WP_REST_Request $request ) use ( $callback, $options ) {
			MAD_Audit::start_timer();

			try {
				$result = call_user_func( $callback, $request );
			} catch ( Throwable $e ) {
				// Un fallo del manejador no debe devolver una traza al cliente.
				$result = new WP_Error(
					'mad_exception',
					$e->getMessage(),
					array( 'status' => 500 )
				);
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[MAD] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore
				}
			}

			$status = 200;
			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			} elseif ( $result instanceof WP_REST_Response ) {
				$status = $result->get_status();
			}

			$dry = $options['mutating'] && $request->get_param( 'dry_run' );

			MAD_Audit::log(
				array(
					'method'     => $request->get_method(),
					'route'      => $request->get_route(),
					'status'     => $status,
					'mutating'   => $options['mutating'] && ! $dry ? 1 : 0,
					'summary'    => $this->summarise( $result, $dry ),
					'payload'    => $request->get_params(),
					'backup_ref' => $this->extract_backup_ref( $result ),
				)
			);

			return $result;
		};
	}

	private function summarise( $result, $dry ) {
		if ( is_wp_error( $result ) ) {
			return 'ERROR ' . $result->get_error_code() . ': ' . $result->get_error_message();
		}

		$data = $result instanceof WP_REST_Response ? $result->get_data() : $result;

		if ( is_array( $data ) && isset( $data['summary'] ) ) {
			return ( $dry ? '[SIMULACIÓN] ' : '' ) . (string) $data['summary'];
		}

		return $dry ? '[SIMULACIÓN] OK' : 'OK';
	}

	private function extract_backup_ref( $result ) {
		$data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
		if ( is_array( $data ) && ! empty( $data['backup_ref'] ) ) {
			return (string) $data['backup_ref'];
		}
		return null;
	}

	/**
	 * Devuelve el permission_callback para un scope dado.
	 */
	private function permission_for( $scope ) {
		return function ( WP_REST_Request $request ) use ( $scope ) {
			$auth = MAD_Auth::authenticate( $request );
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}
			return MAD_Auth::require_scope( $scope );
		};
	}

	// ------------------------------------------------------------- respuestas

	/**
	 * Respuesta correcta con envoltorio homogéneo.
	 */
	protected function ok( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Respuesta de simulación: describe qué habría pasado.
	 */
	protected function dry( $summary, $extra = array() ) {
		return $this->ok(
			array_merge(
				array(
					'dry_run' => true,
					'applied' => false,
					'summary' => $summary,
				),
				$extra
			)
		);
	}

	protected function is_dry( WP_REST_Request $request ) {
		return (bool) $request->get_param( 'dry_run' );
	}

	/**
	 * ¿Pide esta petición guardar HTML crudo? Devuelve true/false, o
	 * WP_Error si lo pide sin permiso content:raw.
	 */
	protected function raw_requested( WP_REST_Request $request ) {
		if ( ! $request->get_param( 'raw' ) ) {
			return false;
		}
		$allowed = MAD_Auth::require_scope( 'content:raw' );
		return is_wp_error( $allowed ) ? $allowed : true;
	}

	/**
	 * Definición común del parámetro raw para rutas de escritura.
	 */
	protected static function raw_arg() {
		return array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Guardar HTML crudo (style, script, formularios) sin que WordPress lo elimine. Requiere content:raw.',
		);
	}

	protected function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Formato compacto y estable de un post, pensado para que el modelo
	 * entienda el contenido sin tener que pedir tres endpoints más.
	 */
	protected function shape_post( $post, $with_content = true ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$data = array(
			'id'        => (int) $post->ID,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'url'       => get_permalink( $post ),
			'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
			'author'    => (int) $post->post_author,
			'parent'    => (int) $post->post_parent,
			'menu_order'=> (int) $post->menu_order,
			'template'  => get_page_template_slug( $post->ID ),
			'created'   => $post->post_date_gmt,
			'modified'  => $post->post_modified_gmt,
			'builder'   => MAD_Route_Builders::detect_builder( $post->ID ),
		);

		if ( $with_content ) {
			$data['content'] = $post->post_content;
			$data['excerpt'] = $post->post_excerpt;
		} else {
			$data['excerpt'] = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
		}

		return $data;
	}
}
