// НейроГовно
export const Tasks = {
    init: function () {
        const $ = jQuery;

        if (typeof fsTaskData === 'undefined') return;

        console.log('FS LMS: Сервис создания задач запущен для:', fsTaskData.subject_key);

        $('body').on('click', '.page-title-action', function (e) {
            const href = $(this).attr('href') || '';

            if (href.indexOf('post-new.php') !== -1 && href.indexOf('post_type=' + fsTaskData.post_type) !== -1) {
                e.preventDefault();

                $.get(fsTaskData.ajax_url, {
                    action: 'fs_get_task_types',
                    subject_key: fsTaskData.subject_key
                }, function (res) {
                    if (res.success && res.data.length > 0) {

                        let msg = "Введите НОМЕР задания (например: 1, 2, 3...):\n";
                        res.data.forEach(t => {
                            msg += `№${t.slug} — ${t.description}\n`;
                        });

                        const userInp = prompt(msg); // Вводим "2"

                        // Ищем в данных объект, у которого slug совпадает с вводом
                        const selected = res.data.find(t => t.slug == userInp || t.slug == `${fsTaskData.subject_key}_${userInp}`);

                        if (selected) {
                            const title = prompt(`Создаем Задание №${userInp}. Введите заголовок:`);

                            if (title) {
                                $.post(fsTaskData.ajax_url, {
                                    action: 'fs_create_task_action',
                                    nonce: fsTaskData.nonce,
                                    subject_key: fsTaskData.subject_key,
                                    term_id: selected.id, // Скрипт сам подставит 124
                                    title: title
                                }, function (final) {
                                    if (final.success) window.location.href = final.data.redirect;
                                    else alert('Ошибка: ' + final.data);
                                });
                            }
                        } else {
                            alert('Задание с таким номером не найдено в таксономии!');
                        }
                    }}
                );
            }
        });
    }
};