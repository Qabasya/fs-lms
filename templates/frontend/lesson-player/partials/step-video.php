<?php
/**
 * Видео-шаг плеера (D21, T14.12): прямой файл → нативный <video> с кастомным
 * хромом (play, ±10 сек, ползунок, время, fullscreen) и главами-перемотками;
 * VK/Rutube/YouTube → oembed-карточка (главы скрыты). Вложения-конспекты —
 * карточки со скачиванием. Поведение хрома — src/js/player/step-video.js.
 *
 * @var array  $step      Шаг из LessonPlayerService::buildView.
 * @var array  $render    Render-данные шага (LessonPlayerService::renderVideoData).
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

$video_url     = (string) ( $render['url'] ?? '' );
$video_mode    = (string) ( $render['mode'] ?? ( '' !== $video_url ? 'embed' : 'none' ) );
$video_is_slot = (bool) ( $render['recording_slot'] ?? false );
$video_chaps   = is_array( $render['chapters'] ?? null ) ? $render['chapters'] : array();
$video_files   = is_array( $render['attachments'] ?? null ) ? $render['attachments'] : array();

$video_fmt = static fn( int $sec ): string => floor( $sec / 60 ) . ':' . str_pad( (string) ( $sec % 60 ), 2, '0', STR_PAD_LEFT );
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
			<div class="vp" data-video-root>
				<video class="vp-el" data-vp-el src="<?php echo esc_url( $video_url ); ?>" preload="metadata" playsinline></video>
				<button type="button" class="vp-play" data-vp-big aria-label="<?php esc_attr_e( 'Смотреть', 'fs-lms' ); ?>">
					<?php echo Icon::Play->svg( 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<div class="vp-bar">
					<div class="vp-line" data-vp-line>
						<span class="fill" data-vp-fill></span>
						<span class="knob" data-vp-knob></span>
					</div>
					<div class="vp-ctrls">
						<button type="button" class="vp-cbtn" data-vp-toggle aria-label="<?php esc_attr_e( 'Плей/пауза', 'fs-lms' ); ?>">
							<span class="vp-ico-play"><?php echo Icon::Play->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="vp-ico-pause"><?php echo Icon::Pause->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</button>
						<button type="button" class="vp-cbtn" data-vp-b10 title="<?php esc_attr_e( '−10 секунд', 'fs-lms' ); ?>">
							<?php echo Icon::SeekBack10->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
						<button type="button" class="vp-cbtn" data-vp-f10 title="<?php esc_attr_e( '+10 секунд', 'fs-lms' ); ?>">
							<?php echo Icon::SeekForward10->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
						<span class="vp-time" data-vp-time>0:00 / 0:00</span>
						<span class="grow"></span>
						<button type="button" class="vp-cbtn" data-vp-fs title="<?php esc_attr_e( 'Во весь экран', 'fs-lms' ); ?>">
							<?php echo Icon::Fullscreen->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
					</div>
				</div>
			</div>

			<?php if ( array() !== $video_chaps ) : ?>
				<div class="chaps">
					<?php foreach ( $video_chaps as $video_chap ) : ?>
						<button type="button" class="chap" data-chap-t="<?php echo esc_attr( (string) $video_chap['t'] ); ?>">
							<b><?php echo esc_html( $video_fmt( (int) $video_chap['t'] ) ); ?></b>
							<?php echo esc_html( $video_chap['title'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		<?php elseif ( 'embed' === $video_mode ) : ?>
			<?php
			$embed = wp_oembed_get( $video_url );
			echo $embed
				? '<div class="video-embed">' . $embed . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				: '<a class="b" href="' . esc_url( $video_url ) . '" target="_blank" rel="noopener">' . esc_html( $video_url ) . '</a>';
			?>

		<?php elseif ( $video_is_slot ) : ?>
			<p class="step-muted"><?php esc_html_e( 'Запись занятия ещё не доступна.', 'fs-lms' ); ?></p>
		<?php elseif ( 'none' === $video_mode ) : ?>
			<?php // B3: видео ещё не загружено (пустой url) — 16:9-плейсхолдер вместо пустого плеера. ?>
			<div class="vp vp-empty"><span><?php esc_html_e( 'Видео скоро появится', 'fs-lms' ); ?></span></div>
		<?php endif; ?>

		<?php if ( ! empty( $render['description'] ) ) : ?>
			<p class="step-muted"><?php echo esc_html( (string) $render['description'] ); ?></p>
		<?php endif; ?>

		<?php foreach ( $video_files as $video_file ) : ?>
			<a class="attach" href="<?php echo esc_url( $video_file['url'] ); ?>" download>
				<span class="ai">
					<?php echo Icon::File->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<span class="at">
					<b><?php echo esc_html( $video_file['title'] ); ?></b>
					<span>
						<?php
						echo esc_html( trim(
							$video_file['ext'] . ( '' !== $video_file['size'] ? ' · ' . $video_file['size'] : '' ),
							' ·'
						) );
						?>
					</span>
				</span>
				<span class="adl">
					<?php echo Icon::Download->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</div>
