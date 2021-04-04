<?php
/**
 * This trait contains copied and pasted versions of some ElasticPress::Command methods,
 * as all of them are marked as privated in the original plugin.
 *
 * phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
 *
 * @package EpParallel
 */

namespace EpParallel;

use WP_CLI;
use ElasticPress\Indexable;
use ElasticPress\Indexables;
use ElasticPress\Utils;
use ElasticPress\Elasticsearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * CLI Commands for ElasticPress
 */
trait UpstreamMethods {

	/**
	 * Holds time until transient expires
	 *
	 * @var  array
	 */
	protected $transient_expiration = 900; // 15 min

	/**
	 * Holds temporary wp_actions when indexing with pagination
	 *
	 * @var  array
	 */
	protected $temporary_wp_actions = [];

	/**
	 * Index all posts for a site or network wide
	 *
	 * @synopsis [--setup] [--network-wide] [--per-page] [--nobulk] [--show-errors] [--offset] [--indexables] [--show-bulk-errors] [--show-nobulk-errors] [--post-type] [--include] [--post-ids] [--ep-host] [--ep-prefix]
	 *
	 * @param array $args Positional CLI args.
	 * @since 0.1.2
	 * @param array $assoc_args Associative CLI args.
	 */
	public function index( $args, $assoc_args ) {
		global $wp_actions;

		if ( ! function_exists( 'pcntl_signal' ) ) {
			WP_CLI::warning( esc_html__( 'Function pcntl_signal not available. Make sure to run `wp elasticpress clear-index` in case the process is killed.' ) );
		} else {
			declare( ticks = 1 );
			pcntl_signal( SIGINT, [ $this, 'delete_transient_on_int' ] );
		}

		$this->maybe_change_host( $assoc_args );
		$this->maybe_change_index_prefix( $assoc_args );
		$this->connect_check();
		$this->index_occurring();

		$indexables = null;

		if ( ! empty( $assoc_args['indexables'] ) ) {
			$indexables = explode( ',', str_replace( ' ', '', $assoc_args['indexables'] ) );
		}

		$total_indexed   = 0;
		$total_indexable = 0;
		$index_errors    = array();

		// Hold original wp_actions.
		$this->temporary_wp_actions = $wp_actions;

		/**
		 * Prior to the index command invoking
		 * Useful for deregistering filters/actions that occur during a query request
		 *
		 * @since 1.4.1
		 */

		/**
		 * Fires before starting a CLI index
		 *
		 * @hook ep_wp_cli_pre_index
		 * @param  {array} $args CLI command position args
		 * @param {array} $assoc_args CLI command associative args
		 */
		do_action( 'ep_wp_cli_pre_index', $args, $assoc_args );

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			set_site_transient( 'ep_wpcli_sync', true, $this->transient_expiration );
		} else {
			set_transient( 'ep_wpcli_sync', true, $this->transient_expiration );
		}

		timer_start();

