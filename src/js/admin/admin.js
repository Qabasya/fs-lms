import {UI} from './modules/ui.js';
import {Subjects} from './services/subjects.js';
import {Tasks} from './services/tasks.js';
import {Boilerplates} from './services/boilerplates.js';
import {TaskCreation} from './components/task-creation-modal';


(function ($) {
    'use strict';

    $(document).ready(function () {
        // Инициализируем общий интерфейс
        UI.init();

        // Инициализируем предметы только там, где есть форма или таблица
        if ($('#fs-add-subject-form').length || $('.open-quick-edit').length) {
            Subjects.init();
        }
        Tasks.init();

        Boilerplates.init();
        TaskCreation.init();
    });

})(jQuery);