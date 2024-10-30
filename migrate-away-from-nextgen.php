<?php
/**
 * Plugin Name: Modula NextGEN Migrator
 * Plugin URI: https://wp-modula.com/
 * Description: Submodule that helps migrate galleries from NextGEN Gallery to Modula Gallery
 * Author: WPChill
 * Author URI: https://www.wpchill.com/
 * Version: 1.0.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MODULA_NEXTGEN_MIGRATOR_VERSION', '1.0.2' );
define( 'MODULA_NEXTGEN_MIGRATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'MODULA_NEXTGEN_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'MODULA_NEXTGEN_MIGRATOR_FILE', __FILE__ );

require_once MODULA_NEXTGEN_MIGRATOR_PATH . 'includes/class-modula-nextgen-migrator.php';

// Load the main plugin class.
$modula_nextgen_migrator = Modula_Nextgen_Migrator::get_instance();
