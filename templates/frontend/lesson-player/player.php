<?php
/**
 * Пошаговый плеер урока (★, T1.5.12). DOM-driven: данные — в data-атрибутах, навигация
 * и запись прогресса — в `src/js/frontend/services/lesson-player.js`.
 *
 * @var array  $view    {group_lesson_id, lesson_id, topic, steps[]}
 * @var int    $groupId
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Course\StepType;

$cockpit_url = add_query_arg( array( 'gid' => $groupId ), \Inc\Enums\Wp\PageRoutes::GroupCockpit->url() );
?>
<div class="wrap fs-player" data-group-lesson-id="<?php echo esc_attr( (string) $view['group_lesson_id'] ); ?>">

	<a class="fs-player__back" href="<?php echo esc_url( $cockpit_url ); ?>">← <?php esc_html_e( 'К программе группы', 'fs-lms' ); ?></a>
	<h1 class="fs-player__title"><?php echo esc_html( $view['topic'] ); ?></h1>

	<div class="fs-player__body">
		<nav class="fs-player__stepper" aria-label="<?php esc_attr_e( 'Шаги урока', 'fs-lms' ); ?>">
			<ol class="fs-player__steplist">
				<?php foreach ( $view['steps'] as $i => $step ) : ?>
					<li
						class="fs-player__stepnav"
						data-step="<?php echo esc_attr( $step['key'] ); ?>"
						data-index="<?php echo esc_attr( (string) $i ); ?>"
						data-type="<?php echo esc_attr( $step['type'] ); ?>"
						data-gate="<?php echo esc_attr( $step['gate'] ); ?>"
						data-status="<?php echo esc_attr( $step['status'] ); ?>"
					>
						<span class="fs-player__stepnum"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
						<span class="fs-player__steptitle"><?php echo esc_html( $step['title'] ); ?></span>
						<span class="fs-player__stepmark" aria-hidden="true"></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</nav>

		<main class="fs-player__stage">
			<?php foreach ( $view['steps'] as $i => $step ) : ?>
				<section
					class="fs-player__panel"
					data-step="<?php echo esc_attr( $step['key'] ); ?>"
					data-index="<?php echo esc_attr( (string) $i ); ?>"
					data-type="<?php echo esc_attr( $step['type'] ); ?>"
					data-gate="<?php echo esc_attr( $step['gate'] ); ?>"
					data-status="<?php echo esc_attr( $step['status'] ); ?>"
					<?php echo 0 === $i ? '' : 'hidden'; ?>
				>
					<header class="fs-player__panelhead">
						<span class="fs-player__paneltype" data-type="<?php echo esc_attr( $step['type'] ); ?>">
							<?php echo esc_html( StepType::fromValueOrDefault( $step['type'] )->label() ); ?>
						</span>
						<h2 class="fs-player__panelname"><?php echo esc_html( $step['title'] ); ?></h2>
					</header>

					<div class="fs-player__panelbody">
						<?php
						$render = $step['render'] ?? array();
						switch ( $step['type'] ) {
							case 'text':
								echo wp_kses_post( (string) ( $render['content'] ?? '' ) );
								break;

							case 'video':
								$url     = (string) ( $render['url'] ?? '' );
								$is_slot = (bool) ( $render['recording_slot'] ?? false );
								if ( '' !== $url ) {
									$embed = wp_oembed_get( $url );
									echo $embed
										? '<div class="fs-player__video">' . $embed . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										: '<a class="fs-player__videolink" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>';
									if ( ! empty( $render['description'] ) ) {
										echo '<p class="fs-player__videodesc">' . esc_html( (string) $render['description'] ) . '</p>';
									}
								} elseif ( $is_slot ) {
									echo '<p class="fs-player__muted">' . esc_html__( 'Запись занятия ещё не доступна.', 'fs-lms' ) . '</p>';
								}
								break;

							case 'material':
								$ref = (int) ( $render['ref'] ?? 0 );
								$link = $ref ? get_permalink( $ref ) : '';
								echo $link
									? '<a class="button fs-player__material" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html__( 'Открыть материал', 'fs-lms' ) . '</a>'
									: '<p class="fs-player__muted">' . esc_html__( 'Материал не выбран.', 'fs-lms' ) . '</p>';
								break;

							case 'task':
							case 'work':
							case 'assessment':
								echo '<p class="fs-player__muted">'
									. esc_html__( 'Этот шаг выполняется в кабинете группы (сдача / прохождение). Зачёт отметится автоматически.', 'fs-lms' )
									. '</p>';
								echo '<a class="button fs-player__tocockpit" href="' . esc_url( $cockpit_url ) . '">' . esc_html__( 'Перейти в кабинет', 'fs-lms' ) . '</a>';
								break;
						}
						?>
					</div>

					<?php if ( in_array( $step['type'], array( 'text', 'video', 'material', 'task' ), true ) ) : ?>
						<button type="button" class="button button-primary fs-player__complete" data-step="<?php echo esc_attr( $step['key'] ); ?>">
							<?php esc_html_e( 'Отметить пройденным', 'fs-lms' ); ?>
						</button>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<div class="fs-player__nav">
				<button type="button" class="button fs-player__prev" disabled>← <?php esc_html_e( 'Назад', 'fs-lms' ); ?></button>
				<span class="fs-player__count" data-count></span>
				<button type="button" class="button fs-player__next"><?php esc_html_e( 'Далее', 'fs-lms' ); ?> →</button>
			</div>
		</main>
	</div>
</div>
