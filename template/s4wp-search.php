<?php
/*
Template Name: Search
*/
?>

<?php get_header(); ?>
    <div id="content">

    <div class="solr clearfix">

<?php
$results = s4wp_search_results();
if ( ! isset( $results['results'] )
     || null === $results['results']
) {
	echo "<div class='solr_noresult'><h2>Sorry, search is unavailable right now</h2><p>Try again later?</p></div>";
} else {
	?>

    <div class="solr1 clearfix">
        <div class="solr_search">
			<?php if ( $results['qtime'] ) {
				echo '<label class="solr_response">Response time: <span id="qrytime">' . esc_html( $results['qtime'] ) . '</span> s</label>';
			}

			//if server id has been defined keep hold of it
			$server = filter_input( INPUT_GET, 'server', FILTER_SANITIZE_STRING );
			if ( $server ) {
				$serverval = '<input name="server" type="hidden" value="' . $server . '" />';
			} else {
				$serverval = '';
			}

			?>

            <form name="searchbox" method="get" id="searchbox" action="">
                <input id="qrybox" name="s" type="text" class="solr_field"
                       value="<?php echo esc_attr( $results['query'] ) ?>"/>
				<?php echo esc_html( $serverval ); ?>
                <input id="searchbtn" type="submit" value="Search"/>
            </form>
        </div>

    </div>

    <div class="solr2">

        <div class="solr_results_header clearfix">
            <div class="solr_results_headerL">

				<?php if ( $results['hits'] && $results['query'] && $results['qtime'] ) {
					if ( $results['firstresult'] === $results['lastresult'] ) {
						printf( "Displaying result %s of <span id='resultcnt'>%s</span> hits", esc_html( $results['firstresult'] ), esc_html( $results['hits'] ) );
					} else {
						printf( "Displaying results %s-%s of <span id='resultcnt'>%s</span> hits", esc_html( $results['firstresult'] ), esc_html( $results['lastresult'] ), esc_html( $results['hits'] ) );
					}
				} ?>

            </div>
            <div class="solr_results_headerR">
                <ol class="solr_sort2">
                    <li class="solr_sort_drop"><a href="<?php echo esc_url( $results['sorting']['scoredesc'] ); ?>">Relevance<span></span></a>
                    </li>
                    <li><a href="<?php echo esc_url( $results['sorting']['datedesc'] ); ?>">Newest</a></li>
                    <li><a href="<?php echo esc_url( $results['sorting']['dateasc'] ); ?>">Oldest</a></li>
                    <li><a href="<?php echo esc_url( $results['sorting']['commentsdesc'] ); ?>">Most Comments</a>
                    </li>
                    <li><a href="<?php echo esc_url( $results['sorting']['commentsasc'] ); ?>">Least Comments</a>
                    </li>
                </ol>
                <div class="solr_sort">Sort by:</div>
            </div>
        </div>

        <div class="solr_results">

			<?php


			if ( 0 === $results['hits'] ) {
				printf( "<div class='solr_noresult'>
										<h2>Sorry, no results were found.</h2>
										<h3>Perhaps you mispelled your search query, or need to try using broader search terms.</h3>
										<p>For example, instead of searching for 'Apple iPhone 3.0 3GS', try something simple like 'iPhone'.</p>
									</div>\n" );
			} else {
				printf( "<ol>\n" );
				foreach ( $results['results'] as $result ) {

					printf( "<li onclick=\"window.location='%s'\">\n", esc_url( $result['permalink'] ) );
					printf( "<h2><a href='%s'>%s</a></h2>\n", esc_url( $result['permalink'] ), esc_html( $result['title'] ) );
					echo '<p>';
					foreach ( explode( '...', $result['teaser'] ) as $thisResult ) {
						if ( ! empty( $thisResult ) ) {
							echo '...' . wp_kses_post( $thisResult ) . '...<br /><br />';
						}
					}

					if ( $result['numcomments'] > 0 ) {
						printf( "<a href='%s'>(comment match)</a>", esc_url( $result['comment_link'] ) );
					}

					echo "</p>\n";

					printf( "<label> By <a href='%s'>%s</a> in %s %s - <a href='%s'>%s comments</a></label>\n",
						esc_url( $result['authorlink'] ),
						esc_html( $result['author'] ),
						wp_kses_post( get_the_category_list( ', ', '', $result['id'] ) ),
						esc_html( date( 'm/d/Y', strtotime( $result['date'] ) ) ),
						esc_url( $result['comment_link'] ),
						esc_url( $result['numcomments'] ) );
					printf( "</li>\n" );
				}
				printf( "</ol>\n" );
			} ?>

			<?php if ( $results['pager'] ) {
				printf( "<div class='solr_pages'>" );
				$itemlinks = array();
				$pagecnt   = 0;
				$pagemax   = 10;
				$next      = '';
				$prev      = '';
				$found     = false;
				foreach ( $results['pager'] as $pageritm ) {
					if ( $pageritm['link'] ) {
						if ( $found && '' === $next ) {
							$next = $pageritm['link'];
						} elseif ( false === $found ) {
							$prev = $pageritm['link'];
						}

						$itemlinks[] = sprintf( "<a href='%s'>%s</a>", esc_url( $pageritm['link'] ), esc_html( $pageritm['page'] ) );
					} else {
						$found       = true;
						$itemlinks[] = sprintf( "<a class='solr_pages_on' href='%s'>%s</a>", esc_url( $pageritm['link'] ), esc_html( $pageritm['page'] ) );
					}

					$pagecnt += 1;
					if ( $pagecnt === $pagemax ) {
						break;
					}
				}

				if ( '' !== $prev ) {
					printf( "<a href='%s'>Previous</a>", esc_url( $prev ) );
				}

				foreach ( $itemlinks as $itemlink ) {
					echo $itemlink; // XSS ok.
				}

				if ( '' !== $next ) {
					printf( "<a href='%s'>Next</a>", esc_url( $next ) );
				}

				printf( "</div>\n" );
			} ?>


        </div>
    </div>

    <div class="solr3">
        <ul class="solr_facets">

            <li class="solr_active">
                <ol>
					<?php if ( $results['facets']['selected'] ) {
						foreach ( $results['facets']['selected'] as $selectedfacet ) {
							printf( '<li><span></span><a href="%s">%s<b>x</b></a></li>', esc_url( $selectedfacet['removelink'] ), esc_html( $selectedfacet['name'] ) );
						}
					} ?>
                </ol>
            </li>

			<?php if ( $results['facets'] && 1 !== $results['hits'] ) {
				foreach ( $results['facets'] as $facet ) {
					if ( isset( $facet['items'] )
					     && count( $facet['items'] ) > 1
					) { #don't display facets with only 1 value
						printf( '<li>\n<h3>%s</h3>\n', esc_html( $facet['name'] ) );
						s4wp_print_facet_items( $facet['items'], '<ol>', '</ol>', '<li>', '</li>',
							'<li><ol>', '</ol></li>', '<li>', '</li>' );
						printf( "</li>\n" );
					}
				}
			} ?>

        </ul>
    </div>

    </div>

    </div>
	<?php

}
get_footer();
