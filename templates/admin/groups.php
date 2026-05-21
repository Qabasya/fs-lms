<?php
/**
 * Шаблон страницы управления группами.
 *
 * @var array $subjects          Список предметов (передан из коллбека)
 * @var array $academic_periods  Список академических периодов (передан из коллбека)
 */
?>

	<div class="wrap">
		<h1 class="wp-heading-inline">Группы</h1>

		<a href="#" class="page-title-action">Добавить группу</a>

		<hr class="wp-header-end">

		<div class="tab-content" style="margin-top: 20px;">
			<div class="card" style="max-width: 100%; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h2>Список групп</h2>
				<p>Здесь будет отображаться таблица управления группами студентов (в разработке).</p>

				<?php
				// Переданные переменные $subjects и $academic_periods уже доступны здесь,
				// если они понадобятся для фильтров или привязки групп к периодам/предметам.
				?>
			</div>
		</div>
	</div>

<?php
// Сюда можно будет подключать модальные окна для групп, когда они понадобятся:
// $group_modal_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . '/../modals/add-group-modal.php';
// if ( file_exists( $group_modal_path ) ) {
//     include $group_modal_path;
// }
?>