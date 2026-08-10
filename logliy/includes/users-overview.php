<?php
/**
 * Admin overview: Passkeys + last login per user.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Count Passkeys per user (batch).
 *
 * @param list<int> $user_ids User IDs.
 * @return array<int, int> user_id => count
 */
function logliy_db_passkey_counts_for_users( array $user_ids ): array {
	$user_ids = array_values( array_filter( array_map( 'absint', $user_ids ) ) );
	if ( $user_ids === array() ) {
		return array();
	}
	global $wpdb;
	$table  = logliy_credentials_table();
	$place  = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; placeholders for IDs.
	$sql    = $wpdb->prepare( "SELECT user_id, COUNT(*) AS cnt FROM {$table} WHERE user_id IN ($place) GROUP BY user_id", $user_ids );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$rows   = $wpdb->get_results( $sql, ARRAY_A );
	$out    = array_fill_keys( $user_ids, 0 );
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$out[ (int) $row['user_id'] ] = (int) $row['cnt'];
		}
	}
	return $out;
}

/**
 * Render users overview (called from settings tab).
 */
function logliy_render_users_overview(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per_page = 20;
	$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$args = array(
		'number'  => $per_page,
		'offset'  => ( $page - 1 ) * $per_page,
		'orderby' => 'registered',
		'order'   => 'DESC',
		'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name' ),
	);
	if ( $search !== '' ) {
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
	}

	$query = new WP_User_Query( $args );
	$users = $query->get_results();
	$total = (int) $query->get_total();
	$ids   = array_map(
		static function ( $u ) {
			return (int) $u->ID;
		},
		$users
	);
	$counts = logliy_db_passkey_counts_for_users( $ids );
	$base   = admin_url( 'options-general.php?page=' . LOGLIY_ADMIN_PAGE . '&tab=users' );
	?>
	<div class="lg-card">
		<h2><?php echo esc_html__( 'Users overview', 'logliy' ); ?></h2>
		<form method="get" style="margin-bottom:12px">
			<input type="hidden" name="page" value="<?php echo esc_attr( LOGLIY_ADMIN_PAGE ); ?>" />
			<input type="hidden" name="tab" value="users" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search users…', 'logliy' ); ?>" />
			<button type="submit" class="button"><?php echo esc_html__( 'Search', 'logliy' ); ?></button>
		</form>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'User', 'logliy' ); ?></th>
					<th><?php echo esc_html__( 'Email', 'logliy' ); ?></th>
					<th><?php echo esc_html__( 'Passkeys', 'logliy' ); ?></th>
					<th><?php echo esc_html__( 'Last login', 'logliy' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( $users === array() ) : ?>
				<tr><td colspan="5"><?php echo esc_html__( 'No users found.', 'logliy' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $users as $user ) : ?>
					<?php
					$last = (int) get_user_meta( (int) $user->ID, 'logliy_last_login', true );
					$cnt  = $counts[ (int) $user->ID ] ?? 0;
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $user->display_name !== '' ? $user->display_name : $user->user_login ); ?></strong>
							<br /><span class="description"><?php echo esc_html( $user->user_login ); ?></span>
						</td>
						<td><?php echo esc_html( $user->user_email ); ?></td>
						<td><?php echo esc_html( (string) $cnt ); ?></td>
						<td>
							<?php
							echo $last > 0
								? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) )
								: esc_html__( '—', 'logliy' );
							?>
						</td>
						<td><a href="<?php echo esc_url( get_edit_user_link( (int) $user->ID ) ); ?>"><?php echo esc_html__( 'Edit', 'logliy' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $base . ( $search !== '' ? '&s=' . rawurlencode( $search ) : '' ) ),
						'format'    => '',
						'current'   => $page,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
		?>
	</div>
	<?php
}
