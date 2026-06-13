import {UI} from './modules/ui.js';
import {TemplateManager} from './services/template-manager.js';
import {Boilerplates} from './services/boilerplates.js';
import {PostsTable} from './services/tables/posts-table.js';
import {RequiredTaxGuard} from './services/required-tax-guard.js';
import {TaskFilter} from "./services/task-dashboard.js";
import {RecentContent} from "./services/recent-posts";
import {AuthSettings} from "./services/settings/auth-settings";
import { GroupsTable } from "./services/tables/groups-table.js";
import { ApplicationsTable } from './services/tables/applications-table.js';
import { StudentsTable } from './services/tables/students-table.js';
import { ParentsTable } from './services/tables/parents-table.js';
import { LogsTable } from './services/tables/logs-table.js';
import { EmailTemplateSettings } from './services/settings/email-template-settings.js';
import { ConsentSettings } from './services/settings/consent-settings.js';
import { HardDeleteStudentService } from './services/hard-delete-student-service.js';
import { ArchiveTable } from './services/tables/archive-table.js';

import {TaxonomyModalManager} from './managers/taxonomy-modal-manager.js';
import {AcademicPeriodModalManager} from "./managers/enrollment/academic-period-modal-manager";
import {GroupModalManager} from "./managers/enrollment/group-modal-manager.js";
import {SubjectModalManager} from "./managers/subject-modal-manager";
import {TaskModalManager} from "./managers/task-modal-manager";
import {HelpModalManager} from "./managers/help-modal-manager";
import { ApplicationModalManager } from './managers/enrollment/applications/application-modal-manager.js';
import { ApplicationReviewModalManager } from './managers/enrollment/applications/application-review-modal-manager.js';
import { ApplicationEnrollmentModalManager } from './managers/enrollment/applications/application-enrollment-modal-manager.js';
import { StudentPersonModalManager } from './managers/enrollment/person/student-person-modal-manager.js';
import { ParentPersonModalManager } from './managers/enrollment/person/parent-person-modal-manager.js';
import { ExpelModalManager } from './managers/enrollment/expel-modal-manager.js';
import { ArchiveViewModalManager } from './managers/enrollment/archive-view-modal-manager.js';

import { ApplicationViewModal } from './modals/enrollment/applications/application-view-modal.js';
import { SelectParentModal } from './modals/enrollment/select-parent-modal.js';
import { TeacherViewModal } from './modals/enrollment/teacher-view-modal.js';
import { AlertModal } from './modals/alert-modal.js';

(function ($) {
    'use strict';

    $(document).ready(function () {
        UI.init();
        AlertModal.init();

        if ($('#fs-add-subject-form').length || $('.open-quick-edit').length) {
            SubjectModalManager.init();
        }

        if ($('.js-add-period').length || $('.js-edit-period').length) {
            AcademicPeriodModalManager.init();
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

        if ($('#fs-task-number-filter').length) {
            TaskFilter.init();
        }

        if ($('#fs-recent-tasks-container, #fs-recent-articles-container').length) {
            RecentContent.init();
        }

        if ($('.js-provider-toggle').length) {
            AuthSettings.init();
        }

        if ($('.js-open-help-modal').length) {
            HelpModalManager.init();
        }

        if ( document.querySelector( '.fs-lms-applications' ) ) {
            ApplicationsTable.init();
            ApplicationModalManager.init();
            ApplicationReviewModalManager.init();
            ApplicationEnrollmentModalManager.init();
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

        HardDeleteStudentService.init();

    });

})(jQuery);