<?php
/**
 * Ключи метаданных позиции Woo-заказа, связывающие её с официальным экзаменом.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Enums\Exam;

/** Ключи метаданных позиции Woo-заказа, связывающие её с официальным экзаменом. */
enum ExamOrderItemMeta: string {

	case Application = '_fs_exam_application';
}
