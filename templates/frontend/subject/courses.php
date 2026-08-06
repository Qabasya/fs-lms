<?php
/**
 * Раздел «Курсы» лендинга предмета (шорткод [fs_lms_subject_courses]).
 *
 * Витрина опубликованных курсов: черновики и архив — внутренние состояния
 * конструктора и сюда не попадают. Пермалинка у курса нет, поэтому карточка
 * ведёт на заявку с пометкой курса (PublicCourseService::enrollUrl).
 *
 * @var \Inc\DTO\Course\CourseCardDTO[] $courses Опубликованные курсы предмета.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Services\Shared\Pluralizer;

$courses = (array) ( $courses ?? array() );
?>
<div class="fs-page-wrapper">
	<div class="fs-subject-section">
		<?php if ( empty( $courses ) ) : ?>
			<p class="fs-subject-empty">Курсы пока не опубликованы.</p>
		<?php else : ?>
			<div class="fs-subject-grid">
				<?php foreach ( $courses as $course ) : ?>
					<article class="fs-subject-card">
						<span class="fs-subject-card-body">
							<strong class="fs-subject-card-title"><?php echo esc_html( $course->title ); ?></strong>

							<?php if ( $course->lessons > 0 ) : ?>
								<span class="fs-subject-card-meta">
									<?php echo esc_html( Pluralizer::withNumber( $course->lessons, 'урок', 'урока', 'уроков' ) ); ?>
								</span>
							<?php endif; ?>

							<a class="fs-subject-card-btn" href="<?php echo esc_url( $course->url ); ?>">Записаться</a>
						</span>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>