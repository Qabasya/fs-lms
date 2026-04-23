<?php
/** @var \Inc\DTO\SubjectViewDTO $dto */
require_once FS_LMS_PATH . 'templates/ui_renderers.php';

?>

<div class="task-dashboard-wrapper">
	<h1 class="wp-heading-inline">Статистика по предмету</h1>
	<p class="description">Здесь отображается вся доступная статистика по выбранному предмету
	</p>
<!-- Таблица 1 -->
	<h3 class="wp-heading-inline">Общая сводка</h3>
	<table class="wp-list-table widefat fixed striped">
		<thead>
		<tr>
			<th scope="col" class="manage-column column-primary">Тип записи</th>
			<th scope="col" class="manage-column">Общее кол-во</th>
			<th scope="col" class="manage-column">Опубликовано</th>
			<th scope="col" class="manage-column">Черновиков</th>
		</tr>
		</thead>
		<tbody id="the-list">
		<?php
		$post_types = array(
			'tasks'    => 'Заданий',
			'articles' => 'Статей',
		);

		foreach ( $post_types as $suffix => $label ) :
			$cpt_name = "{$dto->subject_key}_{$suffix}";
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
					<span class="badge gray"><?php echo esc_html( $total ); ?></span>
				</td>
				<td>
			<span class="badge blue">
				<?php echo esc_html( $published ); ?>
			</span>
				</td>
				<td>
			<span class="badge" style="color: #646970;">
				<?php echo esc_html( $drafts ); ?>
			</span>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

<!-- Таблица 2 -->
	<h3 class="wp-heading-inline">Сводка по заданиям</h3>
	<table class="wp-list-table widefat fixed striped">
		<thead>
		<tr>
			<th scope="col" class="manage-column column-primary">Номер задания</th>
			<th scope="col" class="manage-column">Количество задач</th>
			<th scope="col" class="manage-column">Количество статей</th>
		</tr>
		</thead>
		<tbody id="the-list">
		<tr>
			<td class="column-primary">
				<strong>Заданий</strong>
			</td>
			<td>
				<?php
				$tasks_count = wp_count_posts( "{$dto->subject_key}_tasks" );
				echo esc_html( array_sum( (array) $tasks_count ) );
				?>
			</td>
		</tr>
		<tr>
			<td class="column-primary">
				<strong>Статей</strong>
			</td>
			<td>
				<?php
				$articles_count = wp_count_posts( "{$dto->subject_key}_articles" );
				echo esc_html( array_sum( (array) $articles_count ) );
				?>
			</td>
		</tr>
		</tbody>
	</table>

</div>
