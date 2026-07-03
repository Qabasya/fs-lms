<?php
/**
 * Маунт-контейнер приложения «Конструктор курса» (canonical, design_handoff_course_builder/).
 * Само приложение рендерит JS в #fs-lms-course-builder.
 *
 * @var int           $course_id   ID редактируемого курса (0 — создание нового).
 * @var string        $subject     Ключ предмета (для создания нового курса).
 * @var \WP_Post|null $post        Объект поста (null при создании).
 * @var string        $preview_url URL предварительного просмотра.
 * @var string        $trash_url   URL перемещения в корзину.
 * @var int           $author_id   ID текущего автора.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$author_html = '';
if ( $post ) {
	ob_start();
	wp_dropdown_users( array(
		'selected' => $author_id,
		'name'     => 'post_author',
		'id'       => 'fs-course-author',
		'class'    => 'widefat',
	) );
	$author_html = ob_get_clean();
}
?>
<div class="wrap fs-lms-cb-wrap">

	<div
		id="fs-lms-course-builder"
		data-course-id="<?php echo esc_attr( (string) $course_id ); ?>"
		data-subject="<?php echo esc_attr( $subject ); ?>"
		data-preview-url="<?php echo esc_attr( $preview_url ); ?>"
		data-trash-url="<?php echo esc_attr( $trash_url ); ?>"
		data-author-html="<?php echo esc_attr( $author_html ); ?>"
	></div>
</div>
