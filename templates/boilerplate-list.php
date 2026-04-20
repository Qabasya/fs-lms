<?php
/**
 * Шаблон списка типовых условий (boilerplate) для конкретного задания.
 *
 * @var string $subject Ключ предмета (например, "math")
 * @var string $term Слаг типа задания (например, "1", "2")
 * @var \Inc\DTO\TaskTypeBoilerplateDTO[] $boilerplates Массив DTO типовых условий
 */

use Inc\Enums\Nonce;

// ============================ ПОДГОТОВКА ДАННЫХ ============================

// Формируем название таксономии для получения объекта термина
$taxonomy = $subject . '_task_number';

// Получаем объект термина по его слагу
$term_object = get_term_by( 'slug', $term, $taxonomy );

// Определяем отображаемое имя: если описание есть — берем его, иначе оставляем слаг
$display_name = ( $term_object && ! empty( $term_object->description ) )
		? $term_object->description
		: $term;

// Получаем массив всех предметов из опций
$all_subjects = get_option( 'fs_lms_subjects_list', array() );

// Поиск названия предмета по ключу
$subject_display_name = $subject; // Значение по умолчанию

if ( ! empty( $all_subjects ) ) {
	foreach ( $all_subjects as $s ) {
		if ( $s['key'] === $subject ) {
			$subject_display_name = $s['name'];
			break;
		}
	}
}
?>

<div class="wrap boilerplate-manager-wrapper">
	<?php wp_nonce_field( Nonce::SaveBoilerplate->value, 'nonce' ); ?>

	<h1 class="wp-heading-inline">Типовые условия</h1>
	<p class="description"><?php echo esc_html( $display_name ); ?> / <?php echo esc_html( $subject_display_name ); ?></p>
	<hr class="wp-header-end">

	<table class="wp-list-table widefat fixed striped js-boilerplate-table">
		<thead>
		<tr>
			<th class="manage-column column-primary">Название шаблона</th>
			<th class="manage-column column-actions">Действия</th>
		</tr>
		</thead>

		<tbody id="the-list">
		<?php if ( empty( $boilerplates ) ) : ?>
			<tr class="no-items">
				<td colspan="2">Для этого задания еще не создано типовых условий.</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $boilerplates as $bp ) : ?>
				<tr data-uid="<?php echo esc_attr( $bp->uid ); ?>">
					<td class="column-title">
						<strong>
							<a href="<?php echo esc_url( admin_url( "admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}" ) ); ?>">
								<?php echo esc_html( $bp->title ); ?>
							</a>
						</strong>
					</td>
					<td class="column-actions">
						<div class="row-actions visible">
							<span class="edit">
								<a href="<?php echo esc_url( admin_url( "admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}" ) ); ?>">Изменить</a>
							</span>
							<span class="trash"> |
								<a href="#" class="delete-boilerplate-link" data-uid="<?php echo esc_attr( $bp->uid ); ?>">Удалить</a>
							</span>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>

		<tfoot>
		<tr class="fs-add-row-tr">
			<td colspan="2">
				<a href="<?php echo admin_url( "admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=new" ); ?>"
					class="button-link js-add-boilerplate" title="Добавить новое условие">
					<span class="dashicons dashicons-plus"></span>
				</a>
			</td>
		</tr>
		</tfoot>
	</table>

	<p style="margin-top: 20px;">
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page' => 'fs_subject_' . $subject,
					'tab'  => 'tab-5',
				),
				admin_url( 'admin.php' )
			)
		);
		?>
		" class="button">
			&larr; Назад в Менеджер заданий
		</a>
	</p>
</div>
