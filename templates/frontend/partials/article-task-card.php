<?php
/**
 * Карточка задания внутри текста статьи.
 *
 * Подставляется пост-обработкой контента ({@see \Inc\Services\Subject\ArticleContentService})
 * на место абзаца-ссылки на задание. Свёрнутая карточка показывает первые
 * строки условия, раскрытая — условие целиком и ссылку на страницу задания.
 * Ответ не показываем: за ним идут на саму страницу задания.
 *
 * @var \Inc\DTO\Article\ArticleTaskCardDTO $task_card Данные карточки.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

$body_id = 'fs-article-task-' . $task_card->id;
?>
<article class="fs-article-task js-article-task">
	<button type="button" class="fs-article-task__top js-article-task-toggle"
		aria-expanded="false" aria-controls="<?php echo esc_attr( $body_id ); ?>">
		<span class="fs-article-task__main">
			<span class="fs-article-task__title"><?php echo esc_html( $task_card->title ); ?></span>
			<?php if ( '' !== $task_card->peek ) : ?>
				<span class="fs-article-task__peek"><?php echo esc_html( $task_card->peek ); ?></span>
			<?php endif; ?>
		</span>
		<span class="fs-article-task__chev" aria-hidden="true">
			<?php echo Icon::ChevronDown->svg( 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</span>
	</button>

	<?php // Тело всегда в разметке: раскрытие — CSS-переход по grid-template-rows, а не показ скрытого блока. ?>
	<div class="fs-article-task__body" id="<?php echo esc_attr( $body_id ); ?>">
		<div class="fs-article-task__body-inner">
			<div class="fs-article-task__stmt"><?php echo \Inc\Shared\SafeHtml::post( $task_card->condition ); ?></div>

			<div class="fs-article-task__foot">
				<a class="fs-article-task__open" href="<?php echo esc_url( $task_card->url ); ?>">
					<span>Открыть задание</span>
					<?php echo Icon::ArrowRight->svg( 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			</div>
		</div>
	</div>
</article>