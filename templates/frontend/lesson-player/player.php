<?php
/**
 * Плеер курса (Эпик 14, D18) — полноэкранный app-shell в оболочке ЛК ученика.
 *
 * Свой <html> (без темы сайта, как profile.php): сайдбар кабинета со ссылками
 * в /profile/, топбар (крамб, заголовок урока, прогресс), рейка дерева курса,
 * лента шагов. Шаги отрендерены сервером в скрытые панели; навигацию, ленту
 * и рейку строит бандл player.min.js (Enqueue грузит по fs_lms_is_player_route).
 *
 * @var array    $view        {group_lesson_id, lesson_id, topic, steps[], shell?, preview?}
 * @var int      $groupId     0 в preview-режиме (курс не привязан к группе, Фаза 5).
 * @var string   $active_step Ключ шага из deep-link ?step= (может быть пустым).
 * @var bool|null $can_edit   Право на кнопку «Редактировать» шага; ставит только
 *                            CoursePreviewController, в реальном плеере не задаётся.
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Ui\Icon;
use Inc\Enums\Wp\PageRoutes;

$profile_url = PageRoutes::UserProfile->url();

// ── Оболочка (T14.2): read-модель shell с безопасными фолбэками ──────────
$shell           = is_array( $view['shell'] ?? null ) ? $view['shell'] : array();
// Preview-плеер курса (Фаза 5, D3/D4): без ученика/занятия — контент активен,
// но без сохранения/проверки/прогресса/гейтов. $can_edit ставит только
// CoursePreviewController (наличие права AuthorLmsCourses), реальный плеер его не задаёт.
$is_preview      = (bool) ( $view['preview'] ?? false );
$can_edit        = $is_preview && ! empty( $can_edit );
$course_title    = (string) ( $shell['course_title'] ?? '' );
$module_label    = (string) ( $shell['module_label'] ?? '' );
$course_progress = is_array( $shell['course_progress'] ?? null ) ? $shell['course_progress'] : null;

// #3b (редизайн): режим блокировки урока — сам плеер рендерится, но контент шага
// заменён размытым скелетом + оверлеем «Урок ещё не доступен» (+ таймер D-4).
// Переменные ставит LessonPlayerController; в preview/обычном плеере — false/null.
$locked           = ! empty( $locked );
$locked_scheduled = $locked_scheduled ?? null;
$locked_seconds   = $locked_seconds ?? null;
$locked_soon      = ! empty( $locked_soon );

// Прогресс урока для топбара: пройденные шаги (completed; failed закрывает
// шаг, но в прогресс не зачитывается — как в ProgressStatus::isComplete()).
$steps_total = count( $view['steps'] );
$steps_done  = count(
	array_filter(
		$view['steps'],
		static fn( array $s ): bool => 'completed' === $s['status']
	)
);
$lesson_pct  = $steps_total > 0 ? (int) round( $steps_done / $steps_total * 100 ) : 0;

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $view['topic'] ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
	<?php wp_head(); ?>
</head>
<body class="fs-player-page">

<?php
$next_lesson = is_array( $shell['next_lesson'] ?? null ) ? $shell['next_lesson'] : null;
$next_url    = null !== $next_lesson
	? add_query_arg(
		array(
			'gid' => $groupId,
			'gl'  => (int) $next_lesson['group_lesson_id'],
		),
		PageRoutes::GroupCockpit->url()
	)
	: '';
?>
<div class="app" id="fsPlayerApp"
	data-group-lesson-id="<?php echo esc_attr( (string) ( $view['group_lesson_id'] ?? $view['lesson_id'] ?? 0 ) ); ?>"
	data-group-id="<?php echo esc_attr( (string) $groupId ); ?>"
	data-active-step="<?php echo esc_attr( $active_step ?? '' ); ?>"
	data-preview="<?php echo $is_preview ? '1' : '0'; ?>"
	data-locked="<?php echo $locked ? '1' : '0'; ?>"
	<?php echo '' !== $next_url ? 'data-next-url="' . esc_url( $next_url ) . '"' : ''; ?>
	<?php echo null !== $next_lesson ? 'data-next-available="' . esc_attr( $next_lesson['available'] ? '1' : '0' ) . '"' : ''; ?>>

	<!-- ══ Основная область (сайдбар кабинета в плеере не дублируется — только
	     кнопка «Вернуться» в топбаре, см. ниже) ══ -->
	<div class="s-main">
		<header class="s-top">
			<a class="s-back" href="<?php echo esc_url( $profile_url ); ?>">
				<?php echo Icon::Back->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Вернуться', 'fs-lms' ); ?>
			</a>
			<div>
				<div class="s-crumb">
					<?php esc_html_e( 'Мои курсы', 'fs-lms' ); ?><?php if ( '' !== $course_title ) : ?> · <b><?php echo esc_html( $course_title ); ?></b><?php endif; ?><?php if ( '' !== $module_label ) : ?> · <?php echo esc_html( $module_label ); ?><?php endif; ?>
				</div>
				<div class="s-title"><?php echo esc_html( $view['topic'] ); ?></div>
			</div>
			<div class="s-right">
				<?php if ( $is_preview ) : ?>
					<span class="pv-banner"><?php esc_html_e( 'Предпросмотр курса', 'fs-lms' ); ?></span>
				<?php endif; ?>
				<div class="s-prog">
					<span class="sp-txt" id="fsProgTxt">
						<?php
						printf(
							/* translators: 1: completed steps, 2: total steps */
							esc_html__( 'Урок · %1$d из %2$d', 'fs-lms' ),
							$steps_done,
							$steps_total
						);
						?>
					</span>
					<span class="sp-bar"><span id="fsProgBar" data-width="<?php echo esc_attr( (string) $lesson_pct ); ?>"></span></span>
				</div>
				<button type="button" class="s-ibtn" data-toast="<?php esc_attr_e( 'Уведомлений нет', 'fs-lms' ); ?>">
					<?php echo Icon::Bell->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
		</header>

		<!-- плеер: рейка дерева + контент -->
		<div class="player">
			<div class="railwrap">
				<div class="rail" id="fsRail">
					<?php include __DIR__ . '/partials/rail.php'; ?>
				</div>
			</div>
			<div class="content<?php echo $locked ? ' is-locked' : ''; ?>">
				<div class="cscroll" id="fsScroll">
					<div class="col">
						<?php if ( $locked ) : ?>
						<div class="lock-skeleton" aria-hidden="true">
							<div class="lsk lsk-title"></div>
							<div class="lsk"></div>
							<div class="lsk lsk-short"></div>
							<div class="lsk-box"></div>
							<div class="lsk"></div>
							<div class="lsk lsk-short"></div>
						</div>
						<?php else : ?>
						<div class="strip" id="fsStrip"></div>

						<div class="cnav" id="fsNav">
							<button type="button" class="b b-gh" id="fsNavPrev">
								<?php echo Icon::ChevronLeft->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php esc_html_e( 'Назад', 'fs-lms' ); ?>
							</button>
							<span class="pos" id="fsNavPos"></span>
							<button type="button" class="b b-pri" id="fsNavNext">
								<?php esc_html_e( 'Далее', 'fs-lms' ); ?>
								<?php echo Icon::ChevronRight->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</button>
						</div>

						<div class="steproot" id="fsStepRoot">
							<?php foreach ( $view['steps'] as $i => $step ) : ?>
								<?php
								// Ручное задание проходится как инлайн-шаг: «Далее» отмечает его пройденным.
								$is_manual_task = 'task' === $step['type'] && empty( $step['render']['auto_grade'] );

								// Кнопка «Редактировать» (Фаза 5, #15-E) — только в preview для роли с
								// правом редактирования курса. Ссылочные шаги (task/work/assessment) —
								// по ref (post id, как и раньше); text/video — по стабильному step.key.
								$edit_url = '';
								if ( $can_edit ) {
									$ref      = (int) ( $step['render']['ref'] ?? 0 );
									$edit_url = admin_url( sprintf(
										'admin.php?page=fs_lms_course_builder&course=%d&lesson=%d%s',
										(int) ( $view['course_id'] ?? 0 ),
										(int) $view['lesson_id'],
										$ref > 0 ? '&step_ref=' . $ref : '&step_key=' . rawurlencode( $step['key'] )
									) );
								}
								?>
								<section
									class="pstep"
									data-step="<?php echo esc_attr( $step['key'] ); ?>"
									data-index="<?php echo esc_attr( (string) $i ); ?>"
									data-step-type="<?php echo esc_attr( $step['type'] ); ?>"
									data-title="<?php echo esc_attr( $step['title'] ); ?>"
									data-gate="<?php echo esc_attr( $step['gate'] ); ?>"
									data-status="<?php echo esc_attr( $step['status'] ); ?>"
									<?php echo $is_manual_task ? 'data-manual="1"' : ''; ?>
									hidden
								>
									<?php
									$render = $step['render'] ?? array();
									switch ( $step['type'] ) {
										case 'text':
											include __DIR__ . '/partials/step-text.php';
											break;
										case 'video':
											include __DIR__ . '/partials/step-video.php';
											break;
										case 'task':
											include __DIR__ . '/partials/step-task.php';
											break;
										case 'work':
											include __DIR__ . '/partials/step-work.php';
											break;
										case 'assessment':
											include __DIR__ . '/partials/step-assessment.php';
											break;
									}
									?>
								</section>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php if ( $locked ) : ?>
			<div class="lock-overlay">
				<div class="lock-modal">
					<div class="lock-ico">
						<?php echo Icon::Lock->svg( 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php if ( $locked_soon ) : ?>
						<div class="lock-title"><?php esc_html_e( 'Занятие скоро начнётся', 'fs-lms' ); ?></div>
						<p class="lock-sub">
							<?php
							printf(
								/* translators: %s: время начала занятия (ЧЧ:ММ). */
								esc_html__( 'Начало в %s — страница обновится автоматически.', 'fs-lms' ),
								esc_html( mysql2date( 'H:i', $locked_scheduled ) )
							);
							?>
						</p>
						<div class="lock-countdown" data-lesson-countdown data-seconds="<?php echo esc_attr( (string) $locked_seconds ); ?>">
							<span data-countdown-value><?php echo esc_html( sprintf( '%02d:%02d', intdiv( $locked_seconds, 60 ), $locked_seconds % 60 ) ); ?></span>
						</div>
					<?php elseif ( null !== $locked_seconds && $locked_seconds > 0 ) : ?>
						<div class="lock-title"><?php esc_html_e( 'Урок ещё не доступен', 'fs-lms' ); ?></div>
						<p class="lock-sub">
							<?php
							printf(
								/* translators: 1: дата открытия, 2: время начала (ЧЧ:ММ). */
								esc_html__( 'Откроется %1$s в %2$s.', 'fs-lms' ),
								esc_html( mysql2date( 'j F', $locked_scheduled ) ),
								esc_html( mysql2date( 'H:i', $locked_scheduled ) )
							);
							?>
						</p>
					<?php else : ?>
						<div class="lock-title"><?php esc_html_e( 'Урок ещё не доступен', 'fs-lms' ); ?></div>
						<p class="lock-sub"><?php esc_html_e( 'Он откроется по дате или после выполнения предыдущих шагов.', 'fs-lms' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<div class="toast" id="fsToast">
	<?php echo Icon::Check->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<span><?php esc_html_e( 'Готово', 'fs-lms' ); ?></span>
</div>

<?php wp_footer(); ?>
</body>
</html>
