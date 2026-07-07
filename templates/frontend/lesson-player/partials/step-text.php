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
	<h2><?php echo esc_html( $step['title'] ); ?></h2>

	<div class="gap16">
		<div class="wpc"><?php echo wp_kses_post( (string) ( $render['content'] ?? '' ) ); ?></div>
	</div>
</div>
