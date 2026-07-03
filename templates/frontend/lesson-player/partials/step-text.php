<?php
/**
 * Текст-шаг плеера (T14.6): карточка card16 — бейдж типа, заголовок, wp-контент.
 *
 * @var array $step   Шаг из LessonPlayerService::buildView.
 * @var array $render Render-данные шага.
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
	</div>
	<h2><?php echo esc_html( $step['title'] ); ?></h2>

	<div class="gap16">
		<div class="wpc"><?php echo wp_kses_post( (string) ( $render['content'] ?? '' ) ); ?></div>
	</div>
</div>
