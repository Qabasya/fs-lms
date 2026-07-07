import { initTabs }             from './components/task-tabs.js';
import { initCarousel }         from './components/article-carousel.js';
import { initLessonCountdown }  from './components/lesson-countdown.js';
import { initApplyForm }        from './services/apply-form.js';
import { initJoinForm }         from './services/join-form.js';
import { initGroupCockpit }     from './services/group-cockpit.js';
import { initSubmissions }      from './services/submission.js';
import { initAssessment }       from './services/assessment.js';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initCarousel();
    initLessonCountdown();
    initApplyForm();
    initJoinForm();
    initGroupCockpit();
    initSubmissions();
    initAssessment();
});