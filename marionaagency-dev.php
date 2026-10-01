<?php
/**
 * Plugin Name:       MarionaAgency Dev
 * Plugin URI:        https://marionaagency.com/mad
 * Description:       Puente seguro entre Claude y esta web. API REST completa con scopes, auditoría, backups automáticos y rollback. Se registra solo en el hub de la agencia.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MarionaAgency
 * Author URI:        https://marionaagency.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marionaagency-dev
 * Domain Path:       /languages
 *
 * @package MarionaAgencyDev
 */

defined( 'ABSPATH' ) || exit;

define( 'MAD_VERSION', '0.2.1' );
define( 'MAD_FILE', __FILE__ );
define( 'MAD_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAD_URL', plugin_dir_url( __FILE__ ) );
define( 'MAD_BASENAME', plugin_basename( __FILE__ ) );
define( 'MAD_NS', 'mad/v1' );

/**
 * Interruptor de emergencia. Añade esto a wp-config.php para cortar todo
 * acceso remoto sin desactivar el plugin ni perder la configuración:
 *
 *     define( 'MAD_DISABLED', true );
 */
if ( ! defined( 'MAD_DISABLED' ) ) {
	define( 'MAD_DISABLED', false );
}

/**
 * Configuración de agencia embebida en el zip por build.sh.
 *
 * Es lo que permite que instalar el plugin sea el único paso: la web ya
 * sabe a qué hub darse de alta. Si no existe (instalación manual desde el
 * repo), el plugin funciona igual y se configura desde el panel.
 */
if ( file_exists( MAD_DIR . 'includes/config.php' ) ) {
	require_once MAD_DIR . 'includes/config.php';
}

require_once MAD_DIR . 'includes/class-mad-auth.php';
require_once MAD_DIR . 'includes/class-mad-audit.php';
require_once MAD_DIR . 'includes/class-mad-safety.php';
require_once MAD_DIR . 'includes/class-mad-post-writer.php';
require_once MAD_DIR . 'includes/class-mad-hub.php';
require_once MAD_DIR . 'includes/class-mad-bootstrap.php';
require_once MAD_DIR . 'includes/class-mad-admin.php';
require_once MAD_DIR . 'includes/class-mad-updater.php';
require_once MAD_DIR . 'includes/rest/class-mad-controller.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-core.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-content.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-media.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-files.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-db.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-debug.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-builders.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-acf.php';
require_once MAD_DIR . 'includes/rest/class-mad-route-woo.php';

/**
 * Orquestador principal.
 */
final class MAD_Plugin {

	/** @var MAD_Plugin|null */
	private static $instance = null;

	/** @var MAD_Controller[] */
	private $controllers = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		MAD_Admin::init();
		MAD_Hub::init();
		MAD_Bootstrap::init();
		MAD_Updater::init();
		MAD_Audit::init();

		// El registro contra el hub se reintenta semanalmente por si la web
		// estaba caída o el hub no respondía en el momento de la activación.
		add_action( 'mad_hub_heartbeat', array( 'MAD_Hub', 'heartbeat' ) );
	}

	/**
	 * Monta todos los controladores REST.
	 */
	public function register_routes() {
		$this->controllers = array(
			new MAD_Route_Core(),
			new MAD_Route_Content(),
			new MAD_Route_Media(),
			new MAD_Route_Files(),
			new MAD_Route_DB(),
			new MAD_Route_Debug(),
			new MAD_Route_Builders(),
			new MAD_Route_ACF(),
			new MAD_Route_Woo(),
		);

		foreach ( $this->controllers as $controller ) {
			$controller->register_routes();
		}
	}
}

/**
 * Activación: crea tablas, genera el primer token y avisa al hub.
 */
function mad_activate() {
	MAD_Audit::install_table();
	MAD_Safety::ensure_backup_dir();

	// La configuración del hub viaja en el zip, pero un fichero se pierde
	// en cualquier actualización: la guardamos también en la base de datos
	// para que la web no se quede huérfana nunca.
	MAD_Hub::persist_config();

	// Solo generamos token la primera vez; una reactivación no debe
	// invalidar las credenciales que ya están dadas de alta en el hub.
	if ( ! MAD_Auth::has_tokens() ) {
		$token = MAD_Auth::create_token( 'Token inicial', MAD_Auth::default_scopes() );
		set_transient( 'mad_first_token', $token['secret'], DAY_IN_SECONDS );
	}

	if ( ! wp_next_scheduled( 'mad_hub_heartbeat' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mad_hub_heartbeat' );
	}
	if ( ! wp_next_scheduled( 'mad_bridge_check' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'mad_bridge_check' );
	}

	// El único paso que antes había que hacer a mano: comprobar que la
	// cabecera Authorization sobrevive a este servidor y, si no, arreglarlo.
	MAD_Bootstrap::ensure( true );

	// Alta inmediata, aquí y ahora: con timeout corto para no dejar colgada
	// la pantalla de plugins si el hub tarda. Si falla, quedan dos redes:
	// el reintento al entrar en el escritorio y el cron diario.
	MAD_Hub::heartbeat( 6 );

	update_option( 'mad_activated_at', current_time( 'mysql', true ), false );
}
register_activation_hook( __FILE__, 'mad_activate' );

/**
 * Desactivación: limpia crons y avisa al hub de que esta web se va.
 */
function mad_deactivate() {
	wp_clear_scheduled_hook( 'mad_hub_heartbeat' );
	wp_clear_scheduled_hook( 'mad_bridge_check' );
	MAD_Hub::deregister();
}
register_deactivation_hook( __FILE__, 'mad_deactivate' );

add_action( 'plugins_loaded', array( 'MAD_Plugin', 'instance' ) );
