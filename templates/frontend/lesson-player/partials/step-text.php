<?php
/**
 * Текст-шаг плеера (T14.6): карточка card16 — бейдж типа, заголовок, wp-контент.
 *
 * @var array  $step      Шаг из LessonPlayerService::buildView.
 * @var array  $render    Render-данные шага.
 * @var string $edit_url  Ссылка «Редактировать» в конструктор (#15-E), пусто вне preview.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;
?>
<div class="card16">
	<div class="kick">
		<span class="tbadge" data-step-type="<?php echo esc_attr( $step['type'] ); ?>">
			<?php echo esc_html( StepType::fromValueOrDefault( $step['type'] )->label() ); ?>
		</span>
		<?php if ( ! empty( $edit_url ) ) : ?>
			<a class="b b-gh b-sm pv-edit" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Редактировать', 'fs-lms' ); ?></a>
		<?php endif; ?>
	</div>
	<h1><?php echo esc_html( $step['title'] ); ?></h1>

	<div class="gap16">
		<?php
		// Контент лекции выводится сырым — как это делает the_content() в ядре.
		// Он уже прошёл kses при сохранении шага (LessonAuthoringService::sanitizeStep)
		// и конвейер the_content в StepContentRenderer. Второй прогон через kses
		// срезал бы результат работы плагинов контента: <iframe> oEmbed и атрибуты
		// картинок-формул QuickLaTeX.
		?>
		<div class="wpc"><?php echo $render['content'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	</div>
</div>
