<?php

declare( strict_types=1 );

use Inc\Services\Subject\PostTypeResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Subject\SubjectViewDTO $dto
 */

$service = PostTypeResolver::class;
if ( $dto->tasks_table ) :
	$t               = $dto->tasks_table;
	$subject_key     = $dto->subject_key;
	$task_cpt        = $service::tasks( $subject_key );
	$task_number_tax = "{$subject_key}_task_number";
	?>

<div class="tasks-wrapper">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Задания</h2>
			<div class="fs-page-header__actions">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $t->post_type ) ); ?>"
					class="button button-primary">
					<?php echo esc_html( $t->post_type_object->labels->add_new ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $task_cpt ) ); ?>"
					class="button"
					target="_blank">
					<?php esc_html_e( 'Перейти к заданиям', 'fs-lms' ); ?>
				</a>
			</div>
		</div>
		<p class="fs-page-header__desc">Здесь отображаются последние 10 заданий по предмету.
			<br>Для отображения всех заданий и применения массовых действий нажмите на кнопку «Перейти к заданиям».
		</p>
	</div>

    <div id="fs-recent-tasks-container"
         class="fs-recent-container"
         data-subject="<?php echo esc_attr( $subject_key ); ?>"
         data-type="tasks">
        <p class="description">Загрузка последних заданий...</p>
    </div>



    <?php
	$t->restore();
	endif;
?>
</div>
