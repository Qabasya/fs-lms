<?php
/**
 * Шаг-трансляция плеера (Этап 1): запись занятия есть → тот же нативный/embed
 * хром, что у видео-шага (video-chrome.php); записи ещё нет → плашка-заглушка
 * со ссылкой на трансляцию (`stream_url`, интеграция с плагином трансляций —
 * отдельный, более поздний этап). Описание/главы/вложения не поддерживаются.
 *
 * @var array  $step      Шаг из LessonPlayerService::buildView.
 * @var array  $render    Render-данные шага (StepContentRenderer::renderBroadcastData).
 * @var string $edit_url  Ссылка «Редактировать» в конструктор (#15-E), пусто вне preview.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;
use Inc\Enums\Ui\Icon;

$video_url   = (string) ( $render['url'] ?? '' );
$video_mode  = (string) ( $render['mode'] ?? ( '' !== $video_url ? 'embed' : 'none' ) );
$video_chaps = array(); // главы трансляции не поддерживаются.
$stream_url  = (string) ( $render['stream_url'] ?? '' );
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
		<?php if ( 'native' === $video_mode ) : ?>
			<?php include __DIR__ . '/video-chrome.php'; ?>

		<?php elseif ( 'embed' === $video_mode ) : ?>
			<?php
			$embed = wp_oembed_get( $video_url );
			echo $embed
				? '<div class="video-embed">' . $embed . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				: '<a class="b" href="' . esc_url( $video_url ) . '" target="_blank" rel="noopener">' . esc_html( $video_url ) . '</a>';
			?>

		<?php else : ?>
			<div class="broadcast-placeholder">
				<span class="bp-ico"><?php echo Icon::Play->svg( 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php if ( '' !== $stream_url ) : ?>
					<p class="step-muted"><?php esc_html_e( 'Идёт или скоро будет трансляция занятия.', 'fs-lms' ); ?></p>
					<a class="b b-pri" href="<?php echo esc_url( $stream_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Перейти к трансляции', 'fs-lms' ); ?></a>
				<?php else : ?>
					<p class="step-muted"><?php esc_html_e( 'Запись занятия ещё не доступна.', 'fs-lms' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</div>
