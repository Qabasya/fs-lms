<?php
/**
 * Навигация по статьям одного номера задания — под текстом статьи.
 *
 * Собственный компонент страницы статьи (`components/article/_nav.scss`),
 * с блоком страницы задания (`.fs-task-nav`) больше не общий: по макету это
 * пара карточек с обложкой и описанием, а над ними — счётчик позиции и ссылка
 * на учебник.
 *
 * @var \Inc\DTO\Article\ArticleNavigationDTO $navigation Данные навигации.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

// Переключать нечего: номер задания не проставлен либо статья в серии одна.
if ( ! $navigation instanceof \Inc\DTO\Article\ArticleNavigationDTO || $navigation->isEmpty() ) {
	return;
}

$nav_counter = $navigation->position . ' из ' . $navigation->total;

// Надзаголовки сторон. На краях серия замыкается в кольцо, и переход честнее
// назвать не «Следующей», а концом, к которому он ведёт.
$nav_labels = array(
	'prev' => array( 'Предыдущая', 'Последняя статья' ),
	'next' => array( 'Следующая', 'Первая статья' ),
);
?>
<nav class="fs-article-nav" aria-label="Навигация по статьям">
	<div class="fs-article-nav__head">
		<span class="fs-article-nav__counter"><?php echo esc_html( $nav_counter ); ?></span>
		<?php // Стрелку рисует CSS (миксин fs-arrow-reveal) — как у «Все курсы» в сайдбаре. ?>
		<?php if ( '' !== $navigation->articles_url ) : ?>
			<a href="<?php echo esc_url( $navigation->articles_url ); ?>" class="fs-article-nav__all">Все статьи темы</a>
		<?php endif; ?>
	</div>

	<div class="fs-article-nav__pair">
		<?php
		$nav_sides = array(
			'prev' => $navigation->prev,
			'next' => $navigation->next,
		);

		foreach ( $nav_sides as $nav_key => $nav_article ) :
			$is_next   = 'next' === $nav_key;
			$nav_label = $nav_labels[ $nav_key ][ $nav_article->wrapped ? 1 : 0 ];
			?>
			<a href="<?php echo esc_url( $nav_article->url ); ?>"
				class="fs-article-nav__row fs-article-nav__row--<?php echo esc_attr( $nav_key ); ?>">
				<?php // Нет обложки — заглушка того же размера: карточки сторон равны по высоте. ?>
				<?php if ( '' !== $nav_article->thumbnail ) : ?>
					<img class="fs-article-nav__thumb"
						src="<?php echo esc_url( $nav_article->thumbnail ); ?>"
						alt="" loading="lazy" decoding="async" />
				<?php else : ?>
					<span class="fs-article-nav__thumb fs-article-nav__thumb--empty" aria-hidden="true"></span>
				<?php endif; ?>

				<span class="fs-article-nav__text">
					<span class="fs-article-nav__kicker">
						<?php if ( ! $is_next ) : ?>
							<?php echo Icon::ChevronLeft->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php echo esc_html( $nav_label ); ?>
						<?php if ( $is_next ) : ?>
							<?php echo Icon::ChevronRight->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</span>
					<span class="fs-article-nav__title"><?php echo esc_html( $nav_article->title ); ?></span>
					<?php if ( '' !== $nav_article->description ) : ?>
						<span class="fs-article-nav__desc"><?php echo esc_html( $nav_article->description ); ?></span>
					<?php endif; ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</nav>
