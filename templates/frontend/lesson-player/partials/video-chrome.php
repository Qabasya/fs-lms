<?php
/**
 * Хром нативного видео-плеера (D21, T14.12; вынесено на Этапе 1 из step-video.php):
 * play, ±10 сек, ползунок, время, fullscreen — общий для video- и broadcast-шагов
 * (запись занятия рендерится тем же хромом). Поведение — src/js/player/step-video.js.
 *
 * @var string $video_url   URL прямого файла (mp4/webm/…).
 * @var array  $video_chaps Главы [{t, title}] — video-шаг; у broadcast всегда пусто.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;

$video_chaps = $video_chaps ?? array();
$video_fmt   = static fn( int $sec ): string => floor( $sec / 60 ) . ':' . str_pad( (string) ( $sec % 60 ), 2, '0', STR_PAD_LEFT );
?>
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
			<div class="vp-audio">
				<button type="button" class="vp-cbtn" data-vp-mute aria-pressed="false" aria-label="<?php esc_attr_e( 'Выключить звук', 'fs-lms' ); ?>" data-label-mute="<?php esc_attr_e( 'Выключить звук', 'fs-lms' ); ?>" data-label-unmute="<?php esc_attr_e( 'Включить звук', 'fs-lms' ); ?>">
					<span class="vp-ico-volume"><?php echo Icon::Volume->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="vp-ico-muted"><?php echo Icon::VolumeOff->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</button>
				<input type="range" class="vp-volume" data-vp-volume min="0" max="1" step="0.05" value="1" aria-label="<?php esc_attr_e( 'Громкость', 'fs-lms' ); ?>">
			</div>
			<span class="grow"></span>
			<select class="vp-speed" data-vp-speed aria-label="<?php esc_attr_e( 'Скорость воспроизведения', 'fs-lms' ); ?>">
				<?php foreach ( array( '0.5', '0.75', '1', '1.25', '1.5', '1.75', '2' ) as $video_rate ) : ?>
					<option value="<?php echo esc_attr( $video_rate ); ?>" <?php selected( $video_rate, '1' ); ?>><?php echo esc_html( str_replace( '.', ',', $video_rate ) . '×' ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="vp-cbtn" data-vp-pip hidden title="<?php esc_attr_e( 'Картинка в картинке', 'fs-lms' ); ?>" aria-label="<?php esc_attr_e( 'Картинка в картинке', 'fs-lms' ); ?>" aria-pressed="false">
				<?php echo Icon::PictureInPicture->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
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
