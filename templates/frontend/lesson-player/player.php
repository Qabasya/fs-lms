<?php
/**
 * Плеер курса (Эпик 14, D18) — полноэкранный app-shell в оболочке ЛК ученика.
 *
 * Свой <html> (без темы сайта, как profile.php): сайдбар кабинета со ссылками
 * в /profile/, топбар (крамб, заголовок урока, прогресс), рейка дерева курса,
 * лента шагов. Шаги отрендерены сервером в скрытые панели; навигацию, ленту
 * и рейку строит бандл player.min.js (Enqueue грузит по fs_lms_is_player_route).
 *
 * @var array  $view        {group_lesson_id, lesson_id, topic, steps[], shell?}
 * @var int    $groupId
 * @var string $active_step Ключ шага из deep-link ?step= (может быть пустым).
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Wp\PageRoutes;

$profile_url = PageRoutes::UserProfile->url();

// ── Оболочка (T14.2): read-модель shell с безопасными фолбэками ──────────
$shell           = is_array( $view['shell'] ?? null ) ? $view['shell'] : array();
$course_title    = (string) ( $shell['course_title'] ?? '' );
$module_label    = (string) ( $shell['module_label'] ?? '' );
$course_progress = is_array( $shell['course_progress'] ?? null ) ? $shell['course_progress'] : null;
$current_user    = wp_get_current_user();
$student_name    = (string) ( $shell['student_name'] ?? '' );
if ( '' === $student_name ) {
	$student_name = $current_user->display_name ?: $current_user->user_login;
}
$student_role = (string) ( $shell['student_role'] ?? '' );
if ( '' === $student_role ) {
	$student_role = __( 'Ученик', 'fs-lms' );
}

$name_parts       = array_values( array_filter( explode( ' ', $student_name ) ) );
$student_initials = mb_strtoupper( mb_substr( $name_parts[0] ?? '', 0, 1 ) . mb_substr( $name_parts[1] ?? '', 0, 1 ) );

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

// Пункты сайдбара — экраны ЛК ученика (LearnerProfileView).
$nav_items = array(
	array( 'key' => 'learner-home',       'label' => __( 'Главная', 'fs-lms' ) ),
	array( 'key' => 'learner-lessons',    'label' => __( 'Мои курсы', 'fs-lms' ) ),
	array( 'key' => 'learner-grades',     'label' => __( 'Мои оценки', 'fs-lms' ) ),
	array( 'key' => 'learner-attendance', 'label' => __( 'Посещаемость', 'fs-lms' ) ),
);

$nav_icons = array(
	'learner-home'       => '<svg width="19" height="19" viewBox="0 0 20 20" fill="none"><path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	'learner-lessons'    => '<svg width="19" height="19" viewBox="0 0 20 20" fill="none"><path d="M4 4h7v12H4zM11 4h5v12h-5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
	'learner-grades'     => '<svg width="19" height="19" viewBox="0 0 20 20" fill="none"><path d="M10 3 12 7l4.5.6-3.3 3.2.8 4.5L10 13.2 6 15.5l.8-4.5L3.5 7.7 8 7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
	'learner-attendance' => '<svg width="19" height="19" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M3 8h14M7 2.5v3M13 2.5v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
);
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
	data-group-lesson-id="<?php echo esc_attr( (string) $view['group_lesson_id'] ); ?>"
	data-group-id="<?php echo esc_attr( (string) $groupId ); ?>"
	data-active-step="<?php echo esc_attr( $active_step ?? '' ); ?>"
	<?php echo '' !== $next_url ? 'data-next-url="' . esc_url( $next_url ) . '"' : ''; ?>
	<?php echo null !== $next_lesson ? 'data-next-available="' . esc_attr( $next_lesson['available'] ? '1' : '0' ) . '"' : ''; ?>>

	<!-- ══ Сайдбар кабинета ученика (сворачивается в кнопку) ══ -->
	<aside class="s-side">
		<div class="s-inner">
			<div class="s-brand">
				<div class="s-mark">
					<svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M3 5.5 10 2l7 3.5L10 9 3 5.5z" fill="#fff"/><path d="M6 8v3.5c0 1.2 1.8 2.2 4 2.2s4-1 4-2.2V8" stroke="#fff" stroke-width="1.4" fill="none"/></svg>
				</div>
				<div>
					<div class="s-name"><?php esc_html_e( 'Шаг в будущее', 'fs-lms' ); ?></div>
					<div class="s-bsub"><?php esc_html_e( 'Личный кабинет', 'fs-lms' ); ?></div>
				</div>
				<button type="button" class="s-collapse" id="sCollapse" title="<?php esc_attr_e( 'Свернуть меню', 'fs-lms' ); ?>">
					<svg width="17" height="17" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="3.5" width="15" height="13" rx="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M7.5 3.5v13" stroke="currentColor" stroke-width="1.5"/><path d="M13.8 8 11.8 10l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
			</div>

			<nav class="s-nav">
				<div class="s-navlabel"><?php esc_html_e( 'Меню', 'fs-lms' ); ?></div>
				<?php foreach ( $nav_items as $item ) : ?>
					<a class="s-item<?php echo 'learner-lessons' === $item['key'] ? ' on' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( 'screen', $item['key'], $profile_url ) ); ?>">
						<?php echo $nav_icons[ $item['key'] ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- статичный SVG. ?>
						<?php echo esc_html( $item['label'] ); ?>
					</a>
				<?php endforeach; ?>

				<?php if ( '' !== $course_title ) : ?>
					<div class="s-now">
						<div class="sn-lbl"><?php esc_html_e( 'Сейчас проходите', 'fs-lms' ); ?></div>
						<div class="sn-course"><?php echo esc_html( $course_title ); ?></div>
						<?php if ( null !== $course_progress ) : ?>
							<div class="sn-bar"><span data-width="<?php echo esc_attr( (string) (int) $course_progress['percent'] ); ?>"></span></div>
							<div class="sn-pct">
								<?php
								printf(
									/* translators: 1: percent, 2: module label */
									esc_html__( 'Пройдено %1$d%%%2$s', 'fs-lms' ),
									(int) $course_progress['percent'],
									'' !== $module_label ? esc_html( ' · ' . $module_label ) : ''
								);
								?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</nav>

			<div class="s-foot">
				<div class="s-ava"><?php echo esc_html( $student_initials ); ?></div>
				<div>
					<div class="s-uname"><?php echo esc_html( $student_name ); ?></div>
					<div class="s-urole"><?php echo esc_html( $student_role ); ?></div>
				</div>
			</div>
		</div>
	</aside>

	<!-- ══ Основная область ══ -->
	<div class="s-main">
		<header class="s-top">
			<button type="button" class="mtoggle" id="mtoggle" title="<?php esc_attr_e( 'Развернуть меню', 'fs-lms' ); ?>">
				<svg width="18" height="18" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="3.5" width="15" height="13" rx="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M7.5 3.5v13" stroke="currentColor" stroke-width="1.5"/><path d="M11.6 8l2 2-2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</button>
			<div>
				<div class="s-crumb">
					<?php esc_html_e( 'Мои курсы', 'fs-lms' ); ?><?php if ( '' !== $course_title ) : ?> · <b><?php echo esc_html( $course_title ); ?></b><?php endif; ?><?php if ( '' !== $module_label ) : ?> · <?php echo esc_html( $module_label ); ?><?php endif; ?>
				</div>
				<div class="s-title"><?php echo esc_html( $view['topic'] ); ?></div>
			</div>
			<div class="s-right">
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
					<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 3a4 4 0 0 0-4 4c0 4-1.5 5-1.5 5h11S14 11 14 7a4 4 0 0 0-4-4zM8.5 15a1.5 1.5 0 0 0 3 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
				<a class="s-ibtn" href="<?php echo esc_url( add_query_arg( 'screen', 'learner-lessons', $profile_url ) ); ?>" title="<?php esc_attr_e( 'К списку курсов', 'fs-lms' ); ?>">
					<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</a>
			</div>
		</header>

		<!-- плеер: рейка дерева + контент -->
		<div class="player">
			<div class="railwrap">
				<div class="rail" id="fsRail">
					<?php include __DIR__ . '/partials/rail.php'; ?>
				</div>
			</div>
			<div class="content">
				<div class="cscroll" id="fsScroll">
					<div class="col">
						<div class="strip" id="fsStrip"></div>
						<div class="steproot" id="fsStepRoot">
							<?php foreach ( $view['steps'] as $i => $step ) : ?>
								<?php
								// Ручное задание проходится как инлайн-шаг: «Далее» отмечает его пройденным.
								$is_manual_task = 'task' === $step['type'] && empty( $step['render']['auto_grade'] );
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

						<div class="cnav" id="fsNav">
							<button type="button" class="b b-gh" id="fsNavPrev">
								<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M12 4.5 6.5 10l5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<?php esc_html_e( 'Назад', 'fs-lms' ); ?>
							</button>
							<span class="pos" id="fsNavPos"></span>
							<button type="button" class="b b-pri" id="fsNavNext">
								<?php esc_html_e( 'Далее', 'fs-lms' ); ?>
								<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><path d="M8 4.5 13.5 10 8 15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="toast" id="fsToast">
	<svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
	<span><?php esc_html_e( 'Готово', 'fs-lms' ); ?></span>
</div>

<?php wp_footer(); ?>
</body>
</html>
