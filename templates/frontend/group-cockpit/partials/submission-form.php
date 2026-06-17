<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Requires in scope: $work (WorkDTO), $row (GroupLessonDTO)
// Optional in scope: $existing (SubmissionDTO|null)
?>
<form class="fs-submission-form"
	data-group-lesson-id="<?php echo esc_attr( (string) $row->id ); ?>"
	data-work-id="<?php echo esc_attr( (string) $work->id ); ?>">

	<h4 class="fs-submission-form__title"><?php esc_html_e( 'Сдать работу', 'fs-lms' ); ?></h4>

	<?php if ( $row->homeworkDueAt ) : ?>
		<p class="fs-submission-status">
			<?php
			echo esc_html__( 'Срок сдачи: ', 'fs-lms' ) . esc_html( $row->homeworkDueAt );
			if ( ! $row->allowLate && current_time( 'mysql' ) > $row->homeworkDueAt ) {
				echo ' <span class="fs-submission-late">' . esc_html__( '(просрочено)', 'fs-lms' ) . '</span>';
			}
			?>
		</p>
	<?php endif; ?>

	<div class="fs-submission-form__field">
		<label for="fs-answer-<?php echo esc_attr( (string) $work->id ); ?>">
			<?php esc_html_e( 'Ответ', 'fs-lms' ); ?>
		</label>
		<textarea
			id="fs-answer-<?php echo esc_attr( (string) $work->id ); ?>"
			name="answer_text"
			><?php echo $existing ? esc_textarea( $existing->answerText ?? '' ) : ''; ?></textarea>
	</div>

	<div class="fs-submission-form__field">
		<label><?php esc_html_e( 'Прикрепить файл (необязательно)', 'fs-lms' ); ?></label>
		<input type="file" name="submission_file" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" />
	</div>

	<button type="submit" class="fs-submission-form__submit">
		<?php echo $existing ? esc_html__( 'Пересдать', 'fs-lms' ) : esc_html__( 'Сдать', 'fs-lms' ); ?>
	</button>

	<p class="fs-submission-status" aria-live="polite"></p>

</form>
