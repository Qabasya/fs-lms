<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
?>

<h3>Управление задачами</h3>
<div class="card">
	<p>Перейти к полному списку всех задач по предмету:</p>
	<a href="<?php echo esc_url($dto->tasks_url); ?>" class="button button-primary">
		Открыть список задач
	</a>
</div>