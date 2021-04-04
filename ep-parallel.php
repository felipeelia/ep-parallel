<?php
/**
 * Plugin Name:       ElasticPress Parallel
 * Description:       Parallel index for ElasticPress
 * Version:           0.1.0
 * Requires at least: 4.9
 * Requires PHP:      7.2
 * Author:            Felipe Elia
 * Author URI:        https://felipeelia.com.br
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ep-parallel
 * Domain Path:       /languages
 *
 * @package           EpParallel
 */

// Useful global constants.
define( 'EP_PARALLEL_VERSION', '0.1.0' );
define( 'EP_PARALLEL_URL', plugin_dir_url( __FILE__ ) );
define( 'EP_PARALLEL_PATH', plugin_dir_path( __FILE__ ) );
define( 'EP_PARALLEL_INC', EP_PARALLEL_PATH . 'includes/' );

// Require Composer autoloader if it exists.
if ( file_exists( EP_PARALLEL_PATH . 'vendor/autoload.php' ) ) {
	require_once EP_PARALLEL_PATH . 'vendor/autoload.php';
}

// Include files.
require_once EP_PARALLEL_INC . '/core.php';

// Bootstrap.
EpParallel\Core\setup();
