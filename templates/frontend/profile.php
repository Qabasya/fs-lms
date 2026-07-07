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

use Inc\Enums\Ui\Icon;

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
<body <?php body_class( 'fs-profile-page' ); ?>>

<div class="prof-app">

	<aside class="prof-sidebar">
		<div class="prof-brand">
<!--        TODO: заменить на логотип в меню настройки стилей-->
            <div class="prof-brand-mark">
				<?php echo Icon::BrandMark->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div>
				<div class="prof-brand-name"><span>Шаг в будущее</span></div>
				<div class="prof-brand-sub">Личный кабинет</div>
			</div>
			<button type="button" class="prof-collapse" id="profCollapse" title="Свернуть меню">
				<?php echo Icon::SidebarCollapse->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>

		<!-- Навигация + группы наполняет JS из window.fsProfile -->
		<div class="prof-side-scroll" id="profNav"></div>

		<!-- Блок пользователя наполняет JS -->
		<div class="prof-side-user" id="profUser"></div>
	</aside>

	<div class="prof-main">
		<header class="prof-topbar">
			<button type="button" class="prof-mtoggle" id="profMenuOn" title="Развернуть меню">
				<?php echo Icon::SidebarExpand->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<div class="prof-tb-titles">
				<div class="prof-tb-crumb" id="profTbCrumb">Личный кабинет</div>
				<div class="prof-tb-title" id="profTbTitle">Главная</div>
			</div>
			<span class="prof-tb-spacer"></span>
			<button class="prof-icon-ghost" title="Уведомлений нет">
				<?php echo Icon::Bell->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<a class="prof-icon-ghost prof-home-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" title="Вернуться на главную">
				<?php echo Icon::Home->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
	<?php echo Icon::Check->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<span>Готово</span>
</div>

<?php wp_footer(); ?>
</body>
</html>
