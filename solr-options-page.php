<?php
/*
    Copyright (c) 2009 Matt Weber

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
// Load up options.
$s4wp_settings = solr_options();

// Display a message if one is set.
if ( ! is_null( SolrPower_Options::get_instance()->msg ) ) {
	?>
	<div id="message" class="updated fade"><p>
			<strong><?php echo wp_kses_post( SolrPower_Options::get_instance()->msg ); ?></strong>
		</p></div>
	<?php
}
?>

<div class="wrap">
	<h2><?php esc_html_e( 'Solr Power', 'solr-for-wordpress-on-pantheon' ) ?></h2>


	<h2 class="nav-tab-wrapper" id="solr-tabs">
		<a class="nav-tab nav-tab-active" id="solr_info-tab"
		   href="#top#solr_info">Info</a>
		<a class="nav-tab" id="solr_indexing-tab"
		   href="#top#solr_indexing">Indexing</a>
		<a class="nav-tab" id="solr_action-tab"
		   href="#top#solr_action">Actions</a>
		<a class="nav-tab" id="solr_query-tab" href="#top#solr_query">Query</a>
	</h2>

	<div id="solr_info" class="solrtab active">
		<?php
		$server_ping = SolrPower_Api::get_instance()->ping_server();
		?>
		<div style="width:50%;float:left;">
			<table class="widefat">
				<thead>
				<tr>
					<th colspan="2"><strong>Solr Configuration</strong></th>
				</tr>
				</thead>
				<tbody>
				<tr>
					<td>Ping Status:</td>
					<td><?php echo ( $server_ping ) ? '<span style="color:green">Successful</span>' : '<span style="color:red">Failed</span>'; ?></td>
				</tr>
				<tr>
					<td>Solr Server IP address:</td>
					<td><?php echo esc_html( getenv( 'PANTHEON_INDEX_HOST' ) ); ?></td>
				</tr>
				<tr>
					<td>Solr Server Port:</td>
					<td><?php echo esc_html( getenv( 'PANTHEON_INDEX_PORT' ) ); ?></td>
				</tr>
				<tr>
					<td>Solr Server Path:</td>
					<td><?php echo esc_html( SolrPower_Api::get_instance()->compute_path() ); ?></td>
				</tr>
				</tbody>

			</table>
		</div>
		<?php if ( $server_ping ) { ?>
			<div style="width:50%;float:left;">
				<table class="widefat">
					<thead>
					<tr>
						<th colspan="2"><strong>Indexing Stats by Post Type</strong></th>
					</tr>
					</thead>
					<tbody>
					<?php
					foreach ( SolrPower_Api::get_instance()->index_stats() as $type => $stat ) {
						?>
						<tr>
							<td><?php echo esc_html( $type ); ?>:</td>
							<td><?php echo absint( $stat ); ?></td>
						</tr>
					<?php } ?>
					</tbody>

				</table>
			</div>
		<?php } ?>
		<br style="clear:both;">
	</div>
	<?php
	if ( is_multisite() ) {
		$action='settings.php?page=solr-power';
	} else {
		$action='options-general.php?page=solr-power';
	}
	include 'views/options/indexing.php';
	include 'views/options/action.php';
	include 'views/options/query.php';
	?>


</div>

<script>
	jQuery(document).ready(function () {
		jQuery('#solr-tabs').find('a').click(function () {
				jQuery('#solr-tabs').find('a').removeClass('nav-tab-active');
				jQuery('.solrtab').removeClass('active');

				var id = jQuery(this).attr('id').replace('-tab', '');
				jQuery('#' + id).addClass('active');
				jQuery(this).addClass('nav-tab-active');
			}
		);

		// init
		var solrActiveTab = window.location.hash.replace('#top#', '');

		// default to first tab
		if (solrActiveTab === '' || solrActiveTab === '#_=_') {
			solrActiveTab = jQuery('.solrtab').attr('id');
		}

		jQuery('#' + solrActiveTab).addClass('active');
		jQuery('#' + solrActiveTab + '-tab').addClass('nav-tab-active');

		jQuery('.nav-tab-active').click();
	});

</script>
<style>
	.solrtab {
		display: none;
	}

	.solrtab.active {
		display: block;
	}
</style>
