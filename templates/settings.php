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


<script>
    jQuery(document).ready(function ($) {
        // 2. Управление модальным окном
        var $modal = $('#fs-subject-modal');

        $('#open-subject-modal').on('click', function () {
            $modal.fadeIn(200);
        });

        $('.fs-close').on('click', function () {
            $modal.fadeOut(200);
        });

        $(window).on('click', function (event) {
            if ($(event.target).is($modal)) {
                $modal.fadeOut(200);
            }
        });

        // 3. AJAX сохранение
        $('#fs-add-subject-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('.button-primary');

            $btn.attr('disabled', true).text('Сохранение...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $form.serialize() + '&action=fs_store_subject',
                success: function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                        $btn.attr('disabled', false).text('Создать предмет и CPT');
                    }
                },
                error: function () {
                    alert('Сбой сервера. Проверьте консоль браузера.');
                    $btn.attr('disabled', false).text('Создать предмет и CPT');
                }
            });
        });

        // 4. Функционал быстрого редактирования Quick edit
        var $quickEditRow = $('#fs-quick-edit-row');
        var $currentRow = null; // Сохраняем текущую редактируемую строку

        // Делегируем событие на клик по кнопкам .open-quick-edit (даже для динамически добавленных элементов)
        $(document).on('click', '.open-quick-edit', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var key = $btn.data('key');
            var name = $btn.data('name');
            var count = $btn.data('count');

            // Находим родительскую строку таблицы
            var $row = $btn.closest('tr');
            $row.addClass('fs-editing-row');

            // Сохраняем текущую строку
            $currentRow = $row;

            // Клонируем строку quick edit и вставляем после редактируемой строки
            var $newEditRow = $quickEditRow.clone();
            $newEditRow.attr('id', 'fs-quick-edit-row-' + key);
            $newEditRow.show();

            // Заполняем форму данными
            $newEditRow.find('input[name="name"]').val(name);
            $newEditRow.find('input[name="tasks_count"]').val(count);
            $newEditRow.find('input[name="key"]').val(key);

            // Вставляем после текущей строки
            $row.after($newEditRow);
            $row.hide();

            // Обработчик для кнопки Отмена
            $newEditRow.find('.cancel').off('click').on('click', function () {
                $newEditRow.remove();
                $row.show();
                $row.removeClass('fs-editing-row');
                $currentRow = null;
            });

            // Обработчик для формы
            $newEditRow.find('#fs-quick-edit-form').off('submit').on('submit', function (e) {
                e.preventDefault();

                var $form = $(this);
                var $btn = $form.find('.save');
                var $spinner = $form.find('.spinner');

                $btn.attr('disabled', true);
                $spinner.addClass('is-active');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: $form.serialize() + '&action=fs_update_subject',
                    success: function (response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Ошибка: ' + (response.data || 'Не удалось обновить'));
                            $btn.attr('disabled', false);
                            $spinner.removeClass('is-active');
                        }
                    },
                    error: function () {
                        alert('Сбой сервера');
                        $btn.attr('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });
        });

        // Удаление предмета - используем делегирование событий
        $(document).on('click', '.delete-subject', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var key = $btn.data('key');
            var $row = $btn.closest('tr');
            var name = $row.find('strong a').text().trim();

            if (!confirm('Вы уверены, что хотите удалить предмет "' + name + '"? Это также отключит связанные типы записей.')) {
                return;
            }

            $btn.text('Удаление...').css('pointer-events', 'none');

            // Получаем nonce из формы быстрого редактирования
            var security = $('#fs-quick-edit-form [name="security"]').val();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fs_delete_subject',
                    key: key,
                    security: security
                },
                success: function (response) {
                    if (response.success) {
                        $row.fadeOut(400, function () {
                            $(this).remove();

                            // Проверяем, остались ли строки в таблице
                            var $tbody = $('#subjects-table tbody');
                            var $rows = $tbody.find('tr:visible');

                            # Чет плохо работает, пофиксить
                            if ($rows.length === 0) {
                                // Если строк не осталось, показываем информационное сообщение
                                location.reload();
                            }
                        });
                    } else {
                        alert(response.data || 'Ошибка удаления');
                        $btn.text('Удалить').css('pointer-events', 'auto');
                    }
                },
                error: function () {
                    alert('Сбой сервера при удалении');
                    $btn.text('Удалить').css('pointer-events', 'auto');
                }
            });
        });
    });
</script>