<?php
/**
 * Bare-шелл страницы прохождения контрольной/экзамена (Эпик 15, T15.1/T15.3) —
 * свой <html> без темы сайта, по образцу lesson-player/player.php. Переиспользует
 * токены и атомы плеера (assessment.min.css грузит те же компоненты, что и
 * player.min.css — см. src/scss/assessment/assessment.scss), но своим бандлом.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO $assessment
 * @var string                            $backUrl  Ссылка «Вернуться» (T15.7).
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
			<a class="s-back" href="<?php echo esc_url( $backUrl ); ?>">
				<svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M15 5v4a4 4 0 0 1-4 4H5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 9.5 4.3 13 8 16.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<?php esc_html_e( 'Вернуться', 'fs-lms' ); ?>
			</a>
			<div>
				<div class="s-crumb"><?php echo esc_html( $assessment->kind->label() ); ?></div>
				<div class="s-title"><?php echo esc_html( $assessment->title ); ?></div>
			</div>
		</header>

		<div class="player">
			<div class="content">
				<div class="cscroll">
					<div class="col">
