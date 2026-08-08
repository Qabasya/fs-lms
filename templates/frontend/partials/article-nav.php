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

// Серии нет — у статьи не проставлен номер задания, переключать нечего.
if ( ! $navigation instanceof \Inc\DTO\Article\ArticleNavigationDTO || $navigation->isEmpty() ) {
	return;
}

if ( ! $navigation->prev && ! $navigation->next ) {
	return;
}

$nav_prev    = $navigation->prev;
$nav_next    = $navigation->next;
$nav_counter = $navigation->position . ' из ' . $navigation->total;
?>
<nav class="fs-article-nav" aria-label="Навигация по статьям">
	<div class="fs-article-nav__head">
		<span class="fs-article-nav__counter"><?php echo esc_html( $nav_counter ); ?></span>
		<?php // Стрелку рисует CSS (миксин fs-arrow-reveal) — как у «Все курсы» в сайдбаре. ?>
		<?php if ( '' !== $navigation->textbook_url ) : ?>
			<a href="<?php echo esc_url( $navigation->textbook_url ); ?>" class="fs-article-nav__all">Все статьи темы</a>
		<?php endif; ?>
	</div>

	<div class="fs-article-nav__pair">
		<?php
		$nav_sides = array(
			array( 'key' => 'prev', 'label' => 'Предыдущая', 'article' => $nav_prev ),
			array( 'key' => 'next', 'label' => 'Следующая', 'article' => $nav_next ),
		);

		foreach ( $nav_sides as $side ) :
			$is_next = 'next' === $side['key'];

			if ( ! $side['article'] ) :
				?>
				<div class="fs-article-nav__row fs-article-nav__row--empty" aria-hidden="true"></div>
				<?php
				continue;
			endif;
			?>
			<a href="<?php echo esc_url( $side['article']->url ); ?>"
				class="fs-article-nav__row fs-article-nav__row--<?php echo esc_attr( $side['key'] ); ?>">
				<?php // Нет обложки — заглушка того же размера: карточки сторон равны по высоте. ?>
				<?php if ( '' !== $side['article']->thumbnail ) : ?>
					<img class="fs-article-nav__thumb"
						src="<?php echo esc_url( $side['article']->thumbnail ); ?>"
						alt="" loading="lazy" decoding="async" />
				<?php else : ?>
					<span class="fs-article-nav__thumb fs-article-nav__thumb--empty" aria-hidden="true"></span>
				<?php endif; ?>

				<span class="fs-article-nav__text">
					<span class="fs-article-nav__kicker">
						<?php if ( ! $is_next ) : ?>
							<?php echo Icon::ChevronLeft->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<?php echo esc_html( $side['label'] ); ?>
						<?php if ( $is_next ) : ?>
							<?php echo Icon::ChevronRight->svg( 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</span>
					<span class="fs-article-nav__title"><?php echo esc_html( $side['article']->title ); ?></span>
					<?php if ( '' !== $side['article']->description ) : ?>
						<span class="fs-article-nav__desc"><?php echo esc_html( $side['article']->description ); ?></span>
					<?php endif; ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</nav>