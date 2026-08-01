<?php
/**
 * Блок статей в сайдбаре публичных страниц предмета.
 *
 * Общий партиал страницы задания и «Всех заданий»: одна разметка — одни стили
 * (`src/scss/frontend/components/_sidebar.scss`). На «Всех заданиях» список
 * перерисовывает JS (`components/sidebar-articles.js`) после смены фильтров.
 *
 * @var array  $sidebar_articles Статьи: id, title, url, excerpt, task_number.
 * @var string $sidebar_articles_title Заголовок блока (по умолчанию «Статьи»).
 * @var string $sidebar_articles_url Ссылка «Все материалы» (архив статей предмета).
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sidebar_articles       = (array) ( $sidebar_articles ?? array() );
$sidebar_articles_title = $sidebar_articles_title ?? 'Статьи';
$sidebar_articles_url   = (string) ( $sidebar_articles_url ?? '' );
?>
<section class="fs-sidebar-block js-articles-block" <?php echo empty( $sidebar_articles ) ? 'hidden' : ''; ?>>
	<div class="fs-sidebar-title"><?php echo esc_html( $sidebar_articles_title ); ?></div>

	<ul class="fs-sidebar-articles js-articles-list">
		<?php foreach ( $sidebar_articles as $article ) : ?>
			<li>
				<a href="<?php echo esc_url( $article['url'] ); ?>">
					<span class="fs-sidebar-article-title"><?php echo esc_html( $article['title'] ); ?></span>
					<?php if ( ! empty( $article['excerpt'] ) ) : ?>
						<span class="fs-sidebar-article-desc"><?php echo esc_html( $article['excerpt'] ); ?></span>
					<?php endif; ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( '' !== $sidebar_articles_url ) : ?>
		<a href="<?php echo esc_url( $sidebar_articles_url ); ?>" class="fs-sidebar-more">Все материалы →</a>
	<?php endif; ?>
</section>