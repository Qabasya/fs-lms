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
					<svg width="30" height="30" viewBox="0 0 20 20" fill="none"><path d="M7 4.8v10.4L15.5 10 7 4.8z" fill="currentColor"/></svg>
				</button>
				<div class="vp-bar">
					<div class="vp-line" data-vp-line>
						<span class="fill" data-vp-fill></span>
						<span class="knob" data-vp-knob></span>
					</div>
					<div class="vp-ctrls">
						<button type="button" class="vp-cbtn" data-vp-toggle aria-label="<?php esc_attr_e( 'Плей/пауза', 'fs-lms' ); ?>">
							<span class="vp-ico-play"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 4.8v10.4L15.5 10 7 4.8z" fill="currentColor"/></svg></span>
							<span class="vp-ico-pause"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="5.5" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/><rect x="11.3" y="4.5" width="3.2" height="11" rx="1" fill="currentColor"/></svg></span>
						</button>
						<button type="button" class="vp-cbtn" data-vp-b10 title="<?php esc_attr_e( '−10 секунд', 'fs-lms' ); ?>">
							<svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 3a7 7 0 1 1-6.4 4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M3 3v4.5h4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><text x="7" y="14.5" fill="currentColor" font-size="6.5" font-weight="700">10</text></svg>
						</button>
						<button type="button" class="vp-cbtn" data-vp-f10 title="<?php esc_attr_e( '+10 секунд', 'fs-lms' ); ?>">
							<svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 3a7 7 0 1 0 6.4 4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M17 3v4.5h-4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><text x="6.5" y="14.5" fill="currentColor" font-size="6.5" font-weight="700">10</text></svg>
						</button>
						<span class="vp-time" data-vp-time>0:00 / 0:00</span>
						<span class="grow"></span>
						<button type="button" class="vp-cbtn" data-vp-fs title="<?php esc_attr_e( 'Во весь экран', 'fs-lms' ); ?>">
							<svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M3 8V3h5M12 3h5v5M17 12v5h-5M8 17H3v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
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
		<?php endif; ?>

		<?php if ( ! empty( $render['description'] ) ) : ?>
			<p class="step-muted"><?php echo esc_html( (string) $render['description'] ); ?></p>
		<?php endif; ?>

		<?php foreach ( $video_files as $video_file ) : ?>
			<a class="attach" href="<?php echo esc_url( $video_file['url'] ); ?>" download>
				<span class="ai">
					<svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M5 2.5h6.2L15.5 6.8V17.5H5V2.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M11 3v4.3h4.3" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
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
					<svg width="17" height="17" viewBox="0 0 20 20" fill="none"><path d="M10 3v9m0 0 3.5-3.5M10 12 6.5 8.5M4 16h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</div>
