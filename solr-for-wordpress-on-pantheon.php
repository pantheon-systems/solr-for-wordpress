<?php
/*
  Plugin Name: Solr for WordPress on Pantheon
  Donate link: http://www.mattweber.org
  Description: Allows Pantheon sites to use Solr for searching.
  Version: 0.6.0
  Author: Pantheon, Matt Weber
  Author URI: http://pantheon.io
 */
/*
  Copyright (c) 2011 Matt Weber

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in
  all copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
  THE SOFTWARE.
 */

/*
 * NOTE: We have had to hack the Solarium Curl class to get it to support
 * https:. There is probably a better way to do this and a future version
 * may include a new "Pantheon" provider for Solarium. Until then, if you
 * do a composer update, and it updates, Solarium, things WILL STOP
 * WORKING.
 *
 * Make a backup!
 *  - Cal
 *
 * @TODO refactor as an object
 *
 */

require_once(dirname(__FILE__) . '/vendor/autoload.php');
register_activation_hook( __FILE__, 's4wp_activate' );

function s4wp_submit_schema() {
	// Solarium does not currently support submitting schemas to the server.
	// So we'll do it ourselves

	$returnValue = '';
	$path	   = s4wp_compute_path();
	$schema   = dirname(__FILE__) . '/schema.xml';
	$url		 = 'https://'. getenv('PANTHEON_INDEX_HOST') . ':' .getenv('PANTHEON_INDEX_PORT') . '/' . $path;
	$client_cert = realpath(ABSPATH.'../certs/binding.pem');
		
	/*
	* A couple of quick checks to make sure eveyrthing seems sane
	*/
	if ( $errorMessage=s4wp_sanity_check() ) {
		return $errorMessage;
	}

	if ( !file_exists($schema) ) {
		return $schema . ' does not exist.';
	}

	if ( !file_exists($client_cert) ) {
		return $client_cert . ' does not exist.';
	}


	$file = fopen($schema, 'r');
	// set URL and other appropriate options
	$opts = array(
		CURLOPT_URL		       => $url,
		CURLOPT_PORT		   => 449,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_SSLCERT	       => $client_cert,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_HTTPHEADER     => array('Content-type:text/xml; charset=utf-8'),
		CURLOPT_PUT		       => TRUE,
		CURLOPT_BINARYTRANSFER => 1,
		CURLOPT_INFILE	       => $file,
		CURLOPT_INFILESIZE     => filesize($schema),
	);

	$ch = curl_init();
	curl_setopt_array($ch, $opts);

	$response  = curl_exec($ch);
	$curl_opts = curl_getinfo($ch);

	fclose($file);

	$returnValue = ((int)$curl_opts['http_code'] === 200) ? '' : 'Error: ' . $curl_opts['http_code'];

	return $returnValue;
}


function s4wp_activate() {

	// Check to see if we have  environment variables. If not, bail. If so, create the initial options.

	if ( $errMessage=s4wp_sanity_check() ) {
		 wp_die($errMessage);
	}

	if ( $errorMessage = s4wp_submit_schema() ) {
		wp_die('Submitting the schema failed with the message ' . $errorMessage);
	}
	$options = s4wp_initalize_options();
	s4wp_update_option($options);
	return;
}


function s4wp_sanity_check() {
	$returnValue = '';
	$wp_version  = get_bloginfo('version');

	if ( getenv('PANTHEON_INDEX_HOST')===false ) {
		$returnValue = __( 'Before you can activate this plugin, you must first activate Solr in your Pantheon Dashboard.', 'solr-for-wordpress-on-pantheon' );
	} elseif ( version_compare($wp_version, '3.0', '<') ) {
		$returnValue = __( 'This plugin requires WordPress 3.0 or greater.', 'solr-for-wordpress-on-pantheon' );
	}

	return $returnValue;
}


function s4wp_get_option() {
	$indexall = FALSE;
	$option   = 'plugin_s4wp_settings';

	if ( is_multisite() ) {
		$plugin_s4wp_settings = get_site_option($option);
		$indexall = $plugin_s4wp_settings['s4wp_index_all_sites'];
	}

	if ( $indexall ) {
		return get_site_option($option);
	} else {
		return get_option($option);
	}
}

function s4wp_update_option( $optval ) {
	$indexall = FALSE;
	$option = 'plugin_s4wp_settings';
	if ( is_multisite() ) {
		$plugin_s4wp_settings = get_site_option($option);
		$indexall = $plugin_s4wp_settings['s4wp_index_all_sites'];
	}

	if ( $indexall ) {
		update_site_option($option, $optval);
	} else {
		update_option($option, $optval);
	}
}

/**
 * Connect to the solr service
 * @return solr service object
 */
function s4wp_get_solr() {
	# get the connection options
	$plugin_s4wp_settings = s4wp_get_option();

	$solarium_config = array(
		'endpoint' => array(
			'localhost'  => array(
				'host'   => getenv('PANTHEON_INDEX_HOST'),
				'port'   => getenv('PANTHEON_INDEX_PORT'),
				'scheme' => 'https',
				'path'   => s4wp_compute_path(),
				'ssl'    => array('local_cert' => realpath(ABSPATH.'../certs/binding.pem')),
			)
		)
	);

	apply_filters('s4wp_connection_options',$solarium_config);


	# double check everything has been set
	if ( !($solarium_config['endpoint']['localhost']['host'] and
		   $solarium_config['endpoint']['localhost']['port'] and
		   $solarium_config['endpoint']['localhost']['path']) ) {
		syslog(LOG_ERR, "host, port or path are empty, host:$host, port:$port, path:$path");
		return NULL;
	}


	$solr = new Solarium\Client($solarium_config);

	apply_filters('s4wp_solr',$solr); // better name?
	return $solr;
}

/**
 * check if the server by pinging it
 * @return boolean
 */
function s4wp_ping_server() {
	$solr = s4wp_get_solr();
	try {
		$solr->ping($solr->createPing());
		return true;
	} catch (Solarium\Exception $e) {
		return false;
	}
}


/**
 * build the path that the Solr server uses
 * @return string
 */
function s4wp_compute_path()
{
	return '/sites/self/environments/' . getenv('PANTHEON_ENVIRONMENT') . '/index';
}


