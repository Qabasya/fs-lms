<?php

declare( strict_types=1 );

use Inc\Services\Subject\PostTypeResolver;

defined( 'ABSPATH' ) || exit;

/**
 * @var \Inc\DTO\Subject\SubjectViewDTO $dto
 */

$service = PostTypeResolver::class;
require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';
?>
<div class="task-dashboard-wrapper">
	<h1 class="wp-heading-inline">Статистика по предмету</h1>
	<p class="description">Здесь отображается вся доступная статистика по выбранному предмету
	</p>
	<!-- Таблица 1 -->
	<h3 class="wp-heading-inline">Общая сводка</h3>
	<table class="wp-list-table widefat fixed striped fs-table">
		<thead>
		<tr>
			<th scope="col" class=" column-primary">Тип записи</th>
			<th scope="col" class="">Общее кол-во</th>
			<th scope="col" class="">Опубликовано</th>
			<th scope="col" class="">Черновиков</th>
		</tr>
		</thead>
		<tbody id="the-list">
		<?php
		$post_types = array(
			$service::tasks( $dto->subject_key )    => 'Заданий',
			$service::articles( $dto->subject_key ) => 'Статей',
		);

		foreach ( $post_types as $cpt_name => $label ) :
			$counts   = wp_count_posts( $cpt_name );

			$total     = array_sum( (array) $counts ) - ( $counts->trash ?? 0 );
			$published = $counts->publish ?? 0;
			$drafts    = $counts->draft ?? 0;
			?>
			<tr>
				<td class="column-primary">
					<strong><?php echo esc_html( $label ); ?></strong>
				</td>
				<td>
					<span>
						<?php echo esc_html( $total ); ?>
					</span>
				</td>
				<td>
					<span>
						<?php echo esc_html( $published ); ?>
					</span>
				</td>
				<td>
					<span>
						<?php echo esc_html( $drafts ); ?>
					</span>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<!-- Таблица 2 -->
	<h3 class="wp-heading-inline">Сводка по заданиям</h3>
	<table class="wp-list-table widefat fixed striped fs-table">
		<thead>
		<tr>
			<th scope="col" class=" column-primary">Номер задания</th>
			<th scope="col" class="">Количество задач</th>
			<th scope="col" class="">Количество статей</th>
		</tr>
		</thead>
		<tbody id="the-list">
		<?php
		$taxonomy    = $service::getTaskTaxonomy( $dto->subject_key );
		$task_cpt    = $service::tasks( $dto->subject_key );
		$article_cpt = $service::articles( $dto->subject_key );

		// Получаем все номера заданий
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) :
			foreach ( $terms as $term ) :
				// Считаем задачи для этого номера
				$tasks_query = new WP_Query(
					array(
						'post_type'      => $task_cpt,
						'post_status'    => 'publish',
						'tax_query'      => array(
							array(
								'taxonomy' => $taxonomy,
								'field'    => 'term_id',
								'terms'    => $term->term_id,
							),
						),
						'fields'         => 'ids',
						'posts_per_page' => - 1,
					)
				);
				$tasks_count = $tasks_query->found_posts;

				// Считаем статьи для этого номера
				$articles_query = new WP_Query(
					array(
						'post_type'      => $article_cpt,
						'post_status'    => 'publish',
						'tax_query'      => array(
							array(
								'taxonomy' => $taxonomy,
								'field'    => 'term_id',
								'terms'    => $term->term_id,
							),
						),
						'fields'         => 'ids',
						'posts_per_page' => - 1,
					)
				);
				$articles_count = $articles_query->found_posts;
				?>
				<tr>
					<td class="column-primary">
						<strong>Задание № <?php echo esc_html( $term->name ); ?></strong>
					</td>
					<td>
						<span ><?php echo $tasks_count; ?></span>
					</td>
					<td>
						<span ><?php echo $articles_count; ?></span>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr>
				<td colspan="3">Номера заданий еще не созданы.</td>
			</tr>
		<?php endif; ?>
		</tbody>
	</table>

	<!-- Таблица 3 -->
	<h3 class="wp-heading-inline">Сводка по каждому заданию</h3>

	<div class="filter-section fs-mb-xl">
		<label for="fs-task-number-filter"><strong>Выберите номер задания:</strong></label>
		<select id="fs-task-number-filter" class="postbox fs-mb-0">
			<option value="">— Пусто —</option>
			<?php
			$filter_terms = get_terms( array(
				'taxonomy'   => $dto->protected_tax,
				'hide_empty' => false,
			) );
			if ( ! is_wp_error( $filter_terms ) ) :
				foreach ( $filter_terms as $filter_term ) :
					?>
					<option value="<?php echo esc_attr( $filter_term->term_id ); ?>">
						Задание № <?php echo esc_html( $filter_term->name ); ?>
					</option>
					<?php
				endforeach;
			endif;
			?>
		</select>
	</div>

	<div id="fs-task-table-container"></div>

</div>
