<?php
// Определяем активную вкладку, по умолчанию tab-1
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'tab-1';

// Массив вкладок
$tabs = array(
	'tab-1' => array(
		'title' => 'Предметы',
		'file'  => '/components/tabs/subjects-manager.php',
	),
	'tab-2' => array(
		'title' => 'Вкладка 2',
		'file'  => '/components/tabs/tab-2.php',
	),
	'tab-3' => array(
		'title' => 'Вкладка 3',
		'file'  => '/components/tabs/tab-3.php',
	),
);
?>

<div class="wrap">

    <h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab_id => $tab ): ?>
            <a href="?page=<?php echo $_GET['page'] ?? ''; ?>&tab=<?php echo $tab_id; ?>"
               class="nav-tab <?php echo $active_tab == $tab_id ? 'nav-tab-active' : ''; ?>">
				<?php echo $tab['title']; ?>
            </a>
		<?php endforeach; ?>
    </h2>

    <div class="tab-content">
		<?php
		// Показываем только активный таб
		if ( isset( $tabs[ $active_tab ] ) ) {
			include plugin_dir_path( __FILE__ ) . $tabs[ $active_tab ]['file'];
		}
		?>
    </div>

</div>

<?php
// Подключаем модальное окно
include plugin_dir_path( __FILE__ ) . '/components/modals/add-subject-modal.php';
?>