function s4wp_build_document( Solarium\QueryType\Update\Query\Document\Document $doc, $post_info, $domain = NULL, $path = NULL ) {
	$plugin_s4wp_settings = s4wp_get_option();
	$exclude_ids		  = $plugin_s4wp_settings['s4wp_exclude_pages'];
	$categoy_as_taxonomy  = $plugin_s4wp_settings['s4wp_cat_as_taxo'];
	$index_comments	      = $plugin_s4wp_settings['s4wp_index_comments'];
	$index_custom_fields  = $plugin_s4wp_settings['s4wp_index_custom_fields'];

	if ( $post_info ) {

		# check if we need to exclude this document
		if ( is_multisite() && in_array(substr(site_url(), 7) . $post_info->ID, (array) $exclude_ids) ) {
			return NULL;
		} elseif ( !is_multisite() && in_array($post_info->ID, (array) $exclude_ids) ) {
			return NULL;
		}

		//$doc = new Apache_Solr_Document();
		$auth_info = get_userdata($post_info->post_author);

		# wpmu specific info
		if ( is_multisite() ) {
			// if we get here we expect that we've "switched" what blog we're running
			// as

			if ( $domain == NULL ) {
				$domain = $current_blog->domain;
			}

			if ( $path == NULL ) {
				$path = $current_blog->path;
			}


			$blogid = get_blog_id_from_url($domain, $path);
			$doc->setField('id', $domain . $path . $post_info->ID);
			$doc->setField('permalink', get_blog_permalink($blogid, $post_info->ID));
			$doc->setField('blogid', $blogid);
			$doc->setField('blogdomain', $domain);
			$doc->setField('blogpath', $path);
			$doc->setField('wp', 'multisite');
		} else {
			$doc->setField('id', $post_info->ID);
			$doc->setField('permalink', get_permalink($post_info->ID));
			$doc->setField('wp', 'wp');
		}

		$numcomments = 0;
		if ( $index_comments ) {
			$comments = get_comments("status=approve&post_id={$post_info->ID}");
			foreach ( $comments as $comment ) {
				$doc->addField( 'comments', $comment->comment_content );
				$numcomments += 1;
			}
		}

		$doc->setField( 'title',           $post_info->post_title );
		$doc->setField( 'content',         strip_tags( $post_info->post_content ) );
		$doc->setField( 'numcomments',     $numcomments );
		$doc->setField( 'author',          $auth_info->display_name );
		$doc->setField( 'author_s',        get_author_posts_url( $auth_info->ID, $auth_info->user_nicename ) );
		$doc->setField( 'type',            $post_info->post_type );
		$doc->setField( 'date',            s4wp_format_date( $post_info->post_date_gmt ) );
		$doc->setField( 'modified',        s4wp_format_date( $post_info->post_modified_gmt ) );
		$doc->setField( 'displaydate',     $post_info->post_date );
		$doc->setField( 'displaymodified', $post_info->post_modified );

		$categories = get_the_category( $post_info->ID );
		if ( !$categories == NULL ) {
			foreach ( $categories as $category ) {
				if ( $categoy_as_taxonomy ) {
					$doc->addField( 'categories', get_category_parents( $category->cat_ID, FALSE, '^^' ) );
				} else {
					$doc->addField( 'categories', $category->cat_name );
				}
			}
		}

		//get all the taxonomy names used by wp
		$taxonomies = (array) get_taxonomies( array('_builtin' => FALSE), 'names' );
		foreach ( $taxonomies as $parent ) {
			$terms = get_the_terms( $post_info->ID, $parent );
			if ( (array) $terms === $terms ) {
				//we are creating *_taxonomy as dynamic fields using our schema
				//so lets set up all our taxonomies in that format
				$parent = $parent . "_taxonomy";
				foreach ( $terms as $term ) {
					$doc->addField( $parent, $term->name );
				}
			}
		}

		$tags = get_the_tags( $post_info->ID );
		if ( !$tags == NULL ) {
			foreach ( $tags as $tag ) {
				$doc->addField( 'tags', $tag->name );
			}
		}

		if ( count( $index_custom_fields ) > 0 && count( $custom_fields = get_post_custom( $post_info->ID ) ) ) {
			foreach ((array) $index_custom_fields as $field_name) {
				$field = (array) $custom_fields[$field_name];
				foreach ( $field as $key => $value)  {
					$doc->addField( $field_name . '_str', $value );
					$doc->addField( $field_name . '_srch', $value );
				}
			}
		}
	} else {
		// this will fire during blog sign up on multisite, not sure why
		_e( 'Post Information is NULL', 'solr4wp' );
	}
	return $doc;
}

function s4wp_format_date( $thedate ) {
	$datere = '/(\d{4}-\d{2}-\d{2})\s(\d{2}:\d{2}:\d{2})/';
	$replstr = '${1}T${2}Z';
	return preg_replace( $datere, $replstr, $thedate );
}

function s4wp_post( $documents, $commit = TRUE, $optimize = FALSE ) {
	try {
		$solr = s4wp_get_solr();
		if ( !$solr == NULL ) {

			$update = $solr->createUpdate();

			if ( $documents ) {
				syslog( LOG_INFO, "posting " . count($documents) . " documents for blog:" . get_bloginfo('wpurl') );
				$update->addDocuments( $documents );
			}

			if ( $commit ) {
				syslog( LOG_INFO, "telling Solr to commit" );
				$update->addCommit();
				$solr->update( $update );
			}

			if ( $optimize ) {
				$update = $solr->createUpdate();
				$update->addOptimize();
				$solr->update( $update );
			}
		} else {
			syslog( LOG_ERR, "failed to get a solr instance created" );
		}
	} catch (Exception $e) {
		syslog( LOG_INFO, "ERROR: " . $e->getMessage() );
		//echo $e->getMessage();
	}
}

function s4wp_optimize() {
	try {
		$solr = s4wp_get_solr();
		if (!$solr == NULL) {
			$update = $solr->createUpdate();
			$update->addOptimize();
			$solr->update( $update );
		}
	} catch (Exception $e) {
		syslog(LOG_ERR, $e->getMessage());
	}
}

function s4wp_delete( $doc_id ) {
	try {
		$solr = s4wp_get_solr();
		if (!$solr == NULL) {
			$update = $solr->createUpdate();
			$update->addDeleteById( $doc_id );
			$update->addCommit();
			$solr->update( $update );
		}
	} catch (Exception $e) {
		syslog(LOG_ERR, $e->getMessage());
	}
}

function s4wp_delete_all() {
	try {
		$solr = s4wp_get_solr();
		if (!$solr == NULL) {
			$update = $solr->createUpdate();
			$update->addDeleteQuery( '*:*' );
			$update->addCommit();
			$solr->update( $update );
		}
	} catch (Exception $e) {
		echo $e->getMessage();
	}
}

function s4wp_delete_blog( $blogid ) {
	try {
		$solr = s4wp_get_solr();
		if (!$solr == NULL) {
			$update = $solr->createUpdate();
			$update->addDeleteQuery( "blogid:{$blogid}" );
			$update->addCommit();
			$solr->update( $update );
		}
	} catch (Exception $e) {
		echo $e->getMessage();
	}
}

function s4wp_load_blog_all( $blogid ) {
	global $wpdb;
	$documents = array();
	$cnt = 0;
	$batchsize = 10;

	$bloginfo = get_blog_details($blogid, FALSE);

	if ( $bloginfo->public && !$bloginfo->archived && !$bloginfo->spam && !$bloginfo->deleted ) {
		$postids = $wpdb->get_results( "SELECT ID FROM {$wpdb->base_prefix}{$blogid}_posts WHERE post_status = 'publish';" );

		$solr = s4wp_get_solr();
		$update = $solr->createUpdate();

		for ( $idx = 0; $idx < count( $postids ); $idx++ ) {
			$postid = $ids[$idx];
			$documents[] = s4wp_build_document( $update->createDocument(), 
												get_blog_post($blogid, $postid->ID), 
												$bloginfo->domain, 
												$bloginfo->path );
			$cnt++;

			if ( $cnt == $batchsize ) {
				s4wp_post( $documents );
				$cnt = 0;
				$documents = array();
			}
		}

		if ( $documents ) {
			s4wp_post( $documents );
		}
	}
}

function s4wp_handle_modified( $post_id ) {
	global $current_blog;
	$post_info = get_post( $post_id );
	$plugin_s4wp_settings = s4wp_get_option();
	$index_pages = $plugin_s4wp_settings['s4wp_index_pages'];
	$index_posts = $plugin_s4wp_settings['s4wp_index_posts'];

	s4wp_handle_status_change( $post_id, $post_info );

	if ( ( $index_pages && $post_info->post_type == 'page') || ( $index_posts && $post_info->post_type == 'post' ) ) {

		# make sure this blog is not private or a spam if indexing on a multisite install
		if (is_multisite() && ( $current_blog->public != 1 || $current_blog->spam == 1 || $current_blog->archived == 1 ) ) {
			return;
		}

		$docs = array();
		$solr = s4wp_get_solr();
		$update = $solr->createUpdate();
		$doc = s4wp_build_document( $update->createDocument(), $post_info );
		if ($doc) {
			$docs[] = $doc;
			s4wp_post($docs);
		}
	}
}

function s4wp_handle_status_change( $post_id, $post_info = null ) {
	global $current_blog;

	if ( !$post_info ) {
		$post_info = get_post( $post_id );
	}

	$plugin_s4wp_settings = s4wp_get_option();
	$private_page = $plugin_s4wp_settings['s4wp_private_page'];
	$private_post = $plugin_s4wp_settings['s4wp_private_post'];

	if ( ( $private_page && $post_info->post_type == 'page') || ($private_post && $post_info->post_type == 'post' ) ) {
		/**
		 * We need to check if the status of the post has changed.
		 * Inline edits won't have the prev_status of original_post_status,
		 * instead we check of the _inline_edit variable is present in the $_POST variable
		 */
		if ( ( ( isset($_POST['prev_status']) && $_POST['prev_status'] == 'publish' ) || 
			   (isset($_POST['original_post_status']) and $_POST['original_post_status'] == 'publish') ||
			   ( isset($_POST['_inline_edit']) && !empty($_POST['_inline_edit']) ) 
			 ) &&
			 ($post_info->post_status == 'draft' || $post_info->post_status == 'private')
		   ) {

			if ( is_multisite() ) {
				s4wp_delete( $current_blog->domain . $current_blog->path . $post_info->ID );
			} else {
				s4wp_delete( $post_info->ID );
			}
		}
	}
}

function s4wp_handle_delete( $post_id ) {
	global $current_blog;
	$post_info = get_post( $post_id );
	$plugin_s4wp_settings = s4wp_get_option();
	$delete_page = $plugin_s4wp_settings['s4wp_delete_page'];
	$delete_post = $plugin_s4wp_settings['s4wp_delete_post'];

	if ( ( $delete_page && $post_info->post_type == 'page' ) || ( $delete_post && $post_info->post_type == 'post' ) ) {
		if (is_multisite()) {
			s4wp_delete( $current_blog->domain . $current_blog->path . $post_info->ID );
		} else {
			s4wp_delete( $post_info->ID );
		}
	}
}

function s4wp_handle_deactivate_blog( $blogid ) {
	s4wp_delete_blog( $blogid );
}

function s4wp_handle_activate_blog( $blogid ) {
	s4wp_apply_config_to_blog( $blogid );
	s4wp_load_blog_all( $blogid );
}

function s4wp_handle_archive_blog( $blogid ) {
	s4wp_delete_blog( $blogid );
}

function s4wp_handle_unarchive_blog( $blogid ) {
	s4wp_apply_config_to_blog( $blogid );
	s4wp_load_blog_all( $blogid );
}

function s4wp_handle_spam_blog( $blogid ) {
	s4wp_delete_blog( $blogid );
}

function s4wp_handle_unspam_blog( $blogid ) {
	s4wp_apply_config_to_blog ($blogid );
	s4wp_load_blog_all( $blogid );
}

function s4wp_handle_delete_blog( $blogid ) {
	s4wp_delete_blog( $blogid );
}

function s4wp_handle_new_blog( $blogid ) {
	s4wp_apply_config_to_blog( $blogid );
	s4wp_load_blog_all( $blogid );
}

function s4wp_load_all_posts( $prev ) {
	global $wpdb, $current_blog, $current_site;
	$documents = array();
	$cnt = 0;
	$batchsize = 250;
	$last = "";
	$found = FALSE;
	$end = FALSE;
	$percent = 0;
	//multisite logic is decided s4wp_get_option
	$plugin_s4wp_settings = s4wp_get_option();
	$blog_id = $blog->blog_id;
	if ( $plugin_s4wp_settings['s4wp_index_all_sites'] ) {

		// there is potential for this to run for an extended period of time, depending on the # of blgos
		syslog( LOG_ERR, "starting batch import, setting max execution time to unlimited" );
		ini_set( 'memory_limit', '1024M' );
		set_time_limit( 0 );

		// get a list of blog ids
		$bloglist = $wpdb->get_col( "SELECT * FROM {$wpdb->base_prefix}blogs WHERE spam = 0 AND deleted = 0" , 0 );
		syslog( LOG_INFO, "pushing posts from " . count($bloglist) . " blogs into Solr" );
		
		foreach ( $bloglist as $bloginfo ) {
			// for each blog we need to import we get their id
			// and tell wordpress to switch to that blog
			$blog_id = trim( $bloginfo );
			syslog( LOG_INFO, "switching to blogid $blog_id" );

			// attempt to save some memory by flushing wordpress's cache
			wp_cache_flush();

			// everything just works better if we tell wordpress
			// to switch to the blog we're using, this is a multi-site
			// specific function
			switch_to_blog( $blog_id );

			// now we actually gather the blog posts
			$postids = $wpdb->get_results( "SELECT ID FROM {$wpdb->base_prefix}{$bloginfo}_posts WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID;" );
			$postcount = count( $postids);
			syslog( LOG_INFO, "building $postcount documents for " . substr(get_bloginfo('wpurl'), 7) );
			for ( $idx = 0; $idx < $postcount; $idx++ ) {

				$postid = $postids[ $idx ]->ID;
				$last = $postid;
				$percent = ( floatval($idx) / floatval($postcount) ) * 100;
				if ( $prev && !$found ) {
					if ( $postid === $prev ) {
						$found = TRUE;
					}

					continue;
				}

				if ( $idx === $postcount - 1 ) {
					$end = TRUE;
				}

				// using wpurl is better because it will return the proper
				// URL for the blog whether it is a subdomain install or otherwise
				$solr = s4wp_get_solr();
				$update = $solr->createUpdate();
				$documents[] = s4wp_build_document( $update->createDocument(), get_blog_post($blog_id, $postid), substr(get_bloginfo('wpurl'), 7), $current_site->path );
				$cnt++;
				if ( $cnt == $batchsize ) {
					s4wp_post( $documents, true, false );
					s4wp_post( false, true, false );
					wp_cache_flush();
					$cnt = 0;
					$documents = array();
				}
			}
			// post the documents to Solr
			// and reset the batch counters
			s4wp_post( $documents, true, false );
			s4wp_post( false, true, false );
			$cnt = 0;
			$documents = array();
			syslog( LOG_INFO, "finished building $postcount documents for " . substr( get_bloginfo('wpurl'), 7 ) );
			wp_cache_flush();
		}

		// done importing so lets switch back to the proper blog id
		restore_current_blog();
	} else {
		$posts = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'post' ORDER BY ID;" );
		$postcount = count( $posts );
		for ( $idx = 0; $idx < $postcount; $idx++ ) {
			$postid = $posts[ $idx ]->ID;
			$last = $postid;
			$percent = ( floatval( $idx ) / floatval( $postcount ) ) * 100;
			if ( $prev && !$found ) {
				if ( $postid === $prev ) {
					$found = TRUE;
				}
				continue;
			}

			if ( $idx === $postcount - 1 ) {
				$end = TRUE;
			}

			$solr = s4wp_get_solr();
			$update = $solr->createUpdate();
			$documents[] = s4wp_build_document( $update->createDocument(), get_post($postid) );
			$cnt++;
			if ( $cnt == $batchsize ) {
				s4wp_post( $documents, true, FALSE );
				$cnt = 0;
				$documents = array();
				wp_cache_flush();
				break;
			}
		}
	}

	if ( $documents ) {
		s4wp_post( $documents, true, FALSE );
	}

	if ( $end ) {
		s4wp_post( FALSE, TRUE, FALSE );
		printf( "{\"type\": \"post\", \"last\": \"%s\", \"end\": true, \"percent\": \"%.2f\"}", $last, $percent );
	} else {
		printf( "{\"type\": \"post\", \"last\": \"%s\", \"end\": false, \"percent\": \"%.2f\"}", $last, $percent );
	}
}

function s4wp_load_all_pages( $prev ) {
	global $wpdb;
	$documents = array();
	$cnt = 0;
	$batchsize = 100;
	$last = "";
	$found = FALSE;
	$end = FALSE;
	$percent = 0;
	$plugin_s4wp_settings = s4wp_get_option();

	if ( $plugin_s4wp_settings['s4wp_index_all_sites'] ) {
		$bloglist = $wpdb->get_col("SELECT * FROM {$wpdb->base_prefix}blogs", 0);

		foreach ( $bloglist as $bloginfo ) {
			$postids = $wpdb->get_results( "SELECT ID FROM {$wpdb->base_prefix}{$bloginfo->blog_id}_posts WHERE post_status = 'publish' AND post_type = 'page' ORDER BY ID;" );
			$postcount = count( $postids );

			for ($idx = 0; $idx < $postcount; $idx++) {
				$postid = $postids[ $idx ]->ID;
				$last = $postid;
				$percent = ( floatval( $idx ) / floatval( $postcount ) ) * 100;

				if ( $prev && !$found ) {
					if ($postid === $prev) {
						$found = TRUE;
					}

					continue;
				}

				if ( $idx === $postcount - 1 ) {
					$end = TRUE;
				}

				$solr = s4wp_get_solr();
				$update = $solr->createUpdate();
				$documents[] = s4wp_build_document( $update->createDocument(), 
													get_blog_post($bloginfo->blog_id, $postid), 
													$bloginfo->domain, 
													$bloginfo->path );
				$cnt++;

				if ( $cnt == $batchsize ) {
					s4wp_post( $documents, true, FALSE );
					$cnt = 0;
					$documents = array();
					break;
				}
			}
		}
	} else {
		$pages = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'page' ORDER BY ID;" );
		$pagecount = count( $pages );

		for ( $idx = 0; $idx < $pagecount; $idx++ ) {
			$pageid = $pages[$idx]->ID;
			$last = $pageid;
			$percent = ( floatval( $idx ) / floatval( $pagecount ) ) * 100;

			if ( $prev && !$found ) {

				if ( $pageid === $prev ) {
					$found = TRUE;
				}

				continue;
			}

			if ( $idx === $pagecount - 1 ) {
				$end = TRUE;
			}

			$solr = s4wp_get_solr();
			$update = $solr->createUpdate();
			$documents[] = s4wp_build_document( $update->createDocument(), get_post( $pageid ) );
			$cnt++;
			if ($cnt == $batchsize) {
				s4wp_post($documents, true, FALSE);
				$cnt = 0;
				$documents = array();
				break;
			}
		}
	}

	if ( $documents ) {
		s4wp_post( $documents, true, FALSE );
	}

	if ( $end ) {
		s4wp_post( FALSE, TRUE, FALSE );
		printf( "{\"type\": \"page\", \"last\": \"%s\", \"end\": true, \"percent\": \"%.2f\"}", $last, $percent );
	} else {
		printf( "{\"type\": \"page\", \"last\": \"%s\", \"end\": false, \"percent\": \"%.2f\"}", $last, $percent );
	}
}

function s4wp_search_form() {
	$sort   = filter_input( INPUT_GET,'sort',FILTER_SANITIZE_STRING );
	$order  = filter_input( INPUT_GET,'order',FILTER_SANITIZE_STRING );
	$server = filter_input( INPUT_GET,'server',FILTER_SANITIZE_STRING );


	if ( $sort == 'date' ) {
		$sortval = __( '<option value="score">Score</option><option value="date" selected="selected">Date</option><option value="modified">Last Modified</option>' );
	} elseif ( $sort == 'modified' ) {
		$sortval = __( '<option value="score">Score</option><option value="date">Date</option><option value="modified" selected="selected">Last Modified</option>' );
	} else {
		$sortval = __( '<option value="score" selected="selected">Score</option><option value="date">Date</option><option value="modified">Last Modified</option>' );
	}

	if ( $order == 'asc' ) {
		$orderval = __( '<option value="desc">Descending</option><option value="asc" selected="selected">Ascending</option>' );
	} else {
		$orderval = __( '<option value="desc" selected="selected">Descending</option><option value="asc">Ascending</option>' );
	}

	//if server id has been defined keep hold of it
	if ( $server ) {
		$serverval = '<input name="server" type="hidden" value="' . $server . '" />';
	}
	$form = __( '<form name="searchbox" method="get" id="searchbox" action=""><input type="text" id="qrybox" name="s" value="%s"/><input type="submit" id="searchbtn" /><label for="sortselect" id="sortlabel">Sort By:</label><select name="sort" id="sortselect">%s</select><label for="orderselect" id="orderlabel">Order By:</label><select name="order" id="orderselect">%s</select>%s</form>' );

	printf( $form, filter_input(INPUT_GET,'s',FILTER_SANITIZE_STRING), $sortval, $orderval, $serverval );
}

function s4wp_search_results() {
	$qry	= filter_input( INPUT_GET,'s',FILTER_SANITIZE_STRING );
	$offset = filter_input( INPUT_GET,'offset',FILTER_SANITIZE_STRING );
	$count  = filter_input( INPUT_GET,'count',FILTER_SANITIZE_STRING );
	$fq     = filter_input( INPUT_GET,'fq',FILTER_SANITIZE_STRING );
	$sort   = filter_input( INPUT_GET,'sort',FILTER_SANITIZE_STRING );
	$order  = filter_input( INPUT_GET,'order',FILTER_SANITIZE_STRING );
	$isdym  = filter_input( INPUT_GET,'isdym',FILTER_SANITIZE_STRING );
	$server = filter_input( INPUT_GET,'server',FILTER_SANITIZE_STRING );

	$plugin_s4wp_settings = s4wp_get_option();
	$output_info		  = $plugin_s4wp_settings['s4wp_output_info'];
	$output_pager	      = $plugin_s4wp_settings['s4wp_output_pager'];
	$output_facets	      = $plugin_s4wp_settings['s4wp_output_facets'];
	$results_per_page     = $plugin_s4wp_settings['s4wp_num_results'];
	$categoy_as_taxonomy  = $plugin_s4wp_settings['s4wp_cat_as_taxo'];

	$out = array();

	if ( !$qry ) {
		$qry = '';
	}
	//if server value has been set lets set it up here
	// and add it to all the search urls henceforth
	$serverval = isset($server) ? ( '&server=' . $server ) : '';

	# set some default values
	if ( !$offset ) {
		$offset = 0;
	}

	# only use default if not specified in post information
	if ( !$count ) {
		$count = $results_per_page;
	}

	if ( !$fq ) {
		$fq = '';
	}

	if ( $sort && $order ) {
		$sortby = $sort;
	} else {
		$sortby = '';
		$order = '';
	}

	if ( !$isdym ) {
		$isdym = 0;
	}

	$fqstr		    = '';
	$fqitms	        = explode('||',$fq);
	$selectedfacets = array();

	foreach ( $fqitms as $fqitem ) {

		if ( $fqitem ) {
			$splititm = explode( ':', $fqitem );
			$selectedfacet = array();
			$selectedfacet['name'] = sprintf( __("%s:%s"), ucwords( preg_replace( '/_str$/i', '', $splititm[0] ) ), str_replace( "^^", "/", $splititm[1] ) );
			$removelink = '';
			foreach ( $fqitms as $fqitem2 ) {
				if ( $fqitem2 && !( $fqitem2 === $fqitem ) ) {
					$splititm2 = explode( ':', $fqitem2 );
					$removelink = $removelink . urlencode( '||' ) . $splititm2[0] . ':' . urlencode( $splititm2[1] );
				}
			}

			if ( $removelink ) {
				$selectedfacet['removelink'] = htmlspecialchars( sprintf( __( "?s=%s&fq=%s" ), urlencode( $qry ), $removelink ) );
			} else {
				$selectedfacet['removelink'] = htmlspecialchars(sprintf(__("?s=%s"), urlencode($qry)));
			}
			//if server is set add it on the end of the url
			$selectedfacet['removelink'] .= $serverval;

			$fqstr = $fqstr . urlencode( '||' ) . $splititm[0] . ':' . urlencode( $splititm[1] );

			$selectedfacets[] = $selectedfacet;
		}
	}

	if ( $qry ) {
		$results = s4wp_query( $qry, $offset, $count, $fqitms, $sortby, $order, $server );

		if ( $results ) {
			$data	  = $results->getData();
			$response = $data['response'];
			$header   = $data['responseHeader'];
			$teasers  = $results->getHighlighting()->getResults();

			if ($output_info) {
				$out['hits'] = $response['numFound'];
				$out['qtime'] = sprintf( __( "%.3f" ), $header['QTime'] / 1000 );

			} else {
				$out[ 'hits' ] = 0;
			}

			if ($output_pager) {
				# calculate the number of pages
				$numpages = ceil( $response['numFound'] / $count );
				$currentpage = ceil( $offset / $count ) + 1;
				$pagerout = array();

				if ( $numpages == 0 ) {
					$numpages = 1;
				}

				foreach ( range( 1, $numpages ) as $pagenum ) {
					if ( $pagenum != $currentpage ) {
						$offsetnum = ( $pagenum - 1 ) * $count;
						$pageritm = array();
						$pageritm['page'] = sprintf( __("%d"), $pagenum );
						if ( !isset( $sortby ) || $sortby == "" ) {
							$pagersortby = "score";
							$pagerorder  = "desc";
						} else {
							$pagersortby = $sortby;
							$pagerorder  = $order;
						}
						$pagerlink = sprintf( __( "?s=%s&offset=%d&count=%d&sort=%s&order=%s"), urlencode( $qry ), $offsetnum, $count, $pagersortby, $pagerorder );

						if ( $fqstr ) {
							$pagerlink .= '&fq=' . $fqstr;
						}

						$pageritm['link'] = htmlspecialchars( $pagerlink );
						//if server is set add it on the end of the url
						$pageritm['link'] .=isset( $pageritm['link'] )? $serverval : '';
						$pagerout[] = $pageritm;
					} else {
						$pageritm = array();
						$pageritm['page'] = sprintf(__("%d"), $pagenum);
						$pageritm['link'] = "";
						$pagerout[] = $pageritm;
					}

				}

				$out['pager'] = $pagerout;
			}

			if ( $output_facets ) {
				# handle facets
				$facetout = array();

				if ( $results->getFacetSet() ) {

					foreach ( $results->getFacetSet()->getFacets() as $facetfield => $facet ) {

						if ( !get_object_vars($facet) ) {
							//continue;
						}

						$facetinfo = array();
						$facetitms = array();
						$facetinfo['name'] = ucwords( preg_replace('/_str$/i', '', $facetfield ) );

						# categories is a taxonomy
						if ( $categoy_as_taxonomy && $facetfield == 'categories' ) {
							# generate taxonomy and counts
							$taxo = array();

							foreach ( $facet as $facetval => $facetcnt ) {
								$taxovals = explode( '^^', rtrim($facetval, '^^' ) );
								$taxo = s4wp_gen_taxo_array( $taxo, $taxovals );
							}

							$facetitms = s4wp_get_output_taxo( $facet, $taxo, '', $fqstr . $serverval, $facetfield );

						} else {

							foreach ( $facet as $facetval => $facetcnt ) {
								$facetitm = array();
								$facetitm['count'] = sprintf( __( "%d" ), $facetcnt );
								$facetitm['link'] = htmlspecialchars( sprintf( __( '?s=%s&fq=%s:%s%s', 'solr4wp' ), urlencode( $qry ), $facetfield, urlencode( '"' . $facetval . '"' ), $fqstr ) );

								//if server is set add it on the end of the url
								$facetitm['link'] .= $serverval;
								$facetitm['name'] = $facetval;
								$facetitms[] = $facetitm;
							}

						}

						$facetinfo['items'] = $facetitms;
						$facetout[$facetfield] = $facetinfo;
					}
				}

				$facetout['selected'] = $selectedfacets;
				$out['facets'] = $facetout;
			}

			$resultout = array();

			if ( $response['numFound'] != 0 ) {
				foreach ( $response['docs'] as $doc ) {

					$resultinfo = array();
					$docid	= strval( $doc['id'] );

					$resultinfo['permalink']   = $doc['permalink'];
					$resultinfo['title']	   = $doc['title'];
					$resultinfo['author']	   = $doc['author'];
					$resultinfo['authorlink']  = htmlspecialchars( $doc['author_s'] );
					$resultinfo['numcomments'] = $doc['numcomments'];
					$resultinfo['date']	       = $doc['displaydate'];

					if ( $doc['numcomments'] === 0 ) {
						$resultinfo['comment_link'] = $doc['permalink'] . "#respond";
					} else {
						$resultinfo['comment_link'] = $doc['permalink'] . "#comments";
					}

					$resultinfo['score'] = $doc['score'];
					$resultinfo['id']    = $docid;

					$docteaser = $teasers[$docid];
					$docteaser = $docteaser->getFields();

					if ( $docteaser ) {
						$resultinfo['teaser'] = sprintf( __( "...%s..."), implode("...", $docteaser['content'] ) );
					} else {
						$words = explode( ' ', $doc['content'] );
						$teaser = implode( ' ', array_slice($words, 0, 30 ) );
						$resultinfo['teaser'] = sprintf( __( "%s..." ), $teaser );
					}

					$resultout[] = $resultinfo;
				}
			}

			$out['results'] = $resultout;
		}

	} else {
		$out['hits'] = "0";
	}

	# pager and results count helpers
	$out['query']	    = htmlspecialchars($qry);
	$out['offset']  	= strval($offset);
	$out['count']	    = strval($count);
	$out['firstresult'] = strval($offset + 1);
	$out['lastresult']  = strval(min($offset + $count, $out['hits'])); // hits doesn't exist
	$out['sortby']	    = $sortby;
	$out['order']	    = $order;
	$out['sorting']     = array(
	 		'scoreasc'     => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=score&order=asc%s', urlencode($qry), stripslashes($fq), $serverval)),
			'scoredesc'    => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=score&order=desc%s', urlencode($qry), stripslashes($fq), $serverval)),
			'dateasc'	   => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=date&order=asc%s', urlencode($qry), stripslashes($fq), $serverval)),
			'datedesc'     => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=date&order=desc%s', urlencode($qry), stripslashes($fq), $serverval)),
			'modifiedasc'  => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=modified&order=asc%s', urlencode($qry), stripslashes($fq), $serverval)),
			'modifieddesc' => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=modified&order=desc%s', urlencode($qry), stripslashes($fq), $serverval)),
			'commentsasc'  => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=numcomments&order=asc%s', urlencode($qry), stripslashes($fq), $serverval)),
			'commentsdesc' => htmlspecialchars(sprintf('?s=%s&fq=%s&sort=numcomments&order=desc%s', urlencode($qry), stripslashes($fq), $serverval)),
	);
	return $out;
}

function s4wp_print_facet_items( $items, 
	                             $pre          = "<ul>", 
	                             $post         = "</ul>", 
	                             $before       = "<li>", 
	                             $after        = "</li>", 
	                             $nestedpre    = "<ul>", 
	                             $nestedpost   = "</ul>", 
	                             $nestedbefore = "<li>", 
	                             $nestedafter  = "</li>") {
	if ( !$items ) {
		return;
	}

	printf( __( "%s\n" ), $pre );

	foreach ( $items as $item ) {
		printf( __("%s<a href=\"%s\">%s (%s)</a>%s\n" ), $before, $item["link"], $item["name"], $item["count"], $after );
		$item_items = isset($item["items"]) ? true : false;

		if ( $item_items ) {
			s4wp_print_facet_items( $item["items"], 
				                    $nestedpre, 
				                    $nestedpost, 
				                    $nestedbefore, 
				                    $nestedafter, 
				                    $nestedpre, 
				                    $nestedpost, 
				                    $nestedbefore, 
				                    $nestedafter);
		}
	}
	printf( __( "%s\n" ), $post );
}

function s4wp_get_output_taxo( $facet, $taxo, $prefix, $fqstr, $field ) {

	$qry = filter_input( INPUT_GET,'s',FILTER_SANITIZE_STRING );

	if ( count($taxo) == 0 ) {
		return;
	} else {
		$facetitms = array();

		foreach ( $taxo as $taxoname => $taxoval ) {
			$newprefix = $prefix . $taxoname . '^^';
			$facetvars = $facet->getValues();
			$facetitm = array();
			$facetitm['count'] = sprintf( __( "%d" ), $facetvars[ $newprefix ] );
			$facetitm['link'] = htmlspecialchars( sprintf( __( '?s=%s&fq=%s:%s%s', 'solr4wp'), $qry, $field, urlencode( '"' . $newprefix . '"' ), $fqstr ) );
			$facetitm['name'] = $taxoname;
			$outitms = s4wp_get_output_taxo($facet, $taxoval, $newprefix, $fqstr, $field);

			if ( $outitms ) {
				$facetitm['items'] = $outitms;
			}

			$facetitms[] = $facetitm;
		}

		return $facetitms;
	}
}

