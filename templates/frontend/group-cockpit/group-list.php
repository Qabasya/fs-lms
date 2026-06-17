<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// $groups — object[]
?>
<main class="fs-page-wrapper">
<div class="fs-lms-cockpit-wrap">
	<h1><?php esc_html_e( 'Мои группы', 'fs-lms' ); ?></h1>

	<?php if ( empty( $groups ) ) : ?>
		<p><?php esc_html_e( 'Нет доступных групп.', 'fs-lms' ); ?></p>
	<?php else : ?>
		<ul class="fs-cockpit-group-list">
			<?php foreach ( $groups as $g ) : ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'gid', $g->id, home_url( '/group/' ) ) ); ?>">
						<?php echo esc_html( $g->name ); ?>
					</a>
					<span class="fs-cockpit-group-subject"><?php echo esc_html( $g->subject_key ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
</main>
