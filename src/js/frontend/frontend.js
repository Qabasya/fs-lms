import { initTabs }             from './components/task-tabs.js';
import { initCarousel }         from './components/article-carousel.js';
import { initCodeBlocks }       from './components/code-block.js';
import { initLessonCountdown }  from './components/lesson-countdown.js';
import { initApplyForm }        from './services/apply-form.js';
import { initJoinForm }         from './services/join-form.js';
import { initAssessment }       from './services/assessment.js';
import { AllTasksPage }         from './services/all-tasks-page.js';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initCarousel();
    initCodeBlocks();
    initLessonCountdown();
    initApplyForm();
    initJoinForm();
    initAssessment();
    new AllTasksPage().init();
});