<?php
/**
 * Урок недоступен ученику (гейт: дата/видимость/предусловие). T1.5.12.
 *
 * @var int $groupId
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cockpit_url = add_query_arg( array( 'gid' => $groupId ), \Inc\Enums\PageRoutes::GroupCockpit->url() );
?>
<div class="wrap fs-player fs-player--locked">
	<div class="notice notice-warning">
		<p><?php esc_html_e( 'Этот урок пока недоступен — он откроется по дате или после выполнения предыдущих шагов.', 'fs-lms' ); ?></p>
	</div>
	<a class="button" href="<?php echo esc_url( $cockpit_url ); ?>">← <?php esc_html_e( 'К программе группы', 'fs-lms' ); ?></a>
</div>
