<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * @var string $title
 * @var array  $subjects
 * @var array  $tabs      [ ['name' => string, 'url' => string, 'active' => bool] ]
 * @var string $list_url
 * @var string $new_url
 */
?>
<div class="wrap fs-lms-learning">
	<h1><?php echo esc_html( $title ); ?></h1>

	<?php if ( empty( $subjects ) ) : ?>

		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Нет доступных предметов. Сначала создайте предмет в разделе «Предметы».', 'fs-lms' ); ?></p>
		</div>

	<?php else : ?>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab ) : ?>
				<a class="nav-tab<?php echo $tab['active'] ? ' nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $tab['url'] ); ?>">
					<?php echo esc_html( $tab['name'] ); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<?php if ( '' !== $list_url ) : ?>
			<div class="fs-lms-bank-actions">
				<a class="button button-primary" href="<?php echo esc_url( $list_url ); ?>">
					<?php esc_html_e( 'Открыть список', 'fs-lms' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $new_url ); ?>">
					<?php esc_html_e( 'Добавить', 'fs-lms' ); ?>
				</a>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div>
