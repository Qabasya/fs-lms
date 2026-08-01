<?php
/**
 * Хлебные крошки публичных страниц предмета.
 *
 * Общий партиал страницы задания и «Всех заданий»: одна разметка — одни стили
 * (`src/scss/frontend/components/_breadcrumbs.scss`). Данные готовит
 * {@see \Inc\Controllers\Builders\BreadcrumbsBuilder}.
 *
 * @var array<int, array<string, mixed>> $crumbs Список крошек: label, url, current.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$crumbs = array_values( array_filter( (array) ( $crumbs ?? array() ) ) );

if ( empty( $crumbs ) ) {
	return;
}
?>
<nav class="fs-breadcrumbs" aria-label="Хлебные крошки">
	<?php foreach ( $crumbs as $index => $crumb ) : ?>
		<?php if ( $index > 0 ) : ?>
			<span class="fs-breadcrumbs__sep" aria-hidden="true">/</span>
		<?php endif; ?>

		<?php if ( ! empty( $crumb['url'] ) && empty( $crumb['current'] ) ) : ?>
			<a href="<?php echo esc_url( $crumb['url'] ); ?>"><?php echo esc_html( $crumb['label'] ); ?></a>
		<?php elseif ( ! empty( $crumb['current'] ) ) : ?>
			<span class="fs-breadcrumbs__current" aria-current="page"><?php echo esc_html( $crumb['label'] ); ?></span>
		<?php else : ?>
			<span><?php echo esc_html( $crumb['label'] ); ?></span>
		<?php endif; ?>
	<?php endforeach; ?>
</nav>