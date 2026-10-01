<?php
/**
 * Pantalla de ajustes: estado, tokens, hub, auditoría y copias.
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

class MAD_Admin {

	const SLUG = 'marionaagency-dev';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mad_create_token', array( __CLASS__, 'handle_create_token' ) );
		add_action( 'admin_post_mad_revoke_token', array( __CLASS__, 'handle_revoke_token' ) );
		add_action( 'admin_post_mad_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_filter( 'plugin_action_links_' . MAD_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">Ajustes</a>', admin_url( 'options-general.php?page=' . self::SLUG ) )
		);
		return $links;
	}

	public static function menu() {
		add_options_page(
			'MarionaAgency Dev',
			'MarionaAgency Dev',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}

		$tokens    = MAD_Auth::get_tokens();
		$hub       = MAD_Hub::get_state();
		$new_token = get_transient( 'mad_first_token' );
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'estado'; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $new_token ) {
			delete_transient( 'mad_first_token' );
		}
		?>
		<div class="wrap">
			<h1>MarionaAgency Dev <span style="font-size:13px;color:#666;font-weight:400;">v<?php echo esc_html( MAD_VERSION ); ?></span></h1>

			<?php if ( MAD_DISABLED ) : ?>
				<div class="notice notice-error"><p>
					<strong>El puente está desactivado.</strong> Hay <code>define( 'MAD_DISABLED', true );</code> en wp-config.php.
					Ninguna petición remota será atendida hasta que se quite.
				</p></div>
			<?php endif; ?>

			<?php if ( $new_token ) : ?>
				<div class="notice notice-success">
					<p><strong>Token generado.</strong> Cópialo ahora: no se volverá a mostrar.</p>
					<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $new_token ); ?>" onclick="this.select()"></p>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php
				$tabs = array(
					'estado'    => 'Estado',
					'tokens'    => 'Tokens',
					'auditoria' => 'Auditoría',
					'copias'    => 'Copias',
				);
				foreach ( $tabs as $key => $label ) {
					printf(
						'<a href="%s" class="nav-tab %s">%s</a>',
						esc_url( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=' . $key ) ),
						$tab === $key ? 'nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</h2>

			<?php
			switch ( $tab ) {
				case 'tokens':
					self::tab_tokens( $tokens );
					break;
				case 'auditoria':
					self::tab_audit();
					break;
				case 'copias':
					self::tab_backups();
					break;
				default:
					self::tab_status( $hub, $tokens );
			}
			?>
		</div>
		<?php
	}

	private static function tab_status( $hub, $tokens ) {
		$status  = $hub['status'] ?? 'unknown';
		$colours = array(
			'registered' => '#00a32a',
			'error'      => '#d63638',
			'inactive'   => '#dba617',
		);
		?>
		<h2>Conexión</h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<tr>
					<td style="width:220px"><strong>Endpoint de detección</strong></td>
					<td><code><?php echo esc_html( rest_url( MAD_NS . '/ping' ) ); ?></code></td>
				</tr>
				<tr>
					<td><strong>Base de la API</strong></td>
					<td><code><?php echo esc_html( rest_url( MAD_NS ) ); ?></code></td>
				</tr>
				<tr>
					<td><strong>Hub</strong></td>
					<td>
						<?php if ( MAD_Hub::is_configured() ) : ?>
							<code><?php echo esc_html( MAD_Hub::hub_url() ); ?></code>
							<span style="color:<?php echo esc_attr( $colours[ $status ] ?? '#666' ); ?>">
								● <?php echo esc_html( $hub['message'] ?? 'Sin comprobar' ); ?>
							</span>
							<?php if ( ! empty( $hub['checked_at'] ) ) : ?>
								<br><small>Última comprobación: <?php echo esc_html( $hub['checked_at'] ); ?> UTC</small>
							<?php endif; ?>
						<?php else : ?>
							<em>Sin configurar.</em> Define <code>MAD_HUB_URL</code> y <code>MAD_HUB_SECRET</code> en wp-config.php,
							o rellénalos abajo.
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong>Puente</strong></td>
					<td>
						<?php
						$bridge  = MAD_Bootstrap::get_state();
						$bstatus = $bridge['status'] ?? '';
						$bcolour = in_array( $bstatus, array( 'ok', 'repaired' ), true ) ? '#00a32a' : ( in_array( $bstatus, array( 'unknown', 'blind', 'pending' ), true ) ? '#dba617' : '#d63638' );
						?>
						<span style="color:<?php echo esc_attr( $bcolour ); ?>">●</span>
						<?php echo esc_html( $bridge['message'] ?? 'Sin comprobar todavía.' ); ?>
						<a class="button button-small" style="margin-left:8px"
							href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mad_bridge_check' ), 'mad_bridge_check' ) ); ?>">
							Comprobar puente
						</a>
					</td>
				</tr>
				<tr>
					<td><strong>Capacidades detectadas</strong></td>
					<td><code><?php echo esc_html( implode( ', ', MAD_Hub::capabilities() ) ); ?></code></td>
				</tr>
				<tr>
					<td><strong>Tokens activos</strong></td>
					<td><?php echo (int) count( $tokens ); ?></td>
				</tr>
				<tr>
					<td><strong>HTTPS</strong></td>
					<td>
						<?php if ( is_ssl() ) : ?>
							<span style="color:#00a32a">● Activo</span>
						<?php else : ?>
							<span style="color:#d63638">● Sin HTTPS — la API rechazará todas las peticiones</span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mad_hub_register' ), 'mad_hub_register' ) ); ?>" class="button">
				Registrar en el hub ahora
			</a>
		</p>

		<h2>Ajustes</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mad_save_settings' ); ?>
			<input type="hidden" name="action" value="mad_save_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mad_hub_url">URL del hub</label></th>
					<td>
						<input type="url" id="mad_hub_url" name="mad_hub_url" class="regular-text"
							value="<?php echo esc_attr( get_option( MAD_Hub::OPTION_URL, '' ) ); ?>"
							<?php disabled( defined( 'MAD_HUB_URL' ) && MAD_HUB_URL ); ?>>
						<?php if ( defined( 'MAD_HUB_URL' ) && MAD_HUB_URL ) : ?>
							<p class="description">Fijado en wp-config.php.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mad_hub_secret">Secreto de agencia</label></th>
					<td>
						<input type="password" id="mad_hub_secret" name="mad_hub_secret" class="regular-text"
							value="<?php echo esc_attr( get_option( MAD_Hub::OPTION_SECRET, '' ) ? '••••••••••••' : '' ); ?>"
							<?php disabled( defined( 'MAD_HUB_SECRET' ) && MAD_HUB_SECRET ); ?>>
						<p class="description">Compartido por todas las webs de la agencia. Mejor en wp-config.php que aquí.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mad_rate_limit">Límite por minuto</label></th>
					<td>
						<input type="number" id="mad_rate_limit" name="mad_rate_limit" class="small-text" min="0"
							value="<?php echo esc_attr( get_option( MAD_Auth::OPTION_RATE, 120 ) ); ?>">
						<p class="description">Peticiones por token y minuto. 0 desactiva el límite.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mad_ip_allowlist">IPs permitidas</label></th>
					<td>
						<textarea id="mad_ip_allowlist" name="mad_ip_allowlist" rows="3" class="large-text code"
							placeholder="Una por línea. En blanco = sin restricción."><?php
							echo esc_textarea( implode( "\n", (array) get_option( MAD_Auth::OPTION_ALLOWLIST, array() ) ) );
						?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mad_audit_retention">Retención de auditoría</label></th>
					<td>
						<input type="number" id="mad_audit_retention" name="mad_audit_retention" class="small-text" min="0"
							value="<?php echo esc_attr( get_option( MAD_Audit::OPTION_KEEP, 90 ) ); ?>"> días
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar' ); ?>
		</form>
		<?php
	}

	private static function tab_tokens( $tokens ) {
		?>
		<h2>Tokens</h2>
		<p>Cada token lleva sus propios permisos. Se guardan hasheados: si pierdes uno, revócalo y crea otro.</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Nombre</th><th>Permisos</th><th>Creado</th><th>Último uso</th><th>Usos</th><th></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $tokens ) ) : ?>
				<tr><td colspan="6"><em>No hay ningún token.</em></td></tr>
			<?php endif; ?>
			<?php foreach ( $tokens as $token ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $token['label'] ); ?></strong><br><code style="font-size:11px"><?php echo esc_html( $token['id'] ); ?></code></td>
					<td><code><?php echo esc_html( implode( ', ', (array) $token['scopes'] ) ); ?></code></td>
					<td><?php echo esc_html( $token['created_at'] ); ?></td>
					<td><?php echo esc_html( $token['last_used'] ?: '—' ); ?><br><small><?php echo esc_html( $token['last_ip'] ?: '' ); ?></small></td>
					<td><?php echo (int) $token['uses']; ?></td>
					<td>
						<a class="button button-small" style="color:#d63638"
							onclick="return confirm('¿Revocar este token? Las integraciones que lo usen dejarán de funcionar.')"
							href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mad_revoke_token&id=' . rawurlencode( $token['id'] ) ), 'mad_revoke_token' ) ); ?>">
							Revocar
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3>Crear token</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mad_create_token' ); ?>
			<input type="hidden" name="action" value="mad_create_token">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mad_label">Nombre</label></th>
					<td><input type="text" id="mad_label" name="label" class="regular-text" required placeholder="Claude Code – portátil"></td>
				</tr>
				<tr>
					<th scope="row">Permisos</th>
					<td>
						<?php
						$descriptions = array(
							'read'     => 'Leer contenido, ajustes y diagnóstico',
							'content'  => 'Crear y editar contenido, medios y menús',
							'content:raw' => 'Guardar HTML crudo (style, script, formularios) — solo webs con maquetación embebida. Incluido si el token tiene content + files',
							'files'    => 'Leer y escribir ficheros dentro de wp-content',
							'db'       => 'Consultas SELECT sobre la base de datos',
							'db:write' => 'UPDATE / INSERT / DELETE (requiere confirmación por consulta)',
							'admin'    => 'Opciones del sitio y todo lo anterior',
						);
						foreach ( $descriptions as $scope => $description ) :
							?>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>"
									<?php checked( in_array( $scope, MAD_Auth::default_scopes(), true ) ); ?>>
								<code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $description ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mad_ttl">Caducidad</label></th>
					<td>
						<select id="mad_ttl" name="ttl">
							<option value="0">No caduca</option>
							<option value="2592000">30 días</option>
							<option value="7776000">90 días</option>
							<option value="31536000">1 año</option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Generar token' ); ?>
		</form>
		<?php
	}

	private static function tab_audit() {
		$entries = MAD_Audit::recent( 100 );
		?>
		<h2>Auditoría</h2>
		<p>Últimas <?php echo (int) count( $entries ); ?> peticiones. Las que modifican algo aparecen resaltadas.</p>
		<table class="widefat striped">
			<thead>
				<tr><th>Fecha (UTC)</th><th>Token</th><th>Petición</th><th>Estado</th><th>Resultado</th><th>ms</th></tr>
			</thead>
			<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr><td colspan="6"><em>Todavía no hay actividad.</em></td></tr>
			<?php endif; ?>
			<?php foreach ( $entries as $entry ) : ?>
				<tr<?php echo $entry['mutating'] ? ' style="background:#fcf9e8"' : ''; ?>>
					<td><?php echo esc_html( $entry['created_at'] ); ?></td>
					<td><?php echo esc_html( $entry['token_label'] ); ?><br><small><?php echo esc_html( $entry['ip'] ); ?></small></td>
					<td><code><?php echo esc_html( $entry['method'] . ' ' . $entry['route'] ); ?></code></td>
					<td><?php echo esc_html( $entry['status'] ); ?></td>
					<td><?php echo esc_html( $entry['summary'] ); ?></td>
					<td><?php echo (int) $entry['duration_ms']; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function tab_backups() {
		$backups = MAD_Safety::list_backups( 100 );
		?>
		<h2>Copias de seguridad</h2>
		<p>Cada modificación deja una copia aquí. Para deshacer, pásale la referencia a <code>POST /rollback</code>.</p>
		<table class="widefat striped">
			<thead><tr><th>Fecha</th><th>Tipo</th><th>Original</th><th>Referencia</th></tr></thead>
			<tbody>
			<?php if ( empty( $backups ) ) : ?>
				<tr><td colspan="4"><em>Sin copias todavía.</em></td></tr>
			<?php endif; ?>
			<?php foreach ( $backups as $backup ) : ?>
				<tr>
					<td><?php echo esc_html( $backup['created'] ?? '' ); ?></td>
					<td><?php echo esc_html( $backup['type'] ?? '' ); ?></td>
					<td><code style="font-size:11px"><?php echo esc_html( $backup['original'] ?? '' ); ?></code></td>
					<td><code style="font-size:11px"><?php echo esc_html( $backup['ref'] ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------- acciones

	public static function handle_create_token() {
		self::guard( 'mad_create_token' );

		$created = MAD_Auth::create_token(
			isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : 'Sin nombre',
			isset( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['scopes'] ) ) : array(),
			isset( $_POST['ttl'] ) ? (int) $_POST['ttl'] : 0
		);

		set_transient( 'mad_first_token', $created['secret'], 5 * MINUTE_IN_SECONDS );
		self::redirect( 'tokens' );
	}

	public static function handle_revoke_token() {
		self::guard( 'mad_revoke_token' );

		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		MAD_Auth::revoke_token( $id );

		self::redirect( 'tokens' );
	}

	public static function handle_save_settings() {
		self::guard( 'mad_save_settings' );

		if ( isset( $_POST['mad_hub_url'] ) ) {
			update_option( MAD_Hub::OPTION_URL, esc_url_raw( wp_unslash( $_POST['mad_hub_url'] ) ), false );
		}

		// Solo sobrescribimos el secreto si han escrito uno nuevo de verdad.
		if ( isset( $_POST['mad_hub_secret'] ) ) {
			$secret = sanitize_text_field( wp_unslash( $_POST['mad_hub_secret'] ) );
			if ( $secret && false === strpos( $secret, '••' ) ) {
				update_option( MAD_Hub::OPTION_SECRET, $secret, false );
			}
		}

		if ( isset( $_POST['mad_rate_limit'] ) ) {
			update_option( MAD_Auth::OPTION_RATE, max( 0, (int) $_POST['mad_rate_limit'] ), false );
		}

		if ( isset( $_POST['mad_audit_retention'] ) ) {
			update_option( MAD_Audit::OPTION_KEEP, max( 0, (int) $_POST['mad_audit_retention'] ), false );
		}

		if ( isset( $_POST['mad_ip_allowlist'] ) ) {
			$raw  = sanitize_textarea_field( wp_unslash( $_POST['mad_ip_allowlist'] ) );
			$list = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ), static fn( $ip ) => (bool) filter_var( $ip, FILTER_VALIDATE_IP ) ) );
			update_option( MAD_Auth::OPTION_ALLOWLIST, $list, false );
		}

		self::redirect( 'estado' );
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( $nonce );
	}

	private static function redirect( $tab ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}
}
