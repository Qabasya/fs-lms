<?php
/**
 * Видео-шаг плеера: oembed-карточка (до T14.12 — без нативного хрома).
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

$video_url     = (string) ( $render['url'] ?? '' );
$video_is_slot = (bool) ( $render['recording_slot'] ?? false );
?>
<div class="card16">
	<div class="kick">
		<span class="tbadge" data-step-type="<?php echo esc_attr( $step['type'] ); ?>">
			<?php echo esc_html( StepType::fromValueOrDefault( $step['type'] )->label() ); ?>
		</span>
	</div>
	<h2><?php echo esc_html( $step['title'] ); ?></h2>

	<div class="gap16">
		<?php
		if ( '' !== $video_url ) {
			$embed = wp_oembed_get( $video_url );
			echo $embed
				? '<div class="video-embed">' . $embed . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				: '<a class="b" href="' . esc_url( $video_url ) . '" target="_blank" rel="noopener">' . esc_html( $video_url ) . '</a>';
			if ( ! empty( $render['description'] ) ) {
				echo '<p class="step-muted">' . esc_html( (string) $render['description'] ) . '</p>';
			}
		} elseif ( $video_is_slot ) {
			echo '<p class="step-muted">' . esc_html__( 'Запись занятия ещё не доступна.', 'fs-lms' ) . '</p>';
		}
		?>
	</div>
</div>
