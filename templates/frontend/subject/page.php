<?php
/**
 * Полностраничная обёртка раздела лендинга предмета.
 *
 * Подключается фильтром template_include (SubjectLandingController): страница
 * раздела рендерится разметкой плагина, без заголовка записи и колонок темы.
 * HTML самого раздела уже собран контроллером — экранировать его повторно
 * нельзя.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Services\Shared\ThemeCompatService;

ThemeCompatService::header();

// H1 раздела — заголовок страницы из админки; в макете он не показывается (см. .fs-subject-h1).
$fs_subject_h1 = get_the_title( (int) get_queried_object_id() );

if ( '' !== $fs_subject_h1 ) :
	?>
	<h1 class="fs-subject-h1"><?php echo esc_html( $fs_subject_h1 ); ?></h1>
	<?php
endif;

echo get_query_var( 'fs_subject_section' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

ThemeCompatService::footer();