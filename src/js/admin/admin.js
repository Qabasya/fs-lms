import {UI} from './modules/ui.js';
import {Subjects} from './services/subjects.js';
import {TemplateManager} from './services/template-manager.js';
import {Boilerplates} from './services/boilerplates.js';
import {TaskCreation} from './services/task-creation.js';
import {Taxonomies} from './services/taxonomies.js';
import {PostsTable} from './services/posts-table.js';
import {RequiredTaxGuard} from './services/required-tax-guard.js';
import {TaskFilter} from "./services/task-dashboard.js";
import {RecentContent} from "./services/recent-posts";

(function ($) {
    'use strict';

    $(document).ready(function () {
        // Инициализируем все компоненты из папки components/ (модальные окна)
        UI.init();

        // Предметы — только там, где есть форма или таблица
        if ($('#fs-add-subject-form').length || $('.open-quick-edit').length) {
            Subjects.init();
        }

        TemplateManager.init();
        PostsTable.init();
        Boilerplates.init();

        // Создание заданий — init безопасен: внутри проверяет наличие модалки
        TaskCreation.init();

        // Таксономии — только на странице управления предметом
        if ($('.js-taxonomy-table').length) {
            Taxonomies.init();
        }

        RequiredTaxGuard.init();

        if ($('#fs-task-number-filter').length) {
            TaskFilter.init();
        }

        if ($('#fs-recent-tasks-container, #fs-recent-articles-container').length) {
            RecentContent.init();
        }

    });

})(jQuery);