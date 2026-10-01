<?php
/**
 * Acceso a la base de datos.
 *
 * Lectura libre con scope «db». Escritura solo con «db:write», y aun así
 * pasando por un análisis previo que rechaza lo que no se puede deshacer
 * (DROP, TRUNCATE, ALTER) y exige confirmación explícita.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Route_DB extends MAD_Controller {

	/** Verbos de solo lectura. */
	private $read_verbs = array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' );

	/** Verbos de escritura permitidos con db:write. */
	private $write_verbs = array( 'UPDATE', 'INSERT', 'REPLACE', 'DELETE' );

	/** Nunca, con ningún scope: no hay forma de deshacerlos. */
	private $banned_verbs = array( 'DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'RENAME', 'GRANT', 'REVOKE', 'SET', 'LOCK', 'CALL', 'LOAD' );

	public function register_routes() {

		$this->add( '/db/tables', 'GET', array( $this, 'tables' ), array( 'scope' => 'db' ) );

		$this->add(
			'/db/describe/(?P<table>[A-Za-z0-9_]+)',
			'GET',
			array( $this, 'describe' ),
			array( 'scope' => 'db' )
		);

		$this->add(
			'/db/query',
			'POST',
			array( $this, 'query' ),
			array(
				'scope'    => 'db',
				'mutating' => true,
				'args'     => array(
					// Sin 'required': WordPress valida los parámetros antes que
					// el permiso, y un anónimo recibiría un 400 revelando la
					// forma del endpoint en vez de un 401 seco. Lo validamos
					// dentro del manejador, que sí corre después del token.
					'sql'     => array( 'type' => 'string' ),
					'limit'   => array( 'type' => 'integer', 'default' => 200 ),
					'confirm' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Obligatorio en true para ejecutar consultas de escritura.',
					),
				),
			)
		);
	}

	public function tables() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'    => $row['Name'],
				'rows'    => (int) $row['Rows'],
				'size'    => size_format( (int) $row['Data_length'] + (int) $row['Index_length'] ),
				'engine'  => $row['Engine'],
				'is_core' => 0 === strpos( $row['Name'], $wpdb->prefix ),
			);
		}

		return $this->ok( array( 'prefix' => $wpdb->prefix, 'tables' => $out ) );
	}

	public function describe( $request ) {
		global $wpdb;

		$table = $this->safe_table( $request['table'] );
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		$columns = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB
		$count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB

		return $this->ok(
			array(
				'table'   => $table,
				'rows'    => $count,
				'columns' => $columns,
			)
		);
	}

	public function query( $request ) {
		global $wpdb;

		$sql   = trim( (string) $request->get_param( 'sql' ) );
		$limit = max( 1, min( 1000, (int) $request->get_param( 'limit' ) ) );

		if ( '' === $sql ) {
			return $this->error( 'mad_missing_sql', 'Falta el parámetro «sql».', 400 );
		}

		$analysis = $this->analyse( $sql );
		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}

		// Escritura: exige scope específico y confirmación consciente.
		if ( $analysis['writes'] ) {
			$scope = MAD_Auth::require_scope( 'db:write' );
			if ( is_wp_error( $scope ) ) {
				return $scope;
			}

			if ( ! $request->get_param( 'confirm' ) && ! $this->is_dry( $request ) ) {
				return $this->error(
					'mad_confirm_required',
					sprintf(
						'Esta consulta es de escritura (%s). Envía confirm=true para ejecutarla, o dry_run=true para ver a cuántas filas afectaría.',
						$analysis['verb']
					),
					428
				);
			}
		}

		if ( $this->is_dry( $request ) ) {
			return $this->dry_estimate( $sql, $analysis );
		}

		if ( ! $analysis['writes'] ) {
			$sql = $this->apply_limit( $sql, $limit );

			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB

			if ( $wpdb->last_error ) {
				return $this->error( 'mad_sql_error', $wpdb->last_error, 422 );
			}

			return $this->ok(
				array(
					'summary'   => sprintf( '%d fila(s) devueltas.', is_array( $rows ) ? count( $rows ) : 0 ),
					'rows'      => $rows,
					'count'     => is_array( $rows ) ? count( $rows ) : 0,
					'truncated' => is_array( $rows ) && count( $rows ) >= $limit,
				)
			);
		}

		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB

		if ( false === $affected ) {
			return $this->error( 'mad_sql_error', $wpdb->last_error ?: 'La consulta falló.', 422 );
		}

		return $this->ok(
			array(
				'applied'  => true,
				'summary'  => sprintf( '%s ejecutado: %d fila(s) afectadas.', $analysis['verb'], $affected ),
				'affected' => (int) $affected,
				'insert_id'=> $wpdb->insert_id ?: null,
			)
		);
	}

	// --------------------------------------------------------------- análisis

	/**
	 * Decide si una consulta es aceptable y si escribe.
	 *
	 * No pretende ser un parser SQL completo: es una barrera conservadora.
	 * Ante la duda, rechaza.
	 */
	private function analyse( $sql ) {
		$stripped = $this->strip_comments( $sql );

		if ( '' === trim( $stripped ) ) {
			return $this->error( 'mad_empty_sql', 'La consulta está vacía.', 422 );
		}

		// Una consulta, una sentencia. Rechazamos apilar sentencias.
		$trimmed = rtrim( trim( $stripped ), ';' );
		if ( false !== strpos( $trimmed, ';' ) ) {
			return $this->error(
				'mad_multi_statement',
				'Solo se admite una sentencia por petición.',
				422
			);
		}

		if ( ! preg_match( '/^\s*([A-Za-z]+)/', $trimmed, $matches ) ) {
			return $this->error( 'mad_unparseable', 'No se reconoce el verbo de la consulta.', 422 );
		}

		$verb = strtoupper( $matches[1] );

		if ( in_array( $verb, $this->banned_verbs, true ) ) {
			return $this->error(
				'mad_verb_banned',
				sprintf( '«%s» no está permitido por esta vía: es un cambio estructural que el rollback no puede deshacer. Hazlo por phpMyAdmin con copia previa.', $verb ),
				403
			);
		}

		if ( in_array( $verb, $this->read_verbs, true ) ) {
			return array( 'verb' => $verb, 'writes' => false );
		}

		if ( in_array( $verb, $this->write_verbs, true ) ) {
			// Un DELETE o UPDATE sin WHERE vacía la tabla entera.
			if ( in_array( $verb, array( 'UPDATE', 'DELETE' ), true ) && ! preg_match( '/\bWHERE\b/i', $trimmed ) ) {
				return $this->error(
					'mad_no_where',
					sprintf( 'Un %s sin cláusula WHERE afectaría a toda la tabla. Añade un WHERE.', $verb ),
					422
				);
			}
			return array( 'verb' => $verb, 'writes' => true );
		}

		return $this->error( 'mad_verb_unknown', sprintf( 'Verbo «%s» no soportado.', $verb ), 422 );
	}

	/**
	 * Para una escritura en modo simulación, contamos las filas que tocaría
	 * convirtiendo la consulta en un SELECT COUNT(*) equivalente.
	 */
	private function dry_estimate( $sql, $analysis ) {
		global $wpdb;

		if ( ! $analysis['writes'] ) {
			return $this->dry( sprintf( 'Consulta de lectura (%s); no modifica nada.', $analysis['verb'] ) );
		}

		$count = null;

		if ( 'UPDATE' === $analysis['verb'] && preg_match( '/^\s*UPDATE\s+(.+?)\s+SET\s+.*?(\bWHERE\b.*)$/is', $sql, $m ) ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$m[1]} {$m[2]}" ); // phpcs:ignore WordPress.DB
		} elseif ( 'DELETE' === $analysis['verb'] && preg_match( '/^\s*DELETE\s+FROM\s+(.+?)\s*(\bWHERE\b.*)$/is', $sql, $m ) ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$m[1]} {$m[2]}" ); // phpcs:ignore WordPress.DB
		}

		return $this->dry(
			null === $count
				? sprintf( 'Se ejecutaría un %s. No se ha podido estimar el número de filas.', $analysis['verb'] )
				: sprintf( 'Se ejecutaría un %s sobre %d fila(s).', $analysis['verb'], (int) $count ),
			array( 'estimated_rows' => null === $count ? null : (int) $count )
		);
	}

	private function strip_comments( $sql ) {
		$sql = preg_replace( '#/\*.*?\*/#s', ' ', $sql );
		$sql = preg_replace( '/--[^\r\n]*/', ' ', $sql );
		$sql = preg_replace( '/#[^\r\n]*/', ' ', $sql );
		return (string) $sql;
	}

	/**
	 * Fuerza un LIMIT en los SELECT que no lo llevan, para no volcar tablas
	 * de un millón de filas en una respuesta HTTP.
	 */
	private function apply_limit( $sql, $limit ) {
		$trimmed = rtrim( trim( $sql ), ';' );

		if ( preg_match( '/\bLIMIT\s+\d+/i', $trimmed ) ) {
			return $trimmed;
		}
		if ( ! preg_match( '/^\s*SELECT\b/i', $trimmed ) ) {
			return $trimmed;
		}

		return $trimmed . ' LIMIT ' . (int) $limit;
	}

	private function safe_table( $table ) {
		global $wpdb;

		$table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table );
		if ( '' === $table ) {
			return $this->error( 'mad_bad_table', 'Nombre de tabla no válido.', 422 );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
		if ( ! $exists ) {
			return $this->error( 'mad_no_table', sprintf( 'La tabla «%s» no existe.', $table ), 404 );
		}

		return $table;
	}
}
