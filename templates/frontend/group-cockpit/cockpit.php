<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// $group   — object
// $program — array{row: GroupLessonDTO, topic: string}[]
// $roster  — StudentRecordDTO[]
// $events  — LearningEventDTO[]
// $total   — int (total events)
?>
<main class="fs-page-wrapper">
<div id="fs-group-cockpit" class="fs-lms-cockpit-wrap" data-group-id="<?php echo esc_attr( (string) $group->id ); ?>">

	<h1 class="fs-cockpit-title"><?php echo esc_html( $group->name ); ?></h1>

	<div class="fs-cockpit-layout">

		<!-- Программа -->
		<section class="fs-cockpit-section fs-cockpit-program">
			<h2><?php esc_html_e( 'Программа', 'fs-lms' ); ?></h2>

			<?php if ( empty( $program ) ) : ?>
				<p><?php esc_html_e( 'Программа пуста. Назначьте курс или добавьте уроки.', 'fs-lms' ); ?></p>
			<?php else : ?>
				<ol class="fs-cockpit-lesson-list" id="fs-cockpit-lesson-list">
					<?php foreach ( $program as $item ) :
						$row   = $item['row'];
						$topic = $item['topic'];
					?>
						<li class="fs-cockpit-lesson-item fs-cockpit-visibility-<?php echo esc_attr( $row->visibility ); ?>"
							data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>">

							<span class="fs-cockpit-lesson-topic"><?php echo esc_html( $topic ); ?></span>

							<span class="fs-cockpit-lesson-visibility">
								<?php echo esc_html( $row->visibility ); ?>
							</span>

							<?php if ( $row->scheduledAt ) : ?>
								<time class="fs-cockpit-lesson-date" datetime="<?php echo esc_attr( $row->scheduledAt ); ?>">
									<?php echo esc_html( $row->scheduledAt ); ?>
								</time>
							<?php endif; ?>

							<div class="fs-cockpit-lesson-actions">
								<button class="fs-cockpit-btn-visibility" type="button"
									data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>">
									<?php esc_html_e( 'Видимость', 'fs-lms' ); ?>
								</button>
								<button class="fs-cockpit-btn-remove" type="button"
									data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>">
									<?php esc_html_e( 'Удалить', 'fs-lms' ); ?>
								</button>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
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
				<button id="fs-cockpit-load-more" type="button" data-page="2" data-group-id="<?php echo esc_attr( (string) $group->id ); ?>">
					<?php esc_html_e( 'Загрузить ещё', 'fs-lms' ); ?>
				</button>
			<?php endif; ?>
		<?php endif; ?>
	</section>

</div>
</main>
