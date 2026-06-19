<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// $group      — object (GroupDTO)
// $program    — array{row: GroupLessonDTO, topic: string}[]
// $roster     — StudentRecordDTO[]
// $events     — LearningEventDTO[]
// $total      — int
// $subjectKey — string
// $courses    — CourseDTO[]
?>
<main class="fs-page-wrapper">
<div id="fs-group-cockpit" class="fs-lms-cockpit-wrap"
	data-group-id="<?php echo esc_attr( (string) $group->id ); ?>"
	data-subject-key="<?php echo esc_attr( $subjectKey ); ?>">

	<h1 class="fs-cockpit-title"><?php echo esc_html( $group->name ); ?></h1>

	<div class="fs-cockpit-layout">

		<!-- Программа -->
		<section class="fs-cockpit-section fs-cockpit-program">
			<h2><?php esc_html_e( 'Программа', 'fs-lms' ); ?></h2>

			<!-- Назначение курса -->
			<?php if ( ! empty( $courses ) ) : ?>
			<div class="fs-cockpit-assign-course">
				<select id="fs-course-select">
					<option value=""><?php esc_html_e( '— выбрать курс —', 'fs-lms' ); ?></option>
					<?php foreach ( $courses as $course ) : ?>
						<option value="<?php echo esc_attr( (string) $course->id ); ?>">
							<?php echo esc_html( $course->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select id="fs-assign-policy">
					<option value="append"><?php esc_html_e( 'Добавить к программе', 'fs-lms' ); ?></option>
					<option value="replace"><?php esc_html_e( 'Заменить программу', 'fs-lms' ); ?></option>
				</select>
				<button id="fs-btn-assign-course" type="button" class="fs-cockpit-btn-primary">
					<?php esc_html_e( 'Назначить курс', 'fs-lms' ); ?>
				</button>
			</div>
			<?php endif; ?>

			<!-- Список уроков (drag-and-drop) -->
			<?php if ( ! empty( $program ) ) : ?>
				<ol class="fs-cockpit-lesson-list" id="fs-cockpit-lesson-list">
					<?php foreach ( $program as $item ) :
						$row   = $item['row'];
						$topic = $item['topic'];
						$dateVal = $row->scheduledAt ? substr( $row->scheduledAt, 0, 10 ) : '';
					?>
						<li class="fs-cockpit-lesson-item fs-cockpit-visibility-<?php echo esc_attr( $row->visibility ); ?>"
							data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>"
							data-visibility="<?php echo esc_attr( $row->visibility ); ?>"
							draggable="true">

							<span class="fs-lesson-grip" aria-hidden="true">&#8942;</span>

							<span class="fs-cockpit-lesson-topic"><?php echo esc_html( $topic ); ?></span>

							<input class="fs-lesson-date" type="date"
								value="<?php echo esc_attr( $dateVal ); ?>"
								data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>"
								aria-label="<?php esc_attr_e( 'Дата занятия', 'fs-lms' ); ?>">

							<div class="fs-cockpit-lesson-actions">
								<button class="fs-cockpit-btn-visibility fs-cockpit-visibility-badge fs-vis-<?php echo esc_attr( $row->visibility ); ?>"
									type="button"
									data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>">
									<?php echo esc_html( $row->visibility ); ?>
								</button>
								<button class="fs-cockpit-btn-remove" type="button"
									data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>"
									aria-label="<?php esc_attr_e( 'Удалить урок', 'fs-lms' ); ?>">
									&times;
								</button>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p id="fs-cockpit-empty-note"><?php esc_html_e( 'Программа пуста. Назначьте курс или добавьте уроки.', 'fs-lms' ); ?></p>
			<?php endif; ?>

			<!-- Добавить урок вручную -->
			<div class="fs-lesson-picker">
				<button id="fs-btn-add-lesson" type="button" class="fs-cockpit-btn-secondary">
					<?php esc_html_e( '+ Добавить урок', 'fs-lms' ); ?>
				</button>
				<div id="fs-lesson-picker-panel" class="fs-lesson-picker-panel" hidden>
					<div class="fs-picker-search">
						<input id="fs-picker-search-input" type="search"
							placeholder="<?php esc_attr_e( 'Поиск урока…', 'fs-lms' ); ?>">
						<div class="fs-picker-scope">
							<label>
								<input type="radio" name="fs-picker-scope" value="mine" checked>
								<?php esc_html_e( 'Мои', 'fs-lms' ); ?>
							</label>
							<label>
								<input type="radio" name="fs-picker-scope" value="subject">
								<?php esc_html_e( 'Все предмета', 'fs-lms' ); ?>
							</label>
						</div>
					</div>
					<ul id="fs-picker-results" class="fs-picker-results">
						<li class="fs-picker-empty"><?php esc_html_e( 'Введите запрос для поиска.', 'fs-lms' ); ?></li>
					</ul>
				</div>
			</div>
		</section>

		<!-- Ростер -->
		<aside class="fs-cockpit-section fs-cockpit-roster">
			<h2><?php esc_html_e( 'Ученики', 'fs-lms' ); ?></h2>
			<?php if ( empty( $roster ) ) : ?>
				<p><?php esc_html_e( 'Нет активных учеников.', 'fs-lms' ); ?></p>
			<?php else : ?>
				<ul class="fs-cockpit-roster-list">
					<?php foreach ( $roster as $record ) : ?>
						<li>
							<?php echo esc_html( $record->snapshotLastName . ' ' . $record->snapshotFirstName ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</aside>

	</div>

	<!-- Лента активности -->
	<section class="fs-cockpit-section fs-cockpit-activity" id="fs-cockpit-activity">
		<h2><?php esc_html_e( 'Активность', 'fs-lms' ); ?></h2>
		<?php if ( empty( $events ) ) : ?>
			<p><?php esc_html_e( 'Нет событий.', 'fs-lms' ); ?></p>
		<?php else : ?>
			<ul class="fs-cockpit-activity-list">
				<?php foreach ( $events as $e ) : ?>
					<li>
						<time datetime="<?php echo esc_attr( $e->createdAt ); ?>"><?php echo esc_html( $e->createdAt ); ?></time>
						—
						<span><?php echo esc_html( $e->action ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $total > 20 ) : ?>
				<button id="fs-cockpit-load-more" type="button"
					data-page="2"
					data-group-id="<?php echo esc_attr( (string) $group->id ); ?>">
					<?php esc_html_e( 'Загрузить ещё', 'fs-lms' ); ?>
				</button>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<!-- Очередь проверки работ -->
	<section class="fs-cockpit-section fs-grading-queue" id="fs-grading-queue"
		data-group-id="<?php echo esc_attr( (string) $group->id ); ?>">
		<h2><?php esc_html_e( 'Проверка работ', 'fs-lms' ); ?></h2>
		<p class="fs-loading-hint"><?php esc_html_e( 'Загрузка…', 'fs-lms' ); ?></p>
	</section>

	<!-- Журнал оценок -->
	<section class="fs-cockpit-section">
		<h2><?php esc_html_e( 'Журнал оценок', 'fs-lms' ); ?></h2>
		<div id="fs-gradebook-container"
			data-group-id="<?php echo esc_attr( (string) $group->id ); ?>">
			<p class="fs-loading-hint"><?php esc_html_e( 'Загрузка…', 'fs-lms' ); ?></p>
		</div>
	</section>

</div>
</main>
