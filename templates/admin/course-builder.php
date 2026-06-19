<?php
/**
 * Маунт-контейнер приложения «Конструктор курса» (canonical, design_handoff_course_builder/).
 * Само приложение (course-strip + дерево + редактор) рендерит JS в #fs-lms-course-builder.
 *
 * @var int    $course_id ID редактируемого курса (0 — создание нового).
 * @var string $subject   Ключ предмета (для создания нового курса).
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap fs-lms-cb-wrap">
	<div class="fs-cb-heading-row">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Конструктор курса', 'fs-lms' ); ?></h1>
	</div>
	<p class="fs-cb-subtitle">
		<?php esc_html_e( 'Создавайте структуру курса: модули, уроки и шаги. Перетаскивайте уроки для изменения порядка.', 'fs-lms' ); ?>
	</p>

	<div
		id="fs-lms-course-builder"
		data-course-id="<?php echo esc_attr( (string) $course_id ); ?>"
		data-subject="<?php echo esc_attr( $subject ); ?>"
	></div>
</div>
