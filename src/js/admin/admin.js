import {UI} from './modules/ui.js';
import {TemplateManager} from './services/template-manager.js';
import {Boilerplates} from './services/boilerplates.js';
import {TaxonomyModalManager} from './services/taxonomy-modal-manager.js';
import {PostsTable} from './services/posts-table.js';
import {RequiredTaxGuard} from './services/required-tax-guard.js';
import {TaskFilter} from "./services/task-dashboard.js";
import {RecentContent} from "./services/recent-posts";
import {AuthSettings} from "./services/auth-settings";
import {AcademicPeriodModalManager} from "./services/academic-period-modal-manager";
import {GroupModalManager} from "./services/group-modal-manager.js";
import {SubjectModalManager} from "./services/subject-modal-manager";
import {TaskModalManager} from "./services/task-modal-manager";
import {HelpModalManager} from "./services/help-modal-manager";
import { GroupsTable } from "./services/groups-table.js";
import { ApplicationsTable } from './services/applications-table.js';
import { ApplicationModalManager } from './services/application-modal-manager.js';
import { ApplicationReviewModalManager } from './services/application-review-modal-manager.js';
import { ApplicationEnrollmentModalManager } from './services/application-enrollment-modal-manager.js';
import { StudentViewModal } from './components/student-view-modal.js';
import { ParentViewModal } from './components/parent-view-modal.js';
import { TeacherViewModal } from './components/teacher-view-modal.js';

(function ($) {
    'use strict';

    $(document).ready(function () {
        UI.init();

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
        }

        if ( document.querySelector( '.fs-lms-students' ) ) {
            StudentViewModal.init();
        }

        if ( document.querySelector( '.fs-lms-parents' ) ) {
            ParentViewModal.init();
        }

        if ( document.querySelector( '.fs-lms-teachers' ) ) {
            TeacherViewModal.init();
        }

    });

})(jQuery);