		// This clears away dashboard notifications.
		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			update_site_option( 'ep_last_sync', time() );
			delete_site_option( 'ep_need_upgrade_sync' );
			delete_site_option( 'ep_feature_auto_activated_sync' );
		} else {
			update_option( 'ep_last_sync', time() );
			delete_option( 'ep_need_upgrade_sync' );
			delete_option( 'ep_feature_auto_activated_sync' );
		}

		// Run setup if flag was passed.
		if ( isset( $assoc_args['setup'] ) && true === $assoc_args['setup'] ) {

			// Right now setup is just the put_mapping command, as this also deletes the index(s) first.
			if ( ! $this->put_mapping_helper( $args, $assoc_args ) ) {
				$this->delete_transient();

				exit( 1 );
			}
		}

		$all_indexables               = Indexables::factory()->get_all();
		$non_global_indexable_objects = Indexables::factory()->get_all( false );
		$global_indexable_objects     = Indexables::factory()->get_all( true );

		if ( isset( $assoc_args['network-wide'] ) && is_multisite() ) {
			if ( ! is_numeric( $assoc_args['network-wide'] ) ) {
				$assoc_args['network-wide'] = 0;
			}

			WP_CLI::log( esc_html__( 'Indexing objects network-wide...', 'elasticpress' ) );

			$sites = Utils\get_sites( $assoc_args['network-wide'] );

			foreach ( $sites as $site ) {
				if ( ! Utils\is_site_indexable( $site['blog_id'] ) ) {
					continue;
				}

				switch_to_blog( $site['blog_id'] );

				foreach ( $non_global_indexable_objects as $indexable ) {
					/**
					 * If user has called out specific indexables to be indexed, only do those
					 */
					if ( null !== $indexables && ! in_array( $indexable->slug, $indexables, true ) ) {
						continue;
					}

					WP_CLI::log( sprintf( esc_html__( 'Indexing %1$s on site %2$d...', 'elasticpress' ), esc_html( strtolower( $indexable->labels['plural'] ) ), (int) $site['blog_id'] ) );

					$result = $this->index_helper( $indexable, $assoc_args );

					$total_indexed  += $result['synced'];
					$total_indexable = $result['total'];
					$index_errors    = array_merge( $index_errors, $result['error_details'] );

					WP_CLI::log( sprintf( esc_html__( 'Number of %1$s indexed on site %2$d: %3$d', 'elasticpress' ), esc_html( strtolower( $indexable->labels['plural'] ) ), $site['blog_id'], $result['synced'] ) );

					if ( ! empty( $result['errors'] ) ) {
						$this->delete_transient();

						WP_CLI::warning( sprintf( esc_html__( 'Number of %1$s index errors on site %2$d: %3$d', 'elasticpress' ), esc_html( strtolower( $indexable->labels['singular'] ) ), $site['blog_id'], $result['errors'] ) );
					}
				}

				restore_current_blog();
			}

			/**
			 * Index global indexables e.g. useres
			 */
			foreach ( $global_indexable_objects as $indexable ) {
				/**
				 * If user has called out specific indexables to be indexed, only do those
				 */
				if ( null !== $indexables && ! in_array( $indexable->slug, $indexables, true ) ) {
					continue;
				}

				WP_CLI::log( sprintf( esc_html__( 'Indexing %s...', 'elasticpress' ), esc_html( strtolower( $indexable->labels['plural'] ) ) ) );

				$result = $this->index_helper( $indexable, $assoc_args );

				$total_indexed  += $result['synced'];
				$total_indexable = $result['total'];
				$index_errors    = array_merge( $index_errors, $result['error_details'] );

				WP_CLI::log( sprintf( esc_html__( 'Number of %1$s indexed: %2$d', 'elasticpress' ), esc_html( strtolower( $indexable->labels['plural'] ) ), $result['synced'] ) );

				if ( ! empty( $result['errors'] ) ) {
					$this->delete_transient();

					WP_CLI::warning( sprintf( esc_html__( 'Number of %1$s index errors: %2$d', 'elasticpress' ), esc_html( strtolower( $indexable->labels['singular'] ) ), $result['errors'] ) );
				}
			}

			/**
			 * Handle network aliases separately as they don't depend on blog ID
			 */
			foreach ( $non_global_indexable_objects as $indexable ) {
				/**
				 * If user has called out specific indexables to be indexed, only do those
				 */
				if ( null !== $indexables && ! in_array( $indexable->slug, $indexables, true ) ) {
					continue;
				}

				WP_CLI::log( sprintf( esc_html__( 'Recreating %s network alias...', 'elasticpress' ), esc_html( strtolower( $indexable->labels['singular'] ) ) ) );

				$this->create_network_alias_helper( $indexable );
			}
		} else {
			/**
			 * Run indexing for each indexable one by one
			 */
			foreach ( $all_indexables as $indexable ) {
				/**
				 * If user has called out specific indexables to be indexed, only do those
				 */
				if ( null !== $indexables && ! in_array( $indexable->slug, $indexables, true ) ) {
					continue;
				}

				WP_CLI::log( sprintf( esc_html__( 'Indexing %s...', 'elasticpress' ), esc_html( strtolower( $indexable->labels['plural'] ) ) ) );

				$result = $this->index_helper( $indexable, $assoc_args );

				$total_indexed  += $result['synced'];
				$total_indexable = $result['total'];
				$index_errors    = array_merge( $index_errors, $result['error_details'] );

				WP_CLI::log( sprintf( esc_html__( 'Number of %1$s indexed: %2$d', 'elasticpress' ), esc_html( strtolower( $indexable->labels['plural'] ) ), $result['synced'] ) );

				if ( ! empty( $result['errors'] ) ) {
					$this->delete_transient();

					WP_CLI::warning( sprintf( esc_html__( 'Number of %1$s index errors: %2$d', 'elasticpress' ), esc_html( strtolower( $indexable->labels['singular'] ) ), $result['errors'] ) );
				}
			}
		}

		$index_time = timer_stop();

		$index_results = array(
			'total'        => $total_indexable,
			'synced'       => $total_indexed,
			'end_time_gmt' => time(),
			'total_time'   => (float) $index_time,
			'errors'       => $index_errors,
		);

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			update_site_option( 'ep_last_cli_index', $index_results );
		} else {
			update_option( 'ep_last_cli_index', $index_results, false );
		}

		/**
		 * Fires after executing a CLI index
		 *
		 * @hook ep_wp_cli_after_index
		 * @param  {array} $args CLI command position args
		 * @param {array} $assoc_args CLI command associative args
		 *
		 * @since 3.5.5
		 */
		do_action( 'ep_wp_cli_after_index', $args, $assoc_args );

		WP_CLI::log( WP_CLI::colorize( '%Y' . esc_html__( 'Total time elapsed: ', 'elasticpress' ) . '%N' . $index_time ) );

		$this->delete_transient();

		WP_CLI::success( esc_html__( 'Done!', 'elasticpress' ) );
	}

	/**
	 * maybe change Elastic host on the fly
	 *
	 * @param array $assoc_args Associative CLI args.
	 *
	 * @since 3.4
	 */
	protected function maybe_change_host( $assoc_args ) {
		if ( isset( $assoc_args['ep-host'] ) ) {
			add_filter(
				'ep_host',
				function ( $host ) use ( $assoc_args ) {
					return $assoc_args['ep-host'];
				}
			);
		}
	}

	/**
	 * maybe change index prefix on the fly
	 *
	 * @param array $assoc_args Associative CLI args.
	 *
	 * @since 3.4
	 */
	protected function maybe_change_index_prefix( $assoc_args ) {
		if ( isset( $assoc_args['ep-prefix'] ) ) {
			add_filter(
				'ep_index_prefix',
				function ( $prefix ) use ( $assoc_args ) {
					return $assoc_args['ep-prefix'];
				}
			);
		}
	}

	/**
	 * Check if sync should be interrupted
	 *
	 * @since 3.5.2
	 */
	protected function should_interrupt_sync() {
		$should_interrupt_sync = get_transient( 'ep_wpcli_sync_interrupted' );

		if ( $should_interrupt_sync ) {
			WP_CLI::line( esc_html__( 'Sync was interrupted', 'elasticpress' ) );
			$this->delete_transient_on_int( 2 );
			WP_CLI::halt();
		}
	}

	/**
	 * Provide better error messaging for common connection errors
	 *
	 * @since 0.9.3
	 */
	protected function connect_check() {
		$host = Utils\get_host();

		if ( empty( $host ) ) {
			WP_CLI::error( esc_html__( 'Elasticsearch host is not set.', 'elasticpress' ) );
		} elseif ( ! Elasticsearch::factory()->get_elasticsearch_version( true ) ) {
			WP_CLI::error( esc_html__( 'Could not connect to Elasticsearch.', 'elasticpress' ) );
		}
	}

	/**
	 * Error out if index is already occurring
	 *
	 * @since 3.0
	 */
	protected function index_occurring() {

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			$dashboard_syncing = get_site_option( 'ep_index_meta' );
			$wpcli_syncing     = get_site_transient( 'ep_wpcli_sync' );
		} else {
			$dashboard_syncing = get_option( 'ep_index_meta' );
			$wpcli_syncing     = get_transient( 'ep_wpcli_sync' );
		}

		if ( $dashboard_syncing || $wpcli_syncing ) {
			WP_CLI::error( esc_html__( 'An index is already occuring. Try again later.', 'elasticpress' ) );
		}
	}

	/**
	 * Send any bulk indexing errors
	 *
	 * @param  array     $errors Error array
	 * @param  Indexable $indexable Index indexable
	 * @param  bool      $output True to print output
	 *
	 * @return array Array of error messages for furthur logging.
	 * @since 3.4
	 */
	protected function output_index_errors( $errors, Indexable $indexable, $output = true ) {
		$error_text  = esc_html__( "The following failed to index:\r\n\r\n", 'elasticpress' );
		$error_array = array();

		foreach ( $errors as $object_id => $error ) {

			$error_array[ $object_id ] = array(
				$indexable->labels['singular'],
				$error['type'],
				$error['reason'],
			);

			$error_text .= '- ' . $object_id . ' (' . $indexable->labels['singular'] . '): ' . "\r\n";

			$error_text .= '[' . $error['type'] . '] ' . $error['reason'] . "\r\n";
		}

		if ( $output ) {
			WP_CLI::log( $error_text );
		}

		return $error_array;
	}

	/**
	 * Delete transient that indicates indexing is occuring
	 *
	 * @since 3.1
	 */
	protected function delete_transient() {
		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			delete_site_transient( 'ep_wpcli_sync' );
			delete_site_transient( 'ep_cli_sync_progress' );
			delete_site_transient( 'ep_wpcli_sync_interrupted' );
		} else {
			delete_transient( 'ep_wpcli_sync' );
			delete_transient( 'ep_cli_sync_progress' );
			delete_transient( 'ep_wpcli_sync_interrupted' );
		}
	}

	/**
	 * Reset transient while indexing
	 *
	 * @param int    $items_indexed Count of items already indexed.
	 * @param int    $total_items Total number of items to be indexed.
	 * @param string $slug The slug of the indexable.
	 *
	 * @since 2.2
	 */
	protected function reset_transient( $items_indexed, $total_items, $slug ) {
		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			set_site_transient( 'ep_wpcli_sync', array( $items_indexed, $total_items, $slug ), $this->transient_expiration );
		} else {
			set_transient( 'ep_wpcli_sync', array( $items_indexed, $total_items, $slug ), $this->transient_expiration );
		}
	}

	/**
	 * Resets some values to reduce memory footprint.
	 */
	protected function stop_the_insanity() {
		global $wpdb, $wp_object_cache, $wp_actions, $wp_filter;

		$wpdb->queries = [];

		if ( is_object( $wp_object_cache ) ) {
			$wp_object_cache->group_ops      = [];
			$wp_object_cache->stats          = [];
			$wp_object_cache->memcache_debug = [];

			// Make sure this is a public property, before trying to clear it.
			try {
				$cache_property = new \ReflectionProperty( $wp_object_cache, 'cache' );
				if ( $cache_property->isPublic() ) {
					$wp_object_cache->cache = [];
				}
				unset( $cache_property );
			} catch ( \ReflectionException $e ) {
				// No need to catch.
			}

			/*
			 * In the case where we're not using an external object cache, we need to call flush on the default
			 * WordPress object cache class to clear the values from the cache property
			 */
			if ( ! wp_using_ext_object_cache() ) {
				wp_cache_flush();
			}

			if ( is_callable( $wp_object_cache, '__remoteset' ) ) {
				call_user_func( [ $wp_object_cache, '__remoteset' ] );
			}
		}

		// Prevent wp_actions from growing out of control.
		// phpcs:disable
		$wp_actions = $this->temporary_wp_actions;
		// phpcs:enable

		// WP_Query class adds filter get_term_metadata using its own instance
		// what prevents WP_Query class from being destructed by PHP gc.
		// if ( $q['update_post_term_cache'] ) {
		// add_filter( 'get_term_metadata', array( $this, 'lazyload_term_meta' ), 10, 2 );
		// }
		// It's high memory consuming as WP_Query instance holds all query results inside itself
		// and in theory $wp_filter will not stop growing until Out Of Memory exception occurs.
		if ( isset( $wp_filter['get_term_metadata'] ) ) {
			/*
			 * WordPress 4.7 has a new Hook infrastructure, so we need to make sure
			 * we're accessing the global array properly
			 */
			if ( class_exists( 'WP_Hook' ) && $wp_filter['get_term_metadata'] instanceof WP_Hook ) {
				$filter_callbacks = &$wp_filter['get_term_metadata']->callbacks;
			} else {
				$filter_callbacks = &$wp_filter['get_term_metadata'];
			}
			if ( isset( $filter_callbacks[10] ) ) {
				foreach ( $filter_callbacks[10] as $hook => $content ) {
					if ( preg_match( '#^[0-9a-f]{32}lazyload_term_meta$#', $hook ) ) {
						unset( $filter_callbacks[10][ $hook ] );
					}
				}
			}
		}
	}

	/**
	 * Add document mappings for every indexable
	 *
	 * @since 3.0
	 * @param array $args Positional CLI args.
	 * @param array $assoc_args Associative CLI args.
	 * @return boolean
	 */
	protected function put_mapping_helper( $args, $assoc_args ) {
		$indexables = null;

		if ( ! empty( $assoc_args['indexables'] ) ) {
			$indexables = explode( ',', str_replace( ' ', '', $assoc_args['indexables'] ) );
		}

		$non_global_indexable_objects = Indexables::factory()->get_all( false );
		$global_indexable_objects     = Indexables::factory()->get_all( true );

		if ( isset( $assoc_args['network-wide'] ) && is_multisite() ) {
			if ( ! is_numeric( $assoc_args['network-wide'] ) ) {
				$assoc_args['network-wide'] = 0;
			}

			$sites = Utils\get_sites( $assoc_args['network-wide'] );

			foreach ( $sites as $site ) {
				if ( ! Utils\is_site_indexable( $site['blog_id'] ) ) {
					continue;
				}

				switch_to_blog( $site['blog_id'] );

				foreach ( $non_global_indexable_objects as $indexable ) {
					/**
					 * If user has called out specific indexables to be indexed, only do those
					 */
					if ( null !== $indexables && ! in_array( $indexable->slug, $indexables, true ) ) {
						continue;
					}

					WP_CLI::line( sprintf( esc_html__( 'Adding %1$s mapping for site %2$d...', 'elasticpress' ), esc_html( strtolower( $indexable->labels['singular'] ) ), (int) $site['blog_id'] ) );

					$indexable->delete_index();
					$result = $indexable->put_mapping();

					/**
					 * Fires after CLI put mapping
					 *
					 * @hook ep_cli_put_mapping
					 * @param  {Indexable} $indexable Indexable involved in mapping
					 * @param  {array} $args CLI command position args
					 * @param {array} $assoc_args CLI command associative args
					 */
					do_action( 'ep_cli_put_mapping', $indexable, $args, $assoc_args );

					if ( $result ) {
						WP_CLI::success( esc_html__( 'Mapping sent', 'elasticpress' ) );
					} else {
						WP_CLI::error( esc_html__( 'Mapping failed', 'elasticpress' ), false );

						return false;
					}
				}

				restore_current_blog();
			}
		} else {
			foreach ( $non_global_indexable_objects as $indexable ) {
				/**
				 * If user has called out specific indexables to be indexed, only do those
				 */
				if ( null !== $indexables && ! in_array( $indexable->slug, $indexables, true ) ) {
					continue;
				}

				WP_CLI::line( sprintf( esc_html__( 'Adding %s mapping...', 'elasticpress' ), esc_html( strtolower( $indexable->labels['singular'] ) ) ) );

				$indexable->delete_index();
				$result = $indexable->put_mapping();

				/**
				 * Fires after CLI put mapping
				 *
				 * @hook ep_cli_put_mapping
				 * @param  {Indexable} $indexable Indexable involved in mapping
				 * @param  {array} $args CLI command position args
				 * @param {array} $assoc_args CLI command associative args
				 */
				do_action( 'ep_cli_put_mapping', $indexable, $args, $assoc_args );

				if ( $result ) {
					WP_CLI::success( esc_html__( 'Mapping sent', 'elasticpress' ) );
				} else {
					WP_CLI::error( esc_html__( 'Mapping failed', 'elasticpress' ), false );

					return false;
				}
			}
		}

		/**
		 * Handle global indexables separately
		 */
		foreach ( $global_indexable_objects as $indexable ) {
			/**
			 * If user has called out specific indexables to be indexed, only do those
			 */
			if ( null !== $indexables && ! in_array( $indexable->slug, $indexables, true ) ) {
				continue;
			}

			WP_CLI::line( sprintf( esc_html__( 'Adding %s mapping...', 'elasticpress' ), esc_html( strtolower( $indexable->labels['singular'] ) ) ) );

			$indexable->delete_index();
			$result = $indexable->put_mapping();

			/**
			 * Fires after CLI put mapping
			 *
			 * @hook ep_cli_put_mapping
			 * @param  {Indexable} $indexable Indexable involved in mapping
			 * @param  {array} $args CLI command position args
			 * @param {array} $assoc_args CLI command associative args
			 */
			do_action( 'ep_cli_put_mapping', $indexable, $args, $assoc_args );

			if ( $result ) {
				WP_CLI::success( esc_html__( 'Mapping sent', 'elasticpress' ) );
			} else {
				WP_CLI::error( esc_html__( 'Mapping failed', 'elasticpress' ), false );

				return false;
			}
		}

		return true;
	}
}
