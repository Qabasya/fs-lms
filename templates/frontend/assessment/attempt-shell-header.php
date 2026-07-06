<?php
/**
 * Bare-шелл страницы прохождения контрольной/экзамена (Эпик 15, T15.1/T15.3) —
 * свой <html> без темы сайта, по образцу lesson-player/player.php. Переиспользует
 * токены и атомы плеера (assessment.min.css грузит те же компоненты, что и
 * player.min.css — см. src/scss/assessment/assessment.scss), но своим бандлом.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO $assessment
 * @var string                            $backUrl        Ссылка «Вернуться» (T15.7).
 * @var bool                              $examInProgress Идёт незавершённая попытка —
 *                                                        тогда кнопки выхода нет (выход
 *                                                        только через сдачу работы).
 *
 * @package FS LMS
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $assessment->title ); ?></title>
	<meta name="robots" content="noindex, nofollow">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
	<?php wp_head(); ?>
</head>
<body class="fs-player-page">
<div class="app" id="fsAssessmentApp">
	<div class="s-main">
		<header class="s-top">
			<?php // Кнопки «Вернуться» в шапке нет вовсе: выход — только через страницу
			// результата («Вернуться к курсу»). Пока идёт попытка — индикатор-замок. ?>
			<?php if ( ! empty( $examInProgress ) ) : ?>
				<span class="s-locked" title="<?php esc_attr_e( 'Выйти можно только сдав работу', 'fs-lms' ); ?>">
					<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="4.5" y="8.5" width="11" height="8" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M7 8.5V6.5a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5"/></svg>
					<?php esc_html_e( 'Идёт контрольная', 'fs-lms' ); ?>
				</span>
			<?php endif; ?>
			<div>
				<div class="s-crumb"><?php echo esc_html( $assessment->kind->label() ); ?></div>
				<div class="s-title"><?php echo esc_html( $assessment->title ); ?></div>
			</div>
			<?php if ( ! empty( $examInProgress ) && $assessment->timeLimit > 0 ) : ?>
				<?php /* Таймер в липкой шапке — всегда виден при скролле; заполняется assessment.min.js. */ ?>
				<span class="s-timer" id="fs-assessment-timer">
					<svg width="15" height="15" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4.2l2.8 1.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
					<span id="fs-timer-display">—</span>
				</span>
			<?php endif; ?>
		</header>

		<div class="player">
			<div class="content">
				<div class="cscroll">
					<div class="col">