function s4wp_gen_taxo_array( $in, $vals ) {

	if ( count($vals) == 1 ) {
		if ( isset( $in[ $vals[0] ] ) && !$in[ $vals[0] ] ) {
			$in[$vals[0]] = array();
		}
		return $in;
	} else {
		$in[ $vals[0] ] = s4wp_gen_taxo_array($in[ $vals[0] ], array_slice( $vals, 1 ) );
		return $in;
	}

}

/**
 * Query the required server
 * passes all parameters to the appropriate function based on the server name
 * This allows for extensible server/core based query functions.
 * TODO allow for similar theme/output function
 */
function s4wp_query( $qry, $offset, $count, $fq, $sortby, $order, $server = 'master' ) {
	//NOTICE: does this needs to be cached to stop the db being hit to grab the options everytime search is being done.
	$plugin_s4wp_settings = s4wp_get_option();

	$solr = s4wp_get_solr();
// refactor this becase server id is no longer a thing. - Cal
	if ( !function_exists( $function = 's4wp_' . $server . '_query' ) ) {
		$function = 's4wp_master_query';
	}

	return $function( $solr, $qry, $offset, $count, $fq, $sortby, $order, $plugin_s4wp_settings );
}

function s4wp_master_query( $solr, $qry, $offset, $count, $fq, $sortby, $order, &$plugin_s4wp_settings ) {
	$response = NULL;
	$facet_fields = array();
	$number_of_tags = $plugin_s4wp_settings['s4wp_max_display_tags'];

	if ( $plugin_s4wp_settings['s4wp_facet_on_categories'] ) {
		$facet_fields[] = 'categories';
	}

	$facet_on_tags = $plugin_s4wp_settings['s4wp_facet_on_tags'];

	if ( $facet_on_tags ) {
		$facet_fields[] = 'tags';
	}

	if ( $plugin_s4wp_settings['s4wp_facet_on_author'] ) {
		$facet_fields[] = 'author';
	}

	if ( $plugin_s4wp_settings['s4wp_facet_on_type'] ) {
		$facet_fields[] = 'type';
	}

	$facet_on_custom_taxonomy = $plugin_s4wp_settings['s4wp_facet_on_taxonomy'];

	if ( count( $facet_on_custom_taxonomy ) ) {
		$taxonomies = (array) get_taxonomies(array('_builtin' => FALSE), 'names');
		foreach ($taxonomies as $parent) {
			$facet_fields[] = $parent . "_taxonomy";
		}
	}

	$facet_on_custom_fields = $plugin_s4wp_settings['s4wp_facet_on_custom_fields'];

	if ( is_array( $facet_on_custom_fields ) and count( $facet_on_custom_fields ) ) {

		foreach ( $facet_on_custom_fields as $field_name ) {
			$facet_fields[] = $field_name . '_str';
		}
	}

	if ( $solr ) {
		$select = array(
			//'handler'    => 'spell',
			  'query'      => $qry,
			  'fields'     => '*,score',
			  'start'      => $offset,
			  'rows'       => $count,
			  'omitheader' => false,
		);

		if ( $sortby != "" ) {
			$select['sort'] = array($sortby => $order);
		}

		$query = $solr->createSelect( $select );

		$dismax = $query->getDisMax();
		$dismax->setQueryFields( 'tagssrch^5 title^10 categoriessrch^5 content^3.5 comments^1.5' );
		$dismax->setPhraseFields( 'title^15 text^10' );

		$facetSet = $query->getFacetSet();

		foreach ( $facet_fields as $facet_field ) {
			$facetSet->createFacetField( $facet_field )->setField( $facet_field );
		}
		$facetSet->setMinCount(1);

		if ( $facet_on_tags ) {
			$facetSet->setLimit( $number_of_tags );
		}

		foreach ( $fq as $filter ) {
			if ( $filter !== "" ) {
				$query->createFilterQuery( $filter )->setQuery( $filter );
			}
		}

		//$hl = $query->getHighlighting();
		$query->getHighlighting()->setFields( 'content' );
		$query->getHighlighting()->setSimplePrefix( '<b>' );
		$query->getHighlighting()->setSimplePostfix( '</b>' );
		$query->getHighlighting()->setSnippets( '5' );
		$query->getHighlighting()->setFragsize( '50' );

		try {
			$response = $solr->select( $query );
			if ( !$response->getResponse()->getStatusCode() == 200 ) {
				$response = NULL;
			}
		} catch ( Exception $e ) {
			syslog( LOG_ERR, "failed to query solr for " . print_r( $qry, true ) );
			$response = NULL;
		}
	}

	return $response;
}

function s4wp_options_init() {

	$method = ( isset($_POST['method'])?$_POST['method']:'' ); // Totally guessing as to the default

	if ( $method === "load" ) {
		$type = $_POST['type'];
		$prev = $_POST['prev'];

		if ( $type === 'post' ) {
			s4wp_load_all_posts( $prev );
			exit;
		} elseif ( $type === 'page' ) {
			s4wp_load_all_pages( $prev );
			exit;
		} else {
			return;
		}
	}
	register_setting( 's4w-options-group', 'plugin_s4wp_settings', 's4wp_sanitise_options' );
}

/**
 * Sanitises the options values
 * @param $options array of s4w settings options
 * @return $options sanitised values
 */
function s4wp_sanitise_options( $options ) {
	//$options['s4wp_solr_host'] = wp_filter_nohtml_kses($options['s4wp_solr_host']);
	//$options['s4wp_solr_port'] = absint($options['s4wp_solr_port']);
	//$options['s4wp_solr_path'] = wp_filter_nohtml_kses($options['s4wp_solr_path']);
	//$options['s4wp_solr_update_host'] = wp_filter_nohtml_kses($options['s4wp_solr_update_host']);
	//$options['s4wp_solr_update_port'] = absint($options['s4wp_solr_update_port']);
	//$options['s4wp_solr_update_path'] = wp_filter_nohtml_kses($options['s4wp_solr_update_path']);
	$options['s4wp_index_pages']            = absint($options['s4wp_index_pages']);
	$options['s4wp_index_posts']            = absint($options['s4wp_index_posts']);
	$options['s4wp_index_comments']         = absint($options['s4wp_index_comments']);
	$options['s4wp_delete_page']            = absint($options['s4wp_delete_page']);
	$options['s4wp_delete_post']            = absint($options['s4wp_delete_post']);
	$options['s4wp_private_page']           = absint($options['s4wp_private_page']);
	$options['s4wp_private_post']           = absint($options['s4wp_private_post']);
	$options['s4wp_output_info']            = absint($options['s4wp_output_info']);
	$options['s4wp_output_pager']           = absint($options['s4wp_output_pager']);
	$options['s4wp_output_facets']          = absint($options['s4wp_output_facets']);
	$options['s4wp_exclude_pages']          = s4wp_filter_str2list($options['s4wp_exclude_pages']);
	$options['s4wp_num_results']            = absint($options['s4wp_num_results']);
	$options['s4wp_cat_as_taxo']            = absint($options['s4wp_cat_as_taxo']);
	$options['s4wp_max_display_tags']       = absint($options['s4wp_max_display_tags']);
	$options['s4wp_facet_on_categories']    = absint($options['s4wp_facet_on_categories']);
	$options['s4wp_facet_on_tags']          = absint($options['s4wp_facet_on_tags']);
	$options['s4wp_facet_on_author']        = absint($options['s4wp_facet_on_author']);
	$options['s4wp_facet_on_type']          = absint($options['s4wp_facet_on_type']);
	$options['s4wp_index_all_sites']        = absint($options['s4wp_index_all_sites']);
	$options['s4wp_connect_type']           = wp_filter_nohtml_kses($options['s4wp_connect_type']);
	$options['s4wp_index_custom_fields']    = s4wp_filter_str2list($options['s4wp_index_custom_fields']);
	$options['s4wp_facet_on_custom_fields'] = s4wp_filter_str2list($options['s4wp_facet_on_custom_fields']);

	return $options;
}

