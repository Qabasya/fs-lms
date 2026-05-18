<?php
/**
 * Абсолютно изолированный холст для страницы авторизации.
 * Не зависит от get_header() и get_footer() темы, исключая любой мусор.
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php
	// Подключает стили плагинов, стили WordPress и твой скомпилированный SCSS
	wp_head();
	?>
	<title><?php wp_title( '|', true, 'right' ); ?></title>
</head>
<body <?php body_class(); ?>>

<div class="fs-lms-viewport-wrapper">
	<?php
	// Запускаем стандартный цикл, который вызовет наш шорткод [fs_lms_login_form]
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</div>

<?php
// Подключает скрипты (включая jQuery и логику нашего "глазика")
wp_footer();
?>
</body>
</html>