<?php
/**
 * Parallel Index WP-CLI command for ElasticPress
 *
 * phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
 *
 * @package EpParallel
 */

namespace EpParallel;

use WP_CLI;
use ElasticPress\Command as EP_Command;
use ElasticPress\Indexable;

use Amp;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Parallel Index CLI Command for ElasticPress
 */
class Command extends EP_Command {

	// Due to some overly restrict visibility of ElasticPress's methods, this trait has copied/pasted versions of them.
	use UpstreamMethods;

	/**
	 * Number of sync'd documents.
	 *
	 * @var integer
	 */
	protected $synced = 0;

	/**
	 * Counter for no-bulk index.
	 *
	 * @var integer
	 */
	protected $no_bulk_count = 0;

	/**
	 * Array with failed objects.
	 *
	 * @var array
	 */
	protected $failed_objects = [];

	/**
	 * Current Indexable being used.
	 *
	 * @var \ElasticPress\Indexable
	 */
	protected $indexable = null;

	/**
	 * Object ID or IDs, depending on the value of `--no-bulk`.
	 *
	 * @var string|int|array
	 */
	protected $object_id = '';

	/**
	 * Running a bulk or no-bulk index?
	 *
	 * @var boolean
	 */
	protected $no_bulk = false;

	/**
	 * Total number of objects to be indexed.
	 *
	 * @var integer
	 */
	protected $query_total_objects = 0;

	/**
	 * Array with the data of all the requests that need to be done.
	 * Works like a queue of requests.
	 *
	 * @var array
	 */
	protected $request_data = [];

	/**
	 * The offset number of the main query.
	 *
	 * @var integer
	 */
	protected $query_offset = 0;

	/**
	 * Helper method for indexing documents for an indexable
	 *
	 * @param  Indexable $indexable Instance of indexable.
	 * @param  array     $args Query arguments to be based to object query.
	 * @since  0.9
	 * @return array
	 */
	protected function index_helper( Indexable $indexable, $args ) {
		$this->indexable = $indexable;

		$killed_object_count = 0;

		if ( isset( $args['nobulk'] ) ) {
			$this->no_bulk = true;
		}

		if ( isset( $args['ep-host'] ) ) {
			add_filter(
				'ep_host',
				function () use ( $args ) {
					return $args['ep-host'];
				}
			);
		}

		if ( isset( $args['ep-prefix'] ) ) {
			add_filter(
				'ep_index_prefix',
				function () use ( $args ) {
					return $args['ep-prefix'];
				}
			);
		}

		$show_errors = false;

		if ( isset( $args['show-errors'] ) || ( isset( $args['show-bulk-errors'] ) && ! $this->no_bulk ) || ( isset( $args['show-nobulk-errors'] ) && $this->no_bulk ) ) {
			$show_errors = true;
		}

		$query_args = [];

		$query_args['offset'] = 0;

		if ( ! empty( $args['offset'] ) ) {
			$query_args['offset'] = absint( $args['offset'] );
		}

		if ( ! empty( $args['post-ids'] ) ) {
			$args['include'] = $args['post-ids'];
		}

		if ( ! empty( $args['include'] ) ) {
			$include               = explode( ',', str_replace( ' ', '', $args['include'] ) );
			$query_args['include'] = array_map( 'absint', $include );
			$args['per-page']      = count( $query_args['include'] );
		}

		$per_page = $indexable->get_bulk_items_per_page();

		if ( ! empty( $args['per-page'] ) ) {
			$query_args['per_page'] = absint( $args['per-page'] );
			$per_page               = $query_args['per_page'];
		}

		if ( ! empty( $args['post-type'] ) ) {
			$query_args['post_type'] = explode( ',', $args['post-type'] );
			$query_args['post_type'] = array_map( 'trim', $query_args['post_type'] );
		}

		add_filter( 'ep_intercept_remote_request', '__return_true' );
		add_filter( 'ep_do_intercept_request', [ $this, 'capture_request' ], 10, 3 );

		while ( true ) {
			$query = $indexable->query_db( $query_args );

			$this->query_offset        = $query_args['offset'];
			$this->query_total_objects = (int) $query['total_objects'];

			/**
			 * Reset bulk object queue
			 */
			$objects = [];

			if ( ! empty( $query['objects'] ) ) {

				foreach ( $query['objects'] as $object ) {

					$this->should_interrupt_sync();

					if ( $this->no_bulk ) {
						/**
						 * Index objects one by one
						 */
						$this->object_id = $object->ID;
						$indexable->index( $object->ID, true );

						$this->no_bulk_count++;

						WP_CLI::log( sprintf( esc_html__( 'Enqueueing %1$d/%2$d...', 'elasticpress' ), $this->no_bulk_count, $this->query_total_objects ) );
					} else {
						/**
						 * Conditionally kill indexing for a post
						 *
						 * @hook ep_{indexable_slug}_index_kill
						 * @param  {bool} $index True means dont index
						 * @param  {int} $object_id Object ID
						 * @return {bool} New value
						 */
						if ( apply_filters( 'ep_' . $indexable->slug . '_index_kill', false, $object->ID ) ) {
							$killed_object_count++;
						} else {

							/**
							 * Put object in queue
							 */
							$objects[ $object->ID ] = true;
						}

						// If we have hit the trigger, initiate the bulk request.
						if ( ! empty( $objects ) && ( count( $objects ) + $killed_object_count ) >= absint( count( $query['objects'] ) ) ) {
							$index_objects = $objects;

							$this->object_id = array_keys( $index_objects );
							$indexable->bulk_index( array_keys( $index_objects ) );

							// reset killed count.
							$killed_object_count = 0;

							// reset the objects.
							$objects = [];
						}
					}
				}
			} else {
				// Process the last enqueued requests.
				$this->run_pipe();
				break;
			}

			if ( ! $this->no_bulk ) {
				WP_CLI::log( sprintf( esc_html__( 'Enqueueing %1$d/%2$d...', 'elasticpress' ), (int) ( count( $query['objects'] ) + $this->query_offset ), $this->query_total_objects ) );
			}

			$query_args['offset'] += $per_page;

			usleep( 500 );

			// Avoid running out of memory.
			$this->stop_the_insanity();

		}

		remove_filter( 'ep_intercept_remote_request', '__return_true' );
		remove_filter( 'ep_do_intercept_request', [ $this, 'capture_request' ] );

		if ( $show_errors && ! empty( $this->failed_objects ) ) {
			$this->output_index_errors( $this->failed_objects, $indexable );
		}

		wp_reset_postdata();

		return [
			'total'         => $this->query_total_objects,
			'synced'        => $this->synced,
			'errors'        => count( $this->failed_objects ),
			'error_details' => $this->output_index_errors( $this->failed_objects, $indexable, false ),
		];
	}

	/**
	 * Prevent the remote request from being done. Instead of that,
	 * stores the request data to make it in a batch later.
	 *
	 * @param mixed $response The request response.
	 * @param array $query     The query data.
	 * @param array $args      Arguments to be passed to the request.
	 * @return boolean
	 */
	public function capture_request( $response, $query, $args ) {
		$this->request_data[] = [
			'url'       => $query['url'],
			'data'      => $args,
			'object_id' => $this->object_id,
		];

		if ( apply_filters( 'ep_parallel_max_queue_size', 10 ) === count( $this->request_data ) ) {
			$this->run_pipe();
		}

		$this->indexable_slug = '';
		$this->object_id      = '';

		return false;
	}

	/**
	 * Process the stored request data and empty it.
	 */
	public function run_pipe() {
		$client = HttpClientBuilder::buildDefault();

		$promises = [];

		foreach ( $this->request_data as $request_data ) {
			$promises[] = Amp\call(
				static function () use ( $client, $request_data ) {

					$request = new Request( $request_data['url'], 'POST' );
					$request->setBody( $request_data['data']['body'] );

					if ( ! empty( $request_data['data']['headers'] ) ) {
						$request->setHeaders( $request_data['data']['headers'] );
					}

					if ( ! empty( $request_data['data']['timeout'] ) ) {
						$request->setTransferTimeout( $request_data['data']['timeout'] * 1000 );
						$request->setInactivityTimeout( $request_data['data']['timeout'] * 1000 );
					}

					try {
						$response = yield $client->request( $request );
						$body     = json_decode( yield $response->getBody()->buffer(), true );
					} catch ( \Exception $e ) {
						$body = new \WP_Error( 'http_request_failed', $e->getMessage() );
					}

					return [
						'object_id' => $request_data['object_id'],
						'response'  => $body,
					];
				}
			);
		}

		unset( $client );

		$responses = Amp\Promise\wait( Amp\Promise\all( $promises ) );

		if ( $this->no_bulk ) {
			$this->process_responses_no_bulk( $responses );
		} else {
			$this->process_responses_bulk( $responses );
		}

		WP_CLI::log( esc_html__( 'Processed', 'elasticpress' ) );

		$this->stop_the_insanity();

		$this->request_data = [];
	}

	/**
	 * Process responses on a `no-bulk` index.
	 *
	 * @param array $responses Array of responses.
	 */
	protected function process_responses_no_bulk( $responses ) {
		foreach ( $responses as $response ) {
			$result = $response['response'];

			if ( ! empty( $result['error'] ) ) {
				if ( ! empty( $result['error']['reason'] ) ) {
					$this->failed_objects[ $response['object_id'] ] = $result['error'];
				} else {
					$this->failed_objects[ $response['object_id'] ] = null;
				}
			} else {
				$this->synced++;
			}

			$this->reset_transient( $this->no_bulk_count, $this->query_total_objects, $this->indexable->slug );

			/**
			 * Fires after one by one indexing an object in CLI
			 *
			 * @hook ep_cli_object_index
			 * @param  {int} $object_id Object to index
			 * @param {Indexable} $indexable Current indexable
			 */
			do_action( 'ep_cli_object_index', $response['object_id'], $this->indexable );
		}
	}

	/**
	 * Process responses on a `bulk` index.
	 *
	 * @param array $responses Array of responses.
	 */
	protected function process_responses_bulk( $responses ) {
		foreach ( $responses as $response ) {
			$this->reset_transient(
				(int) ( count( $response['object_id'] ) + $this->query_offset ),
				$this->query_total_objects,
				$this->indexable->slug
			);

			/**
			 * Fires after bulk indexing in CLI
			 *
			 * @hook ep_cli_{indexable_slug}_bulk_index
			 * @param  {array} $objects Objects being indexed
			 * @param  {array} response Elasticsearch bulk index response
			 */
			do_action( 'ep_cli_' . $this->indexable->slug . '_bulk_index', $response['object_id'], $response );

			$failed = 0;

			if ( is_wp_error( $response ) ) {
				$this->delete_transient();

				WP_CLI::warning( implode( "\n", $response->get_error_messages() ) );
				continue;
			}

			if ( isset( $response['response']['errors'] ) && true === $response['response']['errors'] ) {
				foreach ( $response['response']['items'] as $item ) {
					if ( ! empty( $item['index']['error'] ) ) {
						$this->failed_objects[ $item['index']['_id'] ] = (array) $item['index']['error'];
						$failed++;
					}
				}
			}

			$this->synced += count( $response['object_id'] ) - $failed;
		}
	}
}
