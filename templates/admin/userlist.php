<?php



// Определение активной вкладки, по умолчанию tab-1
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'tab-1';

// Массив вкладок с заголовками и путями к файлам
$tabs = array(
	'tab-1' => array(
		'title' => 'Заявки',
		'file'  => '/components/tabs/userlist-tabs/userlist-1-applications.php',
	),
	'tab-2' => array(
		'title' => 'Ученики',
		'file'  => '/components/tabs/userlist-tabs/userlist-2-students.php',
	),
	'tab-3' => array(
		'title' => 'Родители',
		'file'  => '/components/tabs/userlist-tabs/userlist-3-parents.php',
	),
	'tab-4' => array(
		'title' => 'Преподаватели',
		'file'  => '/components/tabs/userlist-tabs/userlist-4-teachers.php',
	),
	'tab-5' => array(
		'title' => 'Архив',
		'file'  => '/components/tabs/userlist-tabs/userlist-5-archive.php',
	),
);
?>



	<div class="wrap">
        <div class="fs-page-header">
            <div class="fs-page-header__content">
                <h1 class="fs-page-header__title">Работа с пользователями</h1>

            </div>

            <p class="fs-page-header__desc">
                Здесь обрабатываются заявки и происходит зачисление и отчисление учеников
            </p>

        </div>
        <h2 class="nav-tab-wrapper">
            <?php foreach ( $tabs as $tab_id => $tab ) : ?>
                <a href="?page=<?php echo esc_attr( $_GET['page'] ?? '' ); ?>&tab=<?php echo esc_attr( $tab_id ); ?>"
                   class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $tab['title'] ); ?>
                </a>
            <?php endforeach; ?>
        </h2>

		<!-- Содержимое активной вкладки -->
		<div class="tab-content">
			<?php
			// Подключение файла активной вкладки
			if ( isset( $tabs[ $active_tab ] ) ) {
				// rtrim нужен, чтобы не было двойного слэша // между путем и файлом
				$file_path = rtrim( plugin_dir_path( __FILE__ ), '/' ) . $tabs[ $active_tab ]['file'];

				if ( file_exists( $file_path ) ) {
					include $file_path;
				} else {
					echo "<div class='notice notice-error'><p>Файл не найден: <code>{$file_path}</code></p></div>";
				}
			}
			?>
		</div>
	</div>


<?php
// Подключение модального окна (путь относительно папки templates/)

?>