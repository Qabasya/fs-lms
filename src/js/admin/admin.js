import { UI }           from './modules/ui.js';
import { Subjects }     from './services/subjects.js';
import { Tasks }        from './services/tasks.js';
import { Boilerplates }    from './services/boilerplates.js';
import { BoilerplateList } from './services/boilerplate-list.js';
import { TaskCreation } from './services/task-creation.js';
import { Taxonomies }   from './services/taxonomies.js';
import { PostsTable }   from './services/posts-table.js';

(function ($) {
    'use strict';

    $(document).ready(function () {
        // Инициализируем все компоненты из папки components/ (модальные окна)
        UI.init();

        // Предметы — только там, где есть форма или таблица
        if ($('#fs-add-subject-form').length || $('.open-quick-edit').length) {
            Subjects.init();
        }

        Tasks.init();
        PostsTable.init();
        Boilerplates.init();
        BoilerplateList.init();

        // Создание заданий — init безопасен: внутри проверяет наличие модалки
        TaskCreation.init();

        // Таксономии — только на странице управления предметом
        if ($('.js-taxonomy-table').length) {
            Taxonomies.init();
        }
    });

})(jQuery);