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
<div class="wrap fs-player" data-group-lesson-id="<?php echo esc_attr( (string) $view['group_lesson_id'] ); ?>" data-active-step="<?php echo esc_attr( $active_step ?? '' ); ?>">

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
						<button type="button" class="fs-player__copylink" data-step="<?php echo esc_attr( $step['key'] ); ?>" aria-label="<?php esc_attr_e( 'Скопировать ссылку на шаг', 'fs-lms' ); ?>" title="<?php esc_attr_e( 'Скопировать ссылку на шаг', 'fs-lms' ); ?>">🔗</button>
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

							case 'task':
								if ( ! empty( $render['auto_grade'] ) ) :
									$tmpl    = (string) ( $render['template'] ?? '' );
									$is_done = in_array( $step['status'], array( 'completed', 'failed' ), true );
									?>

									<?php
									// Condition(s)
									if ( 'triple_task' === $tmpl && is_array( $render['condition_html'] ) ) :
										foreach ( $render['condition_html'] as $num => $cond ) :
											echo '<div class="fs-task-subpart"><h3 class="fs-task-subpart__label">'
												. esc_html__( 'Задание №', 'fs-lms' ) . esc_html( (string) $num )
												. '</h3><div class="fs-task-subpart__body">'
												. wp_kses_post( (string) $cond )
												. '</div></div>';
										endforeach;
									elseif ( 'fill_task' !== $tmpl && ! empty( $render['condition_html'] ) ) :
										echo '<div class="fs-task-condition">' . wp_kses_post( (string) $render['condition_html'] ) . '</div>';
									endif;
									?>

									<div class="fs-task-widget"
										data-template="<?php echo esc_attr( $tmpl ); ?>"
										data-widget='<?php echo esc_attr( (string) wp_json_encode( $render['widget_data'] ?? array() ) ); ?>'
										<?php echo $is_done ? 'data-done="1"' : ''; ?>></div>

									<?php
									$max_att  = (int) ( $render['settings']['max_attempts'] ?? 0 );
									$used_att = (int) ( $render['attempts_used'] ?? 0 );
									if ( $max_att > 0 ) :
									?>
									<div class="fs-attempt-indicator"
										data-used="<?php echo esc_attr( (string) $used_att ); ?>"
										data-max="<?php echo esc_attr( (string) $max_att ); ?>">
										<?php printf(
											/* translators: 1: attempts used, 2: max attempts */
											esc_html__( 'Попыток использовано: %1$d из %2$d', 'fs-lms' ),
											$used_att,
											$max_att
										); ?>
									</div>
									<?php endif; ?>

									<div class="fs-task-footer">
										<button type="button"
											class="button button-primary fs-task-submit"
											data-step="<?php echo esc_attr( $step['key'] ); ?>"
											<?php echo $is_done ? 'disabled' : ''; ?>>
											<?php esc_html_e( 'Проверить', 'fs-lms' ); ?>
										</button>
										<div class="fs-task-result" aria-live="polite"></div>
									</div>

									<?php if ( ! empty( $render['hint_html'] ) ) : ?>
									<details class="fs-hint"<?php echo ! empty( $render['reveal_hint'] ) ? ' open' : ''; ?>>
										<summary class="fs-hint__toggle"><?php esc_html_e( 'Подсказка', 'fs-lms' ); ?></summary>
										<div class="fs-hint__body"><?php echo wp_kses_post( (string) $render['hint_html'] ); ?></div>
									</details>
									<?php endif; ?>

									<?php
								else :
									// Manual task (Code, File, TextSolution) — no auto-checking
									echo '<p class="fs-player__muted">'
										. esc_html__( 'Это задание проверяется вручную. Выполните задание и сдайте в кабинете.', 'fs-lms' )
										. '</p>';
									echo '<a class="button fs-player__tocockpit" href="' . esc_url( $cockpit_url ) . '">' . esc_html__( 'Перейти в кабинет', 'fs-lms' ) . '</a>';
								endif;
								break;

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

					<?php
					// "Отметить пройденным" for non-interactive steps + manual tasks.
					$is_auto_grade_task = 'task' === $step['type'] && ! empty( $step['render']['auto_grade'] );
					$show_complete = in_array( $step['type'], array( 'text', 'video' ), true )
						|| ( 'task' === $step['type'] && ! $is_auto_grade_task );
					if ( $show_complete ) :
					?>
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
