<?php
/**
 * Раздел «Тренажёр» лендинга предмета (шорткод [fs_lms_subject_trainer]).
 *
 * Разметка общая с архивом заданий — шапку и подвал в этом случае выводит тема,
 * поэтому шаблон подключает только тело.
 *
 * @var \Inc\DTO\Task\AllTasksPageDTO $page_data
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

include __DIR__ . '/../partials/all-tasks-body.php';