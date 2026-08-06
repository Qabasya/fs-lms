<?php
/**
 * Навигация по статьям одного номера задания.
 *
 * Разметка и классы — те же, что у блока страницы задания (`.fs-task-nav`):
 * компонент один, стили общие (`src/scss/frontend/components/_nav.scss`).
 * Отличий от страницы задания два: кольца нет — на краях серии сторона
 * неактивна, и в центре вместо «Все задания» стоит счётчик позиции со ссылкой
 * на учебник.
 *
 * Партиал подключается дважды — над текстом статьи и под ним, — поэтому
 * подписи сторон и разметка живут здесь, а не в шаблоне страницы.
 *
 * @var \Inc\DTO\Article\ArticleNavigationDTO $navigation           Данные навигации.
 * @var string                                $article_nav_modifier Доп. класс блока (отступы нижней копии).
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

$nav_prev             = $navigation->prev;
$nav_next             = $navigation->next;
$nav_counter          = $navigation->position . ' из ' . $navigation->total;
$article_nav_modifier = (string) ( $article_nav_modifier ?? '' );
?>
<nav class="fs-task-nav<?php echo esc_attr( $article_nav_modifier ); ?>" aria-label="Навигация по статьям">
	<?php if ( $nav_prev ) : ?>
	<a href="<?php echo esc_url( $nav_prev->url ); ?>" class="fs-task-nav__side fs-task-nav__side--prev" aria-label="Предыдущая статья">
	<?php else : ?>
	<div class="fs-task-nav__side fs-task-nav__side--prev">
	<?php endif; ?>
		<span class="fs-task-nav__arrow<?php echo ! $nav_prev ? ' fs-task-nav__arrow--disabled' : ''; ?>" aria-hidden="true"><?php echo Icon::ChevronLeft->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<div class="fs-task-nav__info">
			<span class="fs-task-nav__label">Предыдущая</span>
			<span class="fs-task-nav__title">
				<?php echo $nav_prev ? esc_html( $nav_prev->title ) : '&mdash;'; ?>
			</span>
		</div>
	<?php if ( $nav_prev ) : ?>
	</a>
	<?php else : ?>
	</div>
	<?php endif; ?>

	<?php // Центр ведёт в учебник — тот же адрес, что у крошки «Учебник». ?>
	<?php if ( '' !== $navigation->textbook_url ) : ?>
		<a href="<?php echo esc_url( $navigation->textbook_url ); ?>" class="fs-task-nav__center">
			<span class="fs-task-nav__dots" aria-hidden="true"><i></i><i></i><i></i></span>
			<span class="fs-task-nav__all"><?php echo esc_html( $nav_counter ); ?></span>
		</a>
	<?php else : ?>
		<div class="fs-task-nav__center">
			<span class="fs-task-nav__dots" aria-hidden="true"><i></i><i></i><i></i></span>
			<span class="fs-task-nav__all fs-task-nav__all--plain"><?php echo esc_html( $nav_counter ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( $nav_next ) : ?>
	<a href="<?php echo esc_url( $nav_next->url ); ?>" class="fs-task-nav__side fs-task-nav__side--next" aria-label="Следующая статья">
	<?php else : ?>
	<div class="fs-task-nav__side fs-task-nav__side--next">
	<?php endif; ?>
		<span class="fs-task-nav__arrow<?php echo ! $nav_next ? ' fs-task-nav__arrow--disabled' : ''; ?>" aria-hidden="true"><?php echo Icon::ChevronRight->svg( 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<div class="fs-task-nav__info fs-task-nav__info--right">
			<span class="fs-task-nav__label">Следующая</span>
			<span class="fs-task-nav__title">
				<?php echo $nav_next ? esc_html( $nav_next->title ) : '&mdash;'; ?>
			</span>
		</div>
	<?php if ( $nav_next ) : ?>
	</a>
	<?php else : ?>
	</div>
	<?php endif; ?>
</nav>