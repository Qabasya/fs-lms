import {UI} from './modules/ui.js';
import {TemplateManager} from './services/template-manager.js';
import {Boilerplates} from './services/boilerplates.js';
import {PostsTable} from './services/posts-table.js';
import {RequiredTaxGuard} from './services/required-tax-guard.js';
import {TaskFilter} from "./services/task-dashboard.js";
import {RecentContent} from "./services/recent-posts";
import {AuthSettings} from "./services/auth-settings";
import { GroupsTable } from "./services/groups-table.js";
import { ApplicationsTable } from './services/applications-table.js';
import { StudentsTable } from './services/students-table.js';
import { EmailTemplateSettings } from './services/email-template-settings.js';
import { ConsentSettings } from './services/consent-settings.js';

import {TaxonomyModalManager} from './managers/taxonomy-modal-manager.js';
import {AcademicPeriodModalManager} from "./managers/academic-period-modal-manager";
import {GroupModalManager} from "./managers/group-modal-manager.js";
import {SubjectModalManager} from "./managers/subject-modal-manager";
import {TaskModalManager} from "./managers/task-modal-manager";
import {HelpModalManager} from "./managers/help-modal-manager";
import { ApplicationModalManager } from './managers/application-modal-manager.js';
import { ApplicationReviewModalManager } from './managers/application-review-modal-manager.js';
import { ApplicationEnrollmentModalManager } from './managers/application-enrollment-modal-manager.js';
import { StudentPersonModalManager } from './managers/student-person-modal-manager.js';
import { ParentPersonModalManager } from './managers/parent-person-modal-manager.js';
import { ExpelModalManager } from './managers/expel-modal-manager.js';

import { ApplicationViewModal } from './modals/application-view-modal.js';
import { TeacherViewModal } from './modals/teacher-view-modal.js';
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
        }

        if ( $( '.fs-lms-students' ).length ) { StudentsTable.init(); }

        if ( document.querySelector( '.fs-lms-students' ) ) {
            StudentPersonModalManager.init();
        }
        if ( document.querySelector( '.fs-lms-parents' ) ) {
            ParentPersonModalManager.init();
        }

        if ( document.querySelector( '.fs-lms-teachers' ) ) {
            TeacherViewModal.init();
        }

        ExpelModalManager.init();

        EmailTemplateSettings.init();
        ConsentSettings.init();

    });

})(jQuery);