<?php
/**
 * Core plugin functionality.
 *
 * @package EpParallel
 */

namespace EpParallel\Core;

use \WP_CLI;

/**
 * Default setup routine
 *
 * @return void
 */
function setup() {
	$n = function( $function ) {
		return __NAMESPACE__ . "\\$function";
	};

	add_action( 'init', $n( 'i18n' ) );
	add_action( 'init', $n( 'init' ) );

	add_action( 'plugins_loaded', $n( 'plugins_loaded' ), 11 );

	do_action( 'tenup_plugin_loaded' );
}

/**
 * Check if ElasticPress is active.
 * If it is, register features. Display a message otherwise.
 *
 * @return void
 */
function plugins_loaded() {
	if ( class_exists( 'ElasticPress\Features' ) ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'ep-parallel', '\EpParallel\Command' );
		}
	} else {
		add_action( 'admin_notices', __NAMESPACE__ . '\missing_elasticpress_notice' );
	}
}

/**
 * The message displayed when ElasticPress is not active.
 */
function missing_elasticpress_notice() {
	?>
	<div class="error">
		<p>
		<?php
			wp_kses_post(
				esc_html__( 'ElasticPress Parallel depends on <strong>ElasticPress</strong> to work.', 'ep-parallel' )
			);
		?>
		</p>
	</div>
	<?php
}

/**
 * Registers the default textdomain.
 *
 * @return void
 */
function i18n() {
	$locale = apply_filters( 'plugin_locale', get_locale(), 'ep-parallel' );
	load_textdomain( 'ep-parallel', WP_LANG_DIR . '/ep-parallel/ep-parallel-' . $locale . '.mo' );
	load_plugin_textdomain( 'ep-parallel', false, plugin_basename( EP_PARALLEL_PATH ) . '/languages/' );
}

/**
 * Initializes the plugin and fires an action other plugins can hook into.
 *
 * @return void
 */
function init() {
	do_action( 'tenup_plugin_init' );
}
