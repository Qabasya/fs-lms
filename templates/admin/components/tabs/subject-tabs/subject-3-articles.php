<?php

declare( strict_types=1 );

use Inc\Services\Subject\PostTypeResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Subject\SubjectViewDTO $dto
 */

$service = PostTypeResolver::class;
if ( $dto->articles_table ) :
	$t               = $dto->articles_table;
	$subject_key     = $dto->subject_key;
	$articles_cpt    = $service::articles( $subject_key );
	$task_number_tax = "{$subject_key}_task_number";
	?>

<div class="articles-wrapper">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Статьи</h2>
			<div class="fs-page-header__actions">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $t->post_type ) ); ?>"
					target="_blank"
					class="button button-primary">
					<?php echo esc_html( $t->post_type_object->labels->add_new ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $articles_cpt ) ); ?>"
					class="button"
					target="_blank">
					<?php esc_html_e( 'Перейти к статьям', 'fs-lms' ); ?>
				</a>
			</div>
		</div>
		<p class="fs-page-header__desc">Здесь отображаются последние 10 статей по предмету.
			<br>Для отображения всех статей и применения массовых действий нажмите на кнопку «Перейти к статьям».
		</p>
	</div>

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
