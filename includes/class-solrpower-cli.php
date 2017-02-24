<?php

use WP_CLI\Utils;

/**
 * Perform a variety of actions against your Solr instance.
 */
class SolrPower_CLI extends WP_CLI_Command {

	/**
	 * Check server settings.
	 *
	 * Pings the Solr server to see if the connection is functional.
	 *
	 * @subcommand check-server-settings
	 */
	public function check_server_settings() {
		$retval = SolrPower_Api::get_instance()->ping_server();
		if ( $retval ) {
			WP_CLI::success( 'Server ping successful.' );
		} else {
			$last_error = SolrPower_Api::get_instance()->last_error;
			WP_CLI::error( "Server ping failed: {$last_error->getMessage()}" );
		}
	}

	/**
	 * Remove one or more posts from the index.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more post ids to remove from the index.
	 *
	 * [--all]
	 * : Remove all posts from the index.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp solr delete 342
	 *     Removed post 342 from the index.
	 *     Success: Specified posts removed from the index.
	 *
	 *     $ wp solr delete --all
	 *     Success: All posts successfully removed from the index.
	 *
	 */
	public function delete( $args, $assoc_args ) {

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all' ) ) {
			if ( SolrPower_Sync::get_instance()->delete_all() ) {
				WP_CLI::success( 'All posts successfully removed from the index.' );
			} else {
				$last_error = SolrPower_Api::get_instance()->last_error;
				WP_CLI::error( "Couldn't remove all posts from the index: {$last_error->getMessage()}" );
			}
		} else if ( count( $args ) ) {
			foreach( $args as $post_id ) {
				if ( SolrPower_Sync::get_instance()->delete( absint( $post_id ) ) ) {
					WP_CLI::log( "Removed post {$post_id} from the index." );
				} else {
					$last_error = SolrPower_Api::get_instance()->last_error;
					WP_CLI::warning( "Couldn't removed post {$post_id} from the index: {$last_error->getMessage()}" );
				}
			}
			WP_CLI::success( 'Specified posts removed from the index.' );
		} else {
			WP_CLI::error( 'Please specify one or more post ids, or use the --all flag.' );
		}

	}

	/**
	 * Index all posts for a site.
	 *
	 * Automatically resumes indexing process from the point it was stopped,
	 * unless the `--force` flag is used.
	 *
	 * [--posts_per_page=<count>]
	 * : Index a specific number of posts per set.
	 * ---
	 * default: 300
	 * ---
	 *
	 * [--post_type=<type>]
	 * : Limit indexing to a specific post type.
	 *
	 * [--force]
	 * : Restart indexing from the beginning, regardless of whether it's been run.
	 */
	public function index( $args, $assoc_args ) {

		$defaults = array(
			'posts_per_page' => 300,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'paged'          => 1,
			'order'          => 'ASC',
			'orderby'        => 'ID',
			'post_type'      => get_post_types( array( 'exclude_from_search' => false ) ),
		);
		// Check if specified post_type is valid.
		if ( isset( $assoc_args[ 'post_type' ] ) && (false === post_type_exists( $assoc_args[ 'post_type' ] )) ) {
			WP_CLI::error( '"' . $assoc_args[ 'post_type' ] . '" is an invalid post type.' );
		}
		$query_args		 = array_merge( $defaults, $assoc_args );

		$log_key         = 'solr_index_paged_' . md5( serialize( $query_args ) );
		$paged           = (int) get_option( $log_key, 0 );
		if ( ! $paged || Utils\get_flag_value( $assoc_args, 'force' ) ) {
			$paged       = 1;
		} else {
			WP_CLI::log( "Resuming index at page {$paged}..." );
		}
		// Ensure each index starts from a fresh point
		delete_option( $log_key );
		add_option( $log_key, $paged, null, 'off' );
		$query_args['paged'] = $paged;

		$query			 = new WP_Query( $query_args );
		$current_page	 = $query->get( 'paged' );
		$total			 = $query->max_num_pages;
		// There's a bug with found_posts that shows two more than what it should.
		$total_posts	 = (1 == $query->max_num_pages) ? $query->post_count : $query->found_posts - 2;

		$notify	 = \WP_CLI\Utils\make_progress_bar( 'Indexing Items:', $total_posts );
		$done	 = 0;
		$failed	 = 0;
		$solr	 = get_solr();
		$update	 = $solr->createUpdate();
		while ( $current_page <= $total ) {
			$query->set( 'paged', $current_page );
			$query->get_posts();
			foreach ( $query->posts as $id ) {
				$documents	 = array();
				$documents[] = SolrPower_Sync::get_instance()->build_document( $update->createDocument(), get_post( $id ) );
				$post_it	 = SolrPower_Sync::get_instance()->post( $documents, true, FALSE );

				if ( false === $post_it ) {
					$failed++;
				} else {
					$done++;
				}
				$notify->tick();
			}
			$current_page++;
			update_option( $log_key, $current_page );
		}
		do_action( 'solr_power_index_all_finished' );
		$notify->finish();
		// Clean up the log because the next index can start from scratch
		delete_option( $log_key );
		WP_CLI::success( sprintf( '%d of %d items indexed.', $done, $total_posts ) );
		if ( 0 < $failed ) {
			WP_CLI::error( 'Failed to index ' . $failed . ' item(s).' );
			WP_CLI::error( SolrPower_Sync::get_instance()->error_msg );
		}
	}

	/**
	 * Report information about Solr Power configuration.
	 *
	 * Displays ping status, alongside Solr server IP address, port, and path.
	 *
	 * ## OPTIONS
	 *
	 * [--field=<field>]
	 * : Display a specific field.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp solr info
	 *     +-------------+------------+
	 *     | Field       | Value      |
	 *     +-------------+------------+
	 *     | ping_status | successful |
	 *     | ip_address  | localhost  |
	 *     | port        | 8983       |
	 *     | path        | /solr      |
	 *     +-------------+------------+
	 *
	 *     $ wp solr info --field=ip_address
	 *     localhost
	 */
	public function info( $args, $assoc_args ) {

		$server = SolrPower_Api::get_instance()->get_server_info();
		$server['ping_status'] = ( $server['ping_status'] ) ? 'successful' : 'failed';
		$formatter = new \WP_CLI\Formatter( $assoc_args, array( 'ping_status', 'ip_address', 'port', 'path' ) );
		$formatter->display_item( $server );
	}

	/**
	 * Optimize the Solr index.
	 *
	 * Calls Solarium's addOptimize() to 'defragment' your index. The space
	 * taken by deleted document data is reclaimed and can merge the index into
	 * fewer segments. This can improve search performance a lot.
	 *
	 * @subcommand optimize-index
	 */
	public function optimize_index() {
		SolrPower_Api::get_instance()->optimize();
		WP_CLI::success( 'Index optimized.' );
	}

	/**
	 * Repost schema.xml to Solr.
	 *
	 * Deletes the current index, then reposts the schema.xml to Solr.
	 *
	 * @subcommand repost-schema
	 */
	public function repost_schema() {

		if ( ! getenv( 'PANTHEON_ENVIRONMENT' ) || ! getenv( 'FILEMOUNT' ) ) {
			WP_CLI::error( 'Schema repositing only works in a Pantheon environment.' );
		}

		SolrPower_Sync::get_instance()->delete_all();
		$output = SolrPower_Api::get_instance()->submit_schema();
		WP_CLI::success( "Schema reposted: {$output}" );
	}

	/**
	 * Report stats about indexed content.
	 *
	 * Displays number of indexed posts for each enabled post type.
	 *
	 * ## OPTIONS
	 *
	 * [--field=<field>]
	 * : Display a specific field.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp solr stats
	 *     +------------+-------+
	 *     | Field      | Value |
	 *     +------------+-------+
	 *     | post       | 2     |
	 *     | page       | 1     |
	 *     | attachment | 0     |
	 *     +------------+-------+
	 *
	 *     $ wp solr stats --field=page
	 *     1
	 */
	public function stats( $args, $assoc_args ) {
		$stats = SolrPower_Api::get_instance()->index_stats();
		$formatter = new \WP_CLI\Formatter( $assoc_args, array_keys( $stats ) );
		$formatter->display_item( $stats );
	}

}
