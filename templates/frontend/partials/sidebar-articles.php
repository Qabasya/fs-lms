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
	<div class="fs-sidebar-head">
		<span class="fs-sidebar-title"><?php echo esc_html( $sidebar_articles_title ); ?></span>
		<?php if ( '' !== $sidebar_articles_url ) : ?>
			<?php // Стрелку рисует CSS (миксин fs-arrow-reveal): в покое её нет, появляется по ховеру. ?>
			<a href="<?php echo esc_url( $sidebar_articles_url ); ?>" class="fs-sidebar-more">Все материалы</a>
		<?php endif; ?>
	</div>

	<ul class="fs-sidebar-articles js-articles-list">
		<?php foreach ( $sidebar_articles as $article ) : ?>
			<li>
				<a href="<?php echo esc_url( $article['url'] ); ?>">
					<?php // Нет обложки — на её месте заглушка: карточки остаются одного размера. ?>
					<?php if ( ! empty( $article['thumbnail'] ) ) : ?>
						<img class="fs-sidebar-article-thumb"
							src="<?php echo esc_url( $article['thumbnail'] ); ?>"
							alt="" loading="lazy" decoding="async" />
					<?php else : ?>
						<span class="fs-sidebar-article-thumb fs-sidebar-article-thumb--empty" aria-hidden="true"></span>
					<?php endif; ?>
					<span class="fs-sidebar-article-text">
						<span class="fs-sidebar-article-title"><?php echo esc_html( $article['title'] ); ?></span>
						<?php // Не ссылка, а подпись внутри неё: вложенные <a> недопустимы. ?>
						<span class="fs-sidebar-article-go">Перейти</span>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
