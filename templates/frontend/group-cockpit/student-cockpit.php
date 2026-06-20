<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// $group   — GroupDTO
// $lessons — array{ row: GroupLessonDTO, topic: string, works: WorkDTO[], submissions: SubmissionDTO[] }[]
?>
<main class="fs-page-wrapper">
<div class="fs-lms-cockpit-wrap fs-student-cockpit">

	<h1 class="fs-cockpit-title"><?php echo esc_html( $group->name ); ?></h1>

	<?php if ( empty( $lessons ) ) : ?>
		<p><?php esc_html_e( 'Открытых уроков пока нет.', 'fs-lms' ); ?></p>
	<?php else : ?>
		<?php foreach ( $lessons as $item ) :
			$row         = $item['row'];
			$topic       = $item['topic'];
			$works       = $item['works'];
			$submissions = $item['submissions'];

			$subsByWork = [];
			foreach ( $submissions as $sub ) {
				$subsByWork[ $sub->workId ] = $sub;
			}
		?>
		<section class="fs-cockpit-section">
			<h2><?php echo esc_html( $topic ); ?></h2>

			<a class="fs-cockpit-play" href="<?php echo esc_url( add_query_arg( array( 'gid' => $group->id, 'gl' => $row->id ), \Inc\Enums\Wp\PageRoutes::GroupCockpit->url() ) ); ?>">
				<?php esc_html_e( 'Пройти урок по шагам →', 'fs-lms' ); ?>
			</a>

			<?php if ( $row->scheduledAt ) : ?>
				<p class="fs-lesson-date">
					<?php echo esc_html( $row->scheduledAt ); ?>
				</p>
			<?php endif; ?>

			<?php if ( empty( $works ) ) : ?>
				<p><?php esc_html_e( 'В этом уроке нет работ.', 'fs-lms' ); ?></p>
			<?php else : ?>
				<?php foreach ( $works as $work ) :
					$existing = $subsByWork[ $work->id ] ?? null;
					$status   = $existing ? $existing->status->value : null;
					$isGraded = $existing && $existing->status->isTerminal();
				?>
				<div class="fs-work-submission" data-work-id="<?php echo esc_attr( (string) $work->id ); ?>">

					<h3 class="fs-work-title"><?php echo esc_html( $work->title ); ?></h3>

					<?php if ( $work->instructions ) : ?>
						<div class="fs-work-instructions">
							<?php echo wp_kses_post( $work->instructions ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $existing && $isGraded ) : ?>

						<div class="fs-submission-result fs-submission-result--graded">
							<div class="fs-submission-result__score">
								<?php
								$scoreText = $existing->score !== null
									? esc_html( $existing->score ) . ' / ' . esc_html( (string) $existing->maxScore )
									: '—';
								echo 'Оценка: ' . $scoreText; // phpcs:ignore WordPress.Security.EscapeOutput
								?>
							</div>
							<?php if ( $existing->feedback ) : ?>
								<div class="fs-submission-result__feedback">
									<?php echo esc_html( $existing->feedback ); ?>
								</div>
							<?php endif; ?>
						</div>

					<?php elseif ( $existing && 'returned' === $status ) : ?>

						<div class="fs-submission-result fs-submission-result--returned">
							<p><?php esc_html_e( 'Работа возвращена на доработку.', 'fs-lms' ); ?></p>
							<?php if ( $existing->feedback ) : ?>
								<div class="fs-submission-result__feedback">
									<?php echo esc_html( $existing->feedback ); ?>
								</div>
							<?php endif; ?>
						</div>
						<?php // fall through to show re-submission form below ?>
						<?php include __DIR__ . '/partials/submission-form.php'; ?>

					<?php elseif ( $existing && 'submitted' === $status ) : ?>

						<div class="fs-submission-result fs-submission-result--submitted">
							<p><?php esc_html_e( 'Работа сдана, ожидает проверки.', 'fs-lms' ); ?></p>
						</div>

					<?php else : ?>

						<?php include __DIR__ . '/partials/submission-form.php'; ?>

					<?php endif; ?>

				</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<?php endforeach; ?>
	<?php endif; ?>

</div>
</main>
