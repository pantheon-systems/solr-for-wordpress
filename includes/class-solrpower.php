<?php

class SolrPower {

	/**
	 * Singleton instance
	 * @var SolrPower|Bool
	 */
	private static $instance = false;

	/**
	 * Grab instance of object.
	 * @return SolrPower
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	function __construct() {

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_head' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_filter( 'plugin_action_links', array( $this, 'plugin_settings_link' ), 10, 2 );
		add_filter( 'debug_bar_panels', array( $this, 'add_panel' ) );
		add_action( 'widgets_init', function () {
			register_widget( 'SolrPower_Facet_Widget' );
		} );

		add_action( 'wp_ajax_nopriv_solr_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_solr_search', array( $this, 'ajax_search' ) );
	}

	function activate() {

		// Check to see if we have  environment variables. If not, bail. If so, create the initial options.

		if ( $errMessage = SolrPower::get_instance()->sanity_check() ) {
			wp_die( esc_html( $errMessage ) );
		}

		// Don't try to send a schema if we're not on Pantheon servers.
		if ( ! defined( 'SOLR_PATH' ) ) {
			$schemaSubmit = SolrPower_Api::get_instance()->submit_schema();
			if ( strpos( $schemaSubmit, 'Error' ) ) {
				wp_die( 'Submitting the schema failed with the message ' . esc_html( $errMessage ) );
			}
		}
		SolrPower_Options::get_instance()->initalize_options();

		return;
	}

	function sanity_check() {
		$returnValue = '';
		$wp_version  = get_bloginfo( 'version' );

		if ( getenv( 'PANTHEON_ENVIRONMENT' ) !== false && getenv( 'PANTHEON_INDEX_HOST' ) === false ) {
			$returnValue = wp_kses( __( 'Before you can activate this plugin, you must first <a href="https://pantheon.io/docs/articles/sites/apache-solr/">activate Solr</a> in your Pantheon Dashboard.', 'solr-for-wordpress-on-pantheon' ), array(
				'a' => array(
					'href' => array()
				)
			) );
		} else if ( version_compare( $wp_version, '3.0', '<' ) ) {
			$returnValue = esc_html__( 'This plugin requires WordPress 3.0 or greater.', 'solr-for-wordpress-on-pantheon' );
		}

		return $returnValue;
	}

	function admin_head() {

		$min = ( defined( 'SCRIPT_DEBUG' ) && true === SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_style( 'solr-admin-css', SOLR_POWER_URL . 'assets/css/admin' . $min . '.css' );
		wp_enqueue_script( 'solr-admin-js', SOLR_POWER_URL . 'assets/js/admin' . $min . '.js', array( 'jquery' ) );

		$solr_js = array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),

			/**
			 * Filter indexed post types
			 *
			 * Filter the list of post types available to index.
			 *
			 * @param array $post_types Array of post type names for indexing.
			 */

			'post_types' => apply_filters( 'solr_post_types', get_post_types( array( 'exclude_from_search' => false ) ) ),
			'security'   => wp_create_nonce( "solr_security" )
		);
		wp_localize_script( 'solr-admin-js', 'solr', $solr_js );
	}

	/**
	 * Display a settings link on the plugins page.
	 *
	 * @param array $links
	 * @param string $file
	 *
	 * @return array
	 */
	function plugin_settings_link( $links, $file ) {

		if ( $file !== plugin_basename( SOLR_POWER_PATH . '/solr-power.php' ) ) {
			return $links;
		}

		array_unshift( $links, '<a href="' . admin_url( 'admin.php' ) . '?page=solr-power">' . esc_html__( 'Settings', 'solr-for-wordpress-on-pantheon' ) . '</a>' );

		return $links;
	}

	function add_panel( $panels ) {
		require_once( SOLR_POWER_PATH . '/includes/class-solrpower-debug.php' );
		array_push( $panels, new SolrPower_Debug() );

		return $panels;
	}

	/**
	 * Enqueue and localize scripts.
	 */
	function add_scripts() {
		wp_enqueue_script( 'Solr_Facet', SOLR_POWER_URL . 'assets/js/facet.min.js', array( 'jquery' ) );
		$solr_options = solr_options();
		$allow_ajax   = isset( $solr_options['allow_ajax'] ) ? boolval( $solr_options['allow_ajax'] ) : false;
		$div_id       = isset( $solr_options['ajax_div_id'] ) ? esc_html( $solr_options['ajax_div_id'] ) : false;
		wp_localize_script( 'Solr_Facet', 'solr', array(
			'ajaxurl'           => admin_url( 'admin-ajax.php' ),
			'allow_ajax'        => $allow_ajax,
			'search_results_id' => $div_id
		) );
	}

	/**
	 * AJAX Callback for Facet Search
	 */
	function ajax_search() {
		// Strip out admin-ajax from pagination links:
		add_filter( 'paginate_links', function ( $url ) {
			$url = str_replace( 'wp-admin/admin-ajax.php', '', $url );
			$url = remove_query_arg( 'action', $url );

			return $url;
		} );

		// Allow an AJAX search.
		add_filter( 'solr_allow_ajax', '__return_true' );
		add_filter( 'solr_allow_admin', '__return_true' );

		$args = array(
			's'              => filter_input( INPUT_GET, 's', FILTER_SANITIZE_STRING ),
			'facets'         => filter_input( INPUT_GET, 'facet', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY ),
			'posts_per_page' => get_option( 'posts_per_page' )
		);

		$query = new WP_Query( $args );
		$query->get_posts();
		ob_start();
		// Check to see if an overwrite template exists in theme.
		$theme_template = locate_template( array( 'solr-search-results.php' ), true, true );
		if ( '' === $theme_template ) {
			include SOLR_POWER_PATH . '/views/templates/solr-search-results.php';
		}
		$the_posts    = ob_get_clean();
		$facet_widget = new SolrPower_Facet_Widget();

		$return = array(
			'posts'  => $the_posts,
			'facets' => $facet_widget->fetch_facets( false )
		);

		echo wp_json_encode( $return );
		wp_die();
	}

}
