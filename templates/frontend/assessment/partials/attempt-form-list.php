<?php
/**
 * Одностраничная форма попытки (Control): все задания списком на одном экране.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO $assessment
 * @var array<int, mixed>                 $taskViews
 */
declare( strict_types=1 );
?>
<form class="fs-attempt-form" novalidate>
	<?php foreach ( $assessment->taskIds as $i => $taskId ) : ?>
		<?php require __DIR__ . '/attempt-question.php'; ?>
	<?php endforeach; ?>

	<div class="fs-attempt-actions">
		<button type="submit" class="fs-btn fs-btn--primary fs-submit-attempt-btn">
			Сдать
		</button>
	</div>
</form>
