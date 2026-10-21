<?php
/**
 * Раздел «Экзамены» лендинга предмета (шорткод [fs_lms_subject_exams]).
 *
 * Публичные экзамены по годам: блок на каждый год (свежие сверху), в блоке —
 * карточки «название + кнопка». Содержимое отдаёт модуль PublicExams фильтром
 * `fs_lms_subject_exams_groups` (см. SubjectLandingController).
 *
 * Над карточками — вводный текст страницы: то, что редактор написал в самой
 * странице (шорткод раздела из него вырезается), а пока там пусто — стандартный
 * текст. Страница поэтому остаётся осмысленной и между сессиями экзаменов, когда
 * карточек нет.
 *
 * @var array<int|string, array<int, array{title: string, url: string}>> $groups Год => карточки.
 *
 * @package FS LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$groups = (array) ( $groups ?? array() );

// Вводный текст: из самой страницы; шорткод раздела вырезан, иначе страница вызвала бы себя же.
$fs_exams_page  = get_queried_object();
$fs_exams_own   = $fs_exams_page instanceof \WP_Post ? trim( strip_shortcodes( $fs_exams_page->post_content ) ) : '';
$fs_exams_intro = '' !== $fs_exams_own ? (string) apply_filters( 'the_content', $fs_exams_own ) : '';
?>
<div class="fs-page-wrapper">
	<div class="fs-subject-section">
		<div class="fs-subject-intro">
			<?php if ( '' !== $fs_exams_intro ) : ?>
				<?php echo $fs_exams_intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- уже прошёл the_content и kses при сохранении страницы. ?>
			<?php else : ?>
				<p>Пробный экзамен — репетиция настоящего: те же правила и формат заданий, чтобы заранее проверить свои силы и увидеть, над чем стоит поработать. После экзамена ученик получает результат и разбор.</p>
				<p>Школа проводит пробные экзамены по расписанию. Узнать ближайшую дату и записаться можно в разделе <a href="<?php echo esc_url( home_url( '/contacts/' ) ); ?>">«Контакты»</a>.</p>
			<?php endif; ?>
		</div>

		<?php if ( empty( $groups ) ) : ?>
			<p class="fs-subject-empty">Ближайшие экзамены пока не объявлены.</p>
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