function s4wp_filter_str2list_numeric( $input ) {
	$final = array();

	if ( $input != "" ) {

		foreach ( explode(',', $input) as $val ) {
			$val = trim($val);

			if ( is_numeric($val) ) {
				$final[] = $val;
			}
		}
	}

	return $final;
}

function s4wp_filter_str2list( $input ) {
	$final = array();
	if ( $input != "" ) {
		foreach ( explode( ',', $input ) as $val ) {
			$final[] = trim( $val );
		}
	}

	return $final;
}

function s4wp_filter_list2str( $input ) {

	if ( !is_array( $input ) ) {
		return "";
	}

	$outval = implode( ',', $input );
	if ( !$outval ) {
		$outval = "";
	}

	return $outval;
}

function s4wp_add_pages() {
	$addpage = FALSE;

	if ( is_multisite() && is_site_admin() ) {
		$plugin_s4wp_settings = s4wp_get_option();
		$indexall = $plugin_s4wp_settings['s4wp_index_all_sites'];

		if ( ( $indexall && is_main_blog() ) || !$indexall ) {
			$addpage = TRUE;
		}
	} elseif ( !is_multisite() && is_admin() ) {
		$addpage = TRUE;
	}

	if ( $addpage ) {
		add_options_page( 'Solr Options', 'Solr Options', 'manage_options', __FILE__, 's4wp_options_page' );
	}
}

function s4wp_options_page() {

	if ( file_exists(dirname(__FILE__) . '/solr-options-page.php') ) {
		include( dirname(__FILE__) . '/solr-options-page.php' );
	} else {
		_e( "<p>Couldn't locate the options page.</p>", 'solr4wp' );
	}
}

function s4wp_admin_head() {
	// include our default css
	// This should be an enqueue
	if ( file_exists(dirname(__FILE__) . '/template/search.css') ) {
		printf( __( "<link rel=\"stylesheet\" href=\"%s\" type=\"text/css\" media=\"screen\" />\n"), plugins_url( '/template/search.css', __FILE__) );
	}
	?>
	<script type="text/javascript">
		var $j = jQuery.noConflict();

		function switch1() {
			if ($j('#solrconnect_single').is(':checked')) {
				$j('#solr_admin_tab2').css('display', 'block');
				$j('#solr_admin_tab2_btn').addClass('solr_admin_on');
				$j('#solr_admin_tab3').css('display', 'none');
				$j('#solr_admin_tab3_btn').removeClass('solr_admin_on');
			}
			if ($j('#solrconnect_separated').is(':checked')) {
				$j('#solr_admin_tab2').css('display', 'none');
				$j('#solr_admin_tab2_btn').removeClass('solr_admin_on');
				$j('#solr_admin_tab3').css('display', 'block');
				$j('#solr_admin_tab3_btn').addClass('solr_admin_on');
			}
		}


		function doLoad( type, prev ) {
			var pluginDirPath = "<?php plugin_dir_path( __FILE__ );?>";
			if ($prev == null) {
				$j.post("options-general.php?page=" + pluginDirPath + "solr-for-wordpress-on-pantheon.php", {method: "load", type: $type}, handleResults, "json");
			} else {
				$j.post("options-general.php?page=" + pluginDirPath + "solr-for-wordpress-on-pantheon.php", {method: "load", type: $type, prev: $prev}, handleResults, "json");
			}
		}

		function handleResults( data ) {
			$j('#percentspan').text(data.percent + "%");
			if (!data.end) {
				doLoad(data.type, data.last);
			} else {
				$j('#percentspan').remove();
				enableAll();
			}
		}

		function disableAll() {
			$j('[name=s4wp_postload]').attr('disabled', 'disabled');
			$j('[name=s4wp_deleteall]').attr('disabled', 'disabled');
			$j('[name=s4wp_init_blogs]').attr('disabled', 'disabled');
			$j('[name=s4wp_optimize]').attr('disabled', 'disabled');
			$j('[name=s4wp_pageload]').attr('disabled', 'disabled');
			$j('[name=s4wp_ping]').attr('disabled', 'disabled');
			$j('#settingsbutton').attr('disabled', 'disabled');
		}

		function enableAll() {
			$j('[name=s4wp_postload]').removeAttr('disabled');
			$j('[name=s4wp_deleteall]').removeAttr('disabled');
			$j('[name=s4wp_init_blogs]').removeAttr('disabled');
			$j('[name=s4wp_optimize]').removeAttr('disabled');
			$j('[name=s4wp_pageload]').removeAttr('disabled');
			$j('[name=s4wp_ping]').removeAttr('disabled');
			$j('#settingsbutton').removeAttr('disabled');
		}

		$percentspan = '<span style="font-size:1.2em;font-weight:bold;margin:20px;padding:20px" id="percentspan">0%</span>';

		$j(document).ready(function() {
			switch1();
			$j('[name=s4wp_postload]').click(function() {
				$j(this).after($percentspan);
				disableAll();
				doLoad("post", null);
				$j(this).preventDefault();
			});

			$j('[name=s4wp_pageload]').click(function() {
				$j(this).after($percentspan);
				disableAll();
				doLoad("page", null);
				$j(this).preventDefault();
			});
		});

	</script> <?php
}

function s4wp_default_head() {
	// include our default css
	// this should be an enqueue
	if ( file_exists(dirname(__FILE__) . '/template/search.css') ) {
		printf( __( "<link rel=\"stylesheet\" href=\"%s\" type=\"text/css\" media=\"screen\" />\n"), plugins_url('/template/search.css', __FILE__ ) );
	}
}

/*
 * @TODO change to echo statemnts and get rid of direct output.
 */
function s4wp_autosuggest_head() {
	// this should be an enqueue
	if ( file_exists( dirname( __FILE__ ) . '/template/autocomplete.css' ) ) {
		printf( __( "<link rel=\"stylesheet\" href=\"%s\" type=\"text/css\" media=\"screen\" />\n"), plugins_url('/template/autocomplete.css', __FILE__ ) );
	}
	?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			$("#s").suggest("?method=autocomplete", {});
			$("#qrybox").suggest("?method=autocomplete", {});
		});
	</script>
	<?php
}

function s4wp_template_redirect() {
	wp_enqueue_script('suggest');

	// not a search page; don't do anything and return
	// thanks to the Better Search plugin for the idea:  http://wordpress.org/extend/plugins/better-search/
	$search = stripos( $_SERVER['REQUEST_URI'], '?s=' );
	$autocomplete = stripos( $_SERVER['REQUEST_URI'], '?method=autocomplete' );

	if ( ( $search || $autocomplete ) == FALSE ) {
		return;
	}

	if ( $autocomplete ) {
		$q   = filter_input(INPUT_GET,'q',FILTER_SANITIZE_STRING);
		$limit = filter_input(INPUT_GET,'limit',FILTER_SANITIZE_STRING);

		s4wp_autocomplete( $q, $limit );
		exit;
	}

	// If there is a template file then we use it
	if ( file_exists( TEMPLATEPATH . '/s4wp_search.php' ) ) {
		// use theme file
		include_once( TEMPLATEPATH . '/s4wp_search.php' );
	} elseif ( file_exists( dirname( __FILE__ ) . '/template/s4wp_search.php' ) ) {
		// use plugin supplied file
		add_action( 'wp_head', 's4wp_default_head' );
		include_once( dirname( __FILE__ ) . '/template/s4wp_search.php' );
	} else {
		// no template files found, just continue on like normal
		// this should get to the normal WordPress search results
		return;
	}

	exit;
}

function solr_escape( $value )
{
	//list taken from http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
	$pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
	$replace = '\\\$1';

	return preg_replace( $pattern, $replace, $value );
}


function s4wp_mlt_widget() {
	register_widget('s4wp_MLTWidget');
}


/*
 * This probably needs to be in a seperate file.
 */
class s4wp_MLTWidget extends WP_Widget {

	function s4wp_MLTWidget() {
		$widget_ops = array('classname'   => 'widget_s4wp_mlt', 
							'description' => __( "Displays a list of pages similar to the page being viewed" ) );
		$this->WP_Widget( 'mlt', __('Similar'), $widget_ops );
	}

	function widget( $args, $instance ) {

		extract( $args );
		$title = apply_filters( 'widget_title', empty( $instance['title'] ) ? __( 'Similar' ) : $instance['title'] );
		$count = empty($instance['count']) ? 5 : $instance['count'];
		
		if ( !is_numeric($count) ) {
			$count = 5;
		}

		$showauthor = $instance['showauthor'];

		$solr = s4wp_get_solr();
		$response = NULL;

		if ( ( !is_single() && !is_page() ) || !$solr ) {
			return;
		}

		$query = $solr->createSelect();
		$query->setQuery( 'permalink:' . solr_escape(get_permalink()) )
			  ->getMoreLikeThis()
			  ->setFields('title,content');

		$response = $solr->select($query);

		if ( !$response->getResponse()->getStatusCode() == 200 ) {
			return;
		}

		echo $before_widget;

		if ( $title ) {
			echo $before_title . $title . $after_title;
		}

		$mltresults = $response->moreLikeThis;

		foreach ( $mltresults as $mltresult ) {
			$docs = $mltresult->docs;
			echo "<ul>";

			foreach ( $docs as $doc ) {

				if ( $showauthor ) {
					$author = " by {$doc->author}";
				}

				echo "<li><a href=\"{$doc->permalink}\" title=\"{$doc->title}\">{$doc->title}</a>{$author}</li>";
			}

			echo "</ul>";
		}

		echo $after_widget;
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = wp_parse_args( (array) $new_instance, 
									  array('title'      => '', 
									  	    'count'      => 5, 
									  	    'showauthor' => 0) );
		$instance['title'] = strip_tags( $new_instance['title'] );
		$cnt = strip_tags( $new_instance['count'] );
		$instance['count'] = is_numeric( $cnt ) ? $cnt : 5;
		$instance['showauthor'] = $new_instance['showauthor'] ? 1 : 0;

		return $instance;
	}

	function form( $instance ) {
		$instance = wp_parse_args( (array) $instance, 
								   array('title'      => '', 
								   	     'count'      => 5, 
								   	     'showauthor' => 0) );
		$title = strip_tags($instance['title']);
		$count = strip_tags($instance['count']);
		$showauthor = $instance['showauthor'] ? 'checked="checked"' : '';
		?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Count:'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo esc_attr($count); ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('showauthor'); ?>"><?php _e('Show Author?:'); ?></label>
			<input class="checkbox" type="checkbox" <?php echo $showauthor; ?> id="<?php echo $this->get_field_id('showauthor'); ?>" name="<?php echo $this->get_field_name('showauthor'); ?>" />
		</p>
		<?php
	}

}

function s4wp_autocomplete( $q, $limit ) {
	$solr = s4wp_get_solr();
	$response = NULL;

	if ( !$solr ) {
		return;
	}

	$query = $solr->createTerms();
	$query->setFields('spell');
	$query->setPrefix($q);
	$query->setLowerbound($q);
	$query->setLowerboundInclude(false);
	$query->setLimit($limit);

	$response = $solr->terms( $query );
	if (!$response->getResponse()->getStatusCode() == 200) {
		return;
	}

	$terms = $response->getResults();
	
	foreach ( $terms['spell'] as $term => $count ) {
		printf("%s\n", $term);
	}
}

// copies config settings from the main blog
// to all of the other blogs
function s4wp_copy_config_to_all_blogs() {
	global $wpdb;

	$blogs = $wpdb->get_results( "SELECT blog_id FROM $wpdb->blogs WHERE spam = 0 AND deleted = 0" );

	$plugin_s4wp_settings = s4wp_get_option();

	foreach ( $blogs as $blog ) {
		switch_to_blog( $blog->blog_id );
		wp_cache_flush();
		syslog( LOG_INFO, "pushing config to {$blog->blog_id}" );
		s4wp_update_option( $plugin_s4wp_settings );
	}

	wp_cache_flush();
	restore_current_blog();
}

function s4wp_apply_config_to_blog( $blogid ) {

	syslog( LOG_ERR, "applying config to blog with id $blogid" );
	
	if ( !is_multisite() )
		return;

	wp_cache_flush();
	$plugin_s4wp_settings = s4wp_get_option();
	switch_to_blog( $blogid );
	wp_cache_flush();
	s4wp_update_option( $plugin_s4wp_settings );
	restore_current_blog();
	wp_cache_flush();
}

function s4wp_initalize_options()
{
	$options = [];

	$options['s4wp_index_pages']			= 1;
	$options['s4wp_index_posts']			= 1;
	$options['s4wp_delete_page']			= 1;
	$options['s4wp_delete_post']			= 1;
	$options['s4wp_private_page']		    = 1;
	$options['s4wp_private_post']		    = 1;
	$options['s4wp_output_info']			= 1;
	$options['s4wp_output_pager']		    = 1;
	$options['s4wp_output_facets']		    = 1;
	$options['s4wp_exclude_pages']		    = array();
	$options['s4wp_exclude_pages']		    = '';
	$options['s4wp_num_results']			= 5;
	$options['s4wp_cat_as_taxo']			= 1;
	$options['s4wp_solr_initialized']	    = 1;
	$options['s4wp_max_display_tags']	    = 10;
	$options['s4wp_facet_on_categories']	= 1;
	$options['s4wp_facet_on_taxonomy']	    = 1;
	$options['s4wp_facet_on_tags']		    = 1;
	$options['s4wp_facet_on_author']		= 1;
	$options['s4wp_facet_on_type']	    	= 1;
	$options['s4wp_index_comments']	        = 1;
	$options['s4wp_connect_type']		    = 'solr';
	$options['s4wp_index_custom_fields']	= array();
	$options['s4wp_facet_on_custom_fields'] = array();
	$options['s4wp_index_custom_fields']	= '';
	$options['s4wp_facet_on_custom_fields'] = '';

	return $options;
}


function s4wp_plugin_settings_link( $links, $file ) {

	if ( $file != plugin_basename( __FILE__ ) ) {
		return $links;
	}

	array_unshift( $links, '<a href="' . admin_url( 'admin.php' ) . '?page=' . plugin_dir_path( __FILE__ ) . 'solr-for-wordpress-on-pantheon.php">' . __( 'Settings', 's4wp' ) . '</a>' );

	return $links;
}


add_action( 'template_redirect', 's4wp_template_redirect', 1 );
add_action( 'publish_post', 's4wp_handle_modified' );
add_action( 'publish_page', 's4wp_handle_modified' );
add_action( 'save_post', 's4wp_handle_modified' );
add_action( 'delete_post', 's4wp_handle_delete' );
add_action( 'admin_menu', 's4wp_add_pages' );
add_action( 'admin_init', 's4wp_options_init' );
add_action( 'widgets_init', 's4wp_mlt_widget' );
add_action( 'wp_head', 's4wp_autosuggest_head' );
add_action( 'admin_head', 's4wp_admin_head' );
add_filter( 'plugin_action_links', 's4wp_plugin_settings_link', 10, 2 );

if ( is_multisite() ) {
	add_action('deactivate_blog', 's4wp_handle_deactivate_blog' );
	add_action('activate_blog', 's4wp_handle_activate_blog' );
	add_action('archive_blog', 's4wp_handle_archive_blog' );
	add_action('unarchive_blog', 's4wp_handle_unarchive_blog' );
	add_action('make_spam_blog', 's4wp_handle_spam_blog' );
	add_action('unspam_blog', 's4wp_handle_unspam_blog' );
	add_action('delete_blog', 's4wp_handle_delete_blog' );
	add_action('wpmu_new_blog', 's4wp_handle_new_blog' );
}
