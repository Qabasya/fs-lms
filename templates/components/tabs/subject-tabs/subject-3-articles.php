<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
?>

<?php
if ( $dto->articles_table ) :
	$t               = $dto->articles_table;
	$subject_key     = $dto->subject_key;
	$articles_cpt    = "{$subject_key}_articles";
	$task_number_tax = "{$subject_key}_task_number";
	?>

<div class="articles-wrapper">

	<div class="header-row">
		<h1 class="wp-heading-inline">Статьи</h1>


		<div class="description-actions">

			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $t->post_type ) ); ?>"
               target="_blank"
				class="page-title-action btn-filled">
				<?php echo esc_html( $t->post_type_object->labels->add_new ); ?>
			</a>

			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $articles_cpt ) ); ?>"
				class="page-title-action"
				target="_blank">
				<?php esc_html_e( 'Перейти к статьям', 'fs-lms' ); ?>
			</a>

		</div>

	</div>
	<p class="description">Здесь отображаются последние 10 статей по предмету.
		<br>Для отображения всех статей и применения массовых действий нажмите на кнопку «Перейти к статьям».
	</p>

	<div id="fs-recent-articles-container"
		class="fs-recent-container"
		data-subject="<?php echo esc_attr( $subject_key ); ?>"
		data-type="articles">
		<p class="description">Загрузка последних статей...</p>
	</div>


	<?php
	$t->restore();
	endif;
?>
</div>