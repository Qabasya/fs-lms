import {UI} from './modules/ui.js';
import {TemplateManager} from './services/template-manager.js';
import { ContentClone } from './services/content-clone.js';
import {Boilerplates} from './services/boilerplates.js';
import {PostsTable} from './services/tables/posts-table.js';
import {RequiredTaxGuard} from './services/required-tax-guard.js';
import {ArticleDescription} from './services/article-description.js';
import {TaskFilter} from "./services/task-dashboard.js";
import {RecentContent} from "./services/recent-posts";
import { GroupsTable } from "./services/tables/groups-table.js";
import { ApplicationsTable } from './services/tables/applications-table.js';
import { StudentsTable } from './services/tables/students-table.js';
import { ParentsTable } from './services/tables/parents-table.js';
import { LogsTable } from './services/tables/logs-table.js';
import { EmailTemplateSettings } from './services/settings/email-template-settings.js';
import { ConsentSettings } from './services/settings/consent-settings.js';
import { ConfigSettings } from './services/settings/config-settings.js';
import { HardDeleteStudentService } from './services/hard-delete-student-service.js';
import { ArchiveTable } from './services/tables/archive-table.js';
import { ImportCsv } from './services/import-csv.js';
import { RefSelector } from './services/ref-selector.js';
import { TaskTemplateType } from './services/task-template-type.js';
import { showToast } from './modules/toast.js';
import { ProblemBankFields, ProblemBankFilters } from './services/problem-bank-fields.js';
import { ModuleToggle } from './services/module-toggle.js';
import { TaskFields } from './services/task-fields.js';
import { TaskEditor } from './services/task-editor.js';

import {TaxonomyModalManager} from './managers/taxonomy-modal-manager.js';
import {AcademicPeriodModalManager} from "./managers/enrollment/academic-period-modal-manager";
import {RoomModalManager} from "./managers/enrollment/room-modal-manager.js";
import {GroupModalManager} from "./managers/enrollment/group-modal-manager.js";
import {SubjectModalManager} from "./managers/subject-modal-manager";
import { SubjectTransferService } from "./services/subject-transfer-service";
import {TaskModalManager} from "./managers/task-modal-manager";
import {HelpModalManager} from "./managers/help-modal-manager";
import { ApplicationModalManager } from './managers/enrollment/applications/application-modal-manager.js';
import { ApplicationReviewModalManager } from './managers/enrollment/applications/application-review-modal-manager.js';
import { ApplicationEnrollmentModalManager } from './managers/enrollment/applications/application-enrollment-modal-manager.js';
import { TrialAccessModalManager } from './managers/enrollment/applications/trial-access-modal-manager.js';
import { StudentPersonModalManager } from './managers/enrollment/person/student-person-modal-manager.js';
import { ParentPersonModalManager } from './managers/enrollment/person/parent-person-modal-manager.js';
import { ExpelModalManager } from './managers/enrollment/expel-modal-manager.js';
import { ArchiveViewModalManager } from './managers/enrollment/archive-view-modal-manager.js';

import { ApplicationViewModal } from './modals/enrollment/applications/application-view-modal.js';
import { SelectParentModal } from './modals/enrollment/select-parent-modal.js';
import { TeacherViewModal } from './modals/enrollment/teacher-view-modal.js';
import { AlertModal } from './modals/alert-modal.js';
import { RolesSettings } from './services/roles-settings.js';
import { LegacyTaskImport } from './services/legacy-task-import.js';
import { PrintCenter } from './services/print-center.js';

/**
 * Инициализирует конструктор из отдельного чанка.
 *
 * @param {Promise<Object>} chunk      Результат import() модуля конструктора
 * @param {string}          exportName Имя экспортируемого объекта с init()
 */
function loadBuilder( chunk, exportName ) {
    chunk
        .then( ( module ) => module[ exportName ].init() )
        .catch( () => showToast( 'Не удалось загрузить конструктор. Обновите страницу.', 'error' ) );
}

(function ($) {
    'use strict';

    $(document).ready(function () {
        setTimeout(() => {
            // Через 5с гасим только флеш-сообщения о результате действия («Запись обновлена»,
            // «Настройки сохранены»). Не трогаем:
            // - структурные плашки-пустышки (.fs-table__no-items, #3) и .inline-блоки в модалках —
            //   они постоянные, а скрытые ещё покажет свой JS (предупреждение экспорта пакета);
            // - предупреждения и info чужих плагинов — их закрывают крестиком;
            // - скрытые уведомления: fadeTo() сначала делает .show(), и невидимый блок
            //   (напр. #notice-corrupt-rest-api.hidden) на миг выталкивал таблицу вниз.
            $('#message, .notice.updated, .notice-success')
                .not('.notice-error, .error, .fs-table__no-items, .inline')
                .filter(':visible')
                .each(function () {
                    const $n = $(this);
                    $n.fadeTo(100, 0, () => $n.slideUp(100, () => $n.remove()));
                });
        }, 5000);

        UI.init();

        ContentClone.init(); // «Дублировать» в таблицах банков
        AlertModal.init();

        if ($('#fs-add-subject-form').length || $('.open-quick-edit').length) {
            SubjectModalManager.init();
        }

        if ($('.js-export-subject').length || $('#fs-import-trigger').length) {
            SubjectTransferService.init();
        }

        if ($('.js-add-period').length || $('.js-edit-period').length) {
            AcademicPeriodModalManager.init();
        }

        if ($('#fs-room-modal').length) {
            RoomModalManager.init();
        }

        GroupModalManager.init();
        GroupsTable.init();

        TemplateManager.init();
        PostsTable.init();
        Boilerplates.init();

        TaskModalManager.init();

        if ($('.js-taxonomy-table').length) {
            TaxonomyModalManager.init();
        }

        RequiredTaxGuard.init();

        if ($('.js-article-description').length) {
            ArticleDescription.init();
        }

        if ( $( '.fs-lms-ref-field' ).length ) {
            RefSelector.init();
        }

        if ($('#fs-task-number-filter').length) {
            TaskFilter.init();
        }

        if ($('#fs-recent-tasks-container, #fs-recent-articles-container').length) {
            RecentContent.init();
        }

        if ($('.js-open-help-modal').length) {
            HelpModalManager.init();
        }

        if ( document.querySelector( '.fs-lms-applications' ) ) {
            ApplicationsTable.init();
            ApplicationModalManager.init();
            ApplicationReviewModalManager.init();
            ApplicationEnrollmentModalManager.init();
            TrialAccessModalManager.init();
            ApplicationViewModal.init();
            SelectParentModal.init();
        }

        if ( $( '.fs-lms-archive' ).length ) {
            ArchiveViewModalManager.init();
            ArchiveTable.init();
        }

        if ( $( '.fs-lms-students' ).length ) { StudentsTable.init(); }

        if ( document.querySelector( '.fs-lms-students' ) ) {
            StudentPersonModalManager.init();
        }
        if ( document.querySelector( '.fs-lms-parents' ) ) {
            ParentsTable.init();
            ParentPersonModalManager.init();
        }

        if ( document.querySelector( '.fs-lms-teachers' ) ) {
            TeacherViewModal.init();
        }

        ExpelModalManager.init();

        LogsTable.init();
        EmailTemplateSettings.init();
        ConsentSettings.init();
        ConfigSettings.init();

        HardDeleteStudentService.init();

        if ( document.querySelector( '.fs-lms-import' ) ) {
            ImportCsv.init();
        }

        // Конструкторы (урок, работа, контрольная, курс) — отдельными чанками: вместе с редактором
        // шагов это самая тяжёлая часть бандла, а нужна она только на экранах редактирования.
        // Общие модули (ConfirmModal, тосты, пикер) остаются в admin.min.js и не дублируются.
        if ( $( '.fs-lms-step-builder' ).length ) {
            loadBuilder( import( /* webpackChunkName: "admin-lesson-steps" */ './services/lesson-step-editor.js' ), 'LessonStepEditor' );
        }

        if ( $( '.fs-lms-work-builder' ).length ) {
            loadBuilder( import( /* webpackChunkName: "admin-work-builder" */ './services/work-builder.js' ), 'WorkBuilder' );
        }

        if ( $( '.fs-lms-assessment-builder' ).length ) {
            loadBuilder( import( /* webpackChunkName: "admin-assessment-builder" */ './services/assessment-builder.js' ), 'AssessmentBuilder' );
        }

        if ( document.getElementById( 'fs-lms-course-builder' ) ) {
            loadBuilder( import( /* webpackChunkName: "admin-course-builder" */ './services/course-builder.js' ), 'CourseBuilder' );
        }

        TaskTemplateType.init();
        ProblemBankFields.init();
        ProblemBankFilters.init();
        TaskFields.init();
        TaskEditor.init();

        if ( $( '.js-module-toggle' ).length ) {
            ModuleToggle.init();
        }

        RolesSettings.init();

        if ( $( '#fs-legacy-import-start' ).length ) {
            LegacyTaskImport.init();
        }

        if ( $( '#fs-print-center-form' ).length ) {
            PrintCenter.init();
        }

    });

})(jQuery);