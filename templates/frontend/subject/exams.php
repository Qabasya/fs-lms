<?php
/**
 * Раздел «Экзамены» лендинга предмета (шорткод [fs_lms_subject_exams]).
 *
 * Публичные экзамены по годам: блок на каждый год (свежие сверху), в блоке —
 * карточки «название + кнопка». Содержимое отдаёт модуль PublicExams фильтром
 * `fs_lms_subject_exams_groups` (см. SubjectLandingController).
 *
 * @var array<int|string, array<int, array{title: string, url: string}>> $groups Год => карточки.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$groups = (array) ( $groups ?? array() );
?>
<div class="fs-page-wrapper">
	<div class="fs-subject-section">
		<?php if ( empty( $groups ) ) : ?>
			<p class="fs-subject-empty">Публичных экзаменов пока нет.</p>
		<?php else : ?>
			<?php foreach ( $groups as $year => $exams ) : ?>
				<section class="fs-subject-group">
					<h2 class="fs-subject-group-title"><?php echo esc_html( (string) $year ); ?></h2>

					<div class="fs-subject-grid">
						<?php foreach ( $exams as $exam ) : ?>
							<article class="fs-subject-card fs-subject-card--exam">
								<span class="fs-subject-card-body">
									<strong class="fs-subject-card-title"><?php echo esc_html( $exam['title'] ); ?></strong>
									<a class="fs-subject-card-btn" href="<?php echo esc_url( $exam['url'] ); ?>">Приступить к экзамену</a>
								</span>
							</article>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
