<?php
/**
 * Личный кабинет (профиль) — полноэкранный SPA.
 *
 * Статичный каркас: бренд, топбар, оверлеи. Сайдбар (#profNav, #profUser) и
 * сцену (#profStage) наполняет JS по конфигу `window.fsProfile` (роль → витрина).
 * Доступ и режим (read-only) приходят с сервера через ProfileViewResolver.
 *
 * @package FS LMS
 */

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/sign-in/' ) );
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Личный кабинет</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
	<?php wp_head(); ?>
</head>
<body class="fs-profile-page">

<div class="prof-app">

	<aside class="prof-sidebar">
		<div class="prof-brand">
<!--        TODO: заменить на логотип в меню настройки стилей-->
            <div class="prof-brand-mark">
				<svg width="18" height="18" viewBox="0 0 20 20" fill="none"><path d="M3 5.5 10 2l7 3.5L10 9 3 5.5z" fill="#fff"/><path d="M6 8v3.5c0 1.2 1.8 2.2 4 2.2s4-1 4-2.2V8" stroke="#fff" stroke-width="1.4" fill="none"/></svg>
			</div>
			<div>
				<div class="prof-brand-name"><span>Шаг в будущее</span></div>
				<div class="prof-brand-sub">Личный кабинет</div>
			</div>
		</div>

		<!-- Навигация + группы наполняет JS из window.fsProfile -->
		<div class="prof-side-scroll" id="profNav"></div>

		<!-- Блок пользователя наполняет JS -->
		<div class="prof-side-user" id="profUser"></div>
	</aside>

	<div class="prof-main">
		<header class="prof-topbar">
			<div class="prof-tb-titles">
				<div class="prof-tb-crumb" id="profTbCrumb">Личный кабинет</div>
				<div class="prof-tb-title" id="profTbTitle">Главная</div>
			</div>
			<span class="prof-tb-spacer"></span>
			<button class="prof-icon-ghost" title="Уведомлений нет">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 3a4 4 0 0 0-4 4c0 4-1.5 5-1.5 5h11S14 11 14 7a4 4 0 0 0-4-4zM8.5 15a1.5 1.5 0 0 0 3 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</button>
			<a class="prof-icon-ghost prof-home-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" title="Вернуться на главную">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M3 9.5 10 4l7 5.5M5 8.5V16h10V8.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</a>
		</header>

		<!-- Экраны создаёт JS из window.fsProfile.screens -->
		<div class="prof-stage" id="profStage"></div>
	</div>
</div>

<!-- Shared overlays -->
<div class="prof-ctx-backdrop" id="profCtxBackdrop"></div>
<div class="prof-ctx-menu" id="profCtxMenu"></div>
<div class="prof-grade-pop" id="profGradePop"></div>
<div class="prof-toast" id="profToast">
	<svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M4 10.5 8 14l8-8.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
	<span>Готово</span>
</div>

<?php wp_footer(); ?>
</body>
</html>
