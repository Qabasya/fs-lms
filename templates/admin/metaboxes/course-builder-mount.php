<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Монтажная точка конструктора курса (разметку целиком строит course-builder.js).
 *
 * @var int    $course_id ID курса
 * @var string $subject   Ключ предмета
 */
?>
<div id="fs-lms-course-builder"
	class="fs-lms-cb-wrap"
	data-course-id="<?php echo esc_attr( (string) $course_id ); ?>"
	data-subject="<?php echo esc_attr( $subject ); ?>"></div>
