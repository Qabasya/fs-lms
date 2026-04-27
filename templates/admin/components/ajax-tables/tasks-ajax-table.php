<?php
/**
 * @var array[]                     $rows        Массив строк: title, edit_link, terms[slug => string]
 * @var \Inc\DTO\TaxonomyDataDTO[]  $taxonomies  Видимые таксономии (без "Номер задания")
 */
?>
<table class="wp-list-table widefat fixed striped fs-table  ">
	<thead>
	<tr>
		<th class="column-primary tw-10">Номер</th>
		<th class="column-primary tw-20" >Название</th>
		<?php foreach ( $taxonomies as $tax ) : ?>
			<th scope="col" ><?php echo esc_html( $tax->name ); ?></th>
		<?php endforeach; ?>
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
				<?php
				foreach ( $taxonomies as $tax ) :
					$val = $row['terms'][ $tax->slug ] ?? '';
					?>
					
					<td data-val="<?php echo esc_attr( $val ); ?>">
						<?php echo $val !== '' ? esc_html( $val ) : '—'; ?>
					</td>
				
				<?php endforeach; ?>
				

			</tr>
		<?php endforeach; ?>
	<?php else : ?>
		<tr>
			<td colspan="<?php echo esc_attr( (string) ( count( $taxonomies ) + 2 ) ); ?>">Еще нет ни одного задания</td>
		</tr>
	<?php endif; ?>
	</tbody>
</table>
