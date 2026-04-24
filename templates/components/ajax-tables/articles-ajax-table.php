<?php
/**
 * @var array[]                     $rows        Массив строк: title, edit_link, terms[slug => string]
 * @var \Inc\DTO\TaxonomyDataDTO[]  $taxonomies  Видимые таксономии (без "Номер задания")
 */
?>
<table class="wp-list-table widefat fixed striped fs-table  ">
	<thead>
	<tr>
		<th class="column-primary tw-5">Номер</th>
		<th class="column-primary " >Название</th>
	</tr>
	</thead>
	<tbody>
	<?php if ( ! empty( $rows ) ) : ?>
		<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td>
					<p> Задание №<?php echo esc_html( $row['number'] ); ?> </p>
				</td>
				<td class="column-actions">
					<div class="row-actions visible">
						<span class="edit">
							<a href="<?php echo esc_url( $row['edit_link'] ); ?>" target="_blank"><?php echo esc_html( $row['title'] ); ?></a>
						</span>
					</div>
				</td>
				
			</tr>
		<?php endforeach; ?>
	<?php else : ?>
		<tr>
			<td colspan="<?php echo esc_attr( (string) ( count( $taxonomies ) + 2 ) ); ?>">Еще нет ни одной статьи</td>
		</tr>
	<?php endif; ?>
	</tbody>
</table>
