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

echo get_query_var( 'fs_subject_section' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

ThemeCompatService::footer();