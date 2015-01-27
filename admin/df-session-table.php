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
<style>

.df-wc-session-table {
	border-collapse: collapse;
	border-spacing: 0;
}

.df-wc-session-table th,
.df-wc-session-table td {
	border-collapse: collapse;
	padding: 5px;
}

.df-wc-session-table td {
	border: solid black 1px;
}

.df-wc-session-table tbody th {
	text-align: right;
}

.df-wc-session-table td {
	text-align: center;
}

.df-wc-session-table td.control {
	text-align: left;
	border: none;
}

</style>
<div class="wrap">
	<h2><?php echo $title ?></h2>

	<?php if ( $notice ) { ?>
		<div class="updated"><p><?php echo $notice ?></p></div>
	<?php } ?>

	<table class="df-wc-session-table">
		<thead>
			<tr>
				<th></th>
				<th>Total</th>
				<th>User</th>
				<th>Guest</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<th>All Sessions</th>
				<td><?php echo $all_sessions['total'] ?></td>
				<td><?php echo $all_sessions['user'] ?></td>
				<td><?php echo $all_sessions['guest'] ?></td>
				<td class="control">
					<a class="button" href="<?php echo $clear_all_sessions_url ?>">Clear All Sessions</a>
					<span class="description">Warning: This will empty carts of all active sessions</span>
				</td>
			</tr>
			<tr>
				<th>Expired Sessions</th>
				<td><?php echo $expired_sessions['total'] ?></td>
				<td><?php echo $expired_sessions['user'] ?></td>
				<td><?php echo $expired_sessions['guest'] ?></td>
				<td class="control">
					<a class="button" href="<?php echo $clear_expired_sessions_url ?>">Clear Expired Sessions</a>
					<span class="description">These are automatically cleaned up twice a day.</span>
				</td>
			</tr>
		</tbody>
	</table>
</div>