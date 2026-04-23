<?php
/**
 * @var WP_Query $query
 * @var array    $taxonomies
 * @var string   $number_taxonomy
 */

?>
<table class="wp-list-table widefat fixed striped js-task-manager-table">
	<thead>
	<tr>
		<th class="column-primary">Название задачи</th>
		<?php
		foreach ( $taxonomies as $tax ) :
			if ( $tax->slug === $number_taxonomy ) {
				continue;
			}
			?>
			<th scope="col"><?php echo esc_html( $tax->name ); ?></th>
		<?php endforeach; ?>
		<th class="column-actions">Действия</th>
	</tr>
	</thead>
	<tbody>
	<?php if ( $query->have_posts() ) : ?>
		<?php
		while ( $query->have_posts() ) :
			$query->the_post();
			?>
			<tr>
				<td class="column-title">
					<strong><?php the_title(); ?></strong>
				</td>

				<?php
				foreach ( $taxonomies as $tax ) :
					if ( $tax->slug === $number_taxonomy ) {
						continue;
					}

					$terms = get_the_terms( get_the_ID(), $tax->slug );
					$names = ! empty( $terms ) && ! is_wp_error( $terms )
							? implode( ', ', wp_list_pluck( $terms, 'name' ) )
							: '—';
					?>
					<td><?php echo esc_html( $names ); ?></td>
				<?php endforeach; ?>
				<td class="column-actions">
					<div class="row-actions visible">
						<span class="edit">
							<a href="<?php echo get_edit_post_link(); ?>" target="_blank">Редактировать</a>
						</span>
					</div>
				</td>
			</tr>
			<?php
		endwhile;
		wp_reset_postdata();
		?>
	<?php else : ?>
		<tr>
			<td colspan="<?php echo count( $taxonomies ) + 1; ?>">Задач по этому номеру не найдено.</td>
		</tr>
	<?php endif; ?>
	</tbody>
</table>
