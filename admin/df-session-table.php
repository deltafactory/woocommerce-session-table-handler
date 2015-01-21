<?php
global $title;

$action = !empty( $_REQUEST['action'] ) ? $_REQUEST['action'] : false;
$notice = false;

if ( $action ) {
	$wc_session = DF_Session_Loader::load_session_handler();

	switch( $action ) {
		case 'clear_all':
			check_admin_referer( 'df-session-clear' );
			$wc_session->destroy_all_sessions();
			wp_cache_flush();
			$notice = 'All sessions cleared.';
			break;

		case 'clear_expired':
			$wc_session->cleanup_sessions();
			wp_cache_flush();
			$notice = 'Expired sessions cleaned.';
			break;
	}
}

$all_sessions = DF_Session_Loader::count_sessions();
$expired_sessions = DF_Session_Loader::count_sessions( true );

$base_url = add_query_arg( 'page', $plugin_page, admin_url( 'admin.php' ) );
$clear_all_sessions_url = wp_nonce_url( add_query_arg( 'action', 'clear_all', $base_url ), 'df-session-clear' );
$clear_expired_sessions_url = wp_nonce_url( add_query_arg( 'action', 'clear_expired', $base_url ), 'df-session-clear' );
?>
<div class="wrap">
	<h2><?php echo $title ?></h2>

	<?php if ( $notice ) { ?>
		<div class="updated"><p><?php echo $notice ?></p></div>
	<?php } ?>

	<table class="form-table">
		<tr>
			<th>Sessions</th>
			<td>
				<p>Total: <?php echo $all_sessions['total'] ?></p>
				<p>User:  <?php echo $all_sessions['user'] ?></p>
				<p>Guest: <?php echo $all_sessions['guest'] ?></p>

				<p>
					<a class="button" href="<?php echo $clear_all_sessions_url ?>">Clear All Sessions</a>
					<span class="description">Warning: This will empty carts of all active sessions</span>
				</p>
			</td>
		</tr>

		<tr>
			<th>Expired Sessions</th>
			<td>
				<p>Total: <?php echo $expired_sessions['total'] ?></p>
				<p>User:  <?php echo $expired_sessions['user'] ?></p>
				<p>Guest: <?php echo $expired_sessions['guest'] ?></p>

				<p><a class="button" href="<?php echo $clear_expired_sessions_url ?>">Clear Expired Sessions</a></p>
			</td>
	</table>
</div>