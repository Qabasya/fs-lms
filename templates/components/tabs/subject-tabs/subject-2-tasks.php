<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
?>

<?php
if ( $dto->tasks_table ) :
	$t               = $dto->tasks_table;
	$subject_key     = $dto->subject_key;
	$task_cpt        = "{$subject_key}_tasks";
	$task_number_tax = "{$subject_key}_task_number";
	?>

<div class="tasks-wrapper">

	<div class="header-row">
		<h1 class="wp-heading-inline">Задания</h1>


		<div class="description-actions">

			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $t->post_type ) ); ?>"
				class="page-title-action btn-filled">
				<?php echo esc_html( $t->post_type_object->labels->add_new ); ?>
			</a>

			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $task_cpt ) ); ?>"
				class="page-title-action"
			target="_blank">
				<?php esc_html_e( 'Перейти к заданиям', 'fs-lms' ); ?>
			</a>

		</div>

	</div>
	<p class="description">Здесь отображаются последние 10 заданий по предмету.
		<br>Для отображения всех заданий и применения массовых действий нажмите на кнопку «Перейти к заданиям».
	</p>

	<div id="fs-recent-tasks-container" data-subject="<?php echo esc_attr( $subject_key ); ?>">
		<p class="description">Загрузка последних задач...</p>
	</div>


	<?php
	$t->restore();
	endif;
?>
</div>