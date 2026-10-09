import { initTabs }             from './components/task-tabs.js';
import { initCarousel }         from './components/article-carousel.js';
import { initArticleToc }       from './components/article-toc.js';
import { initScrollSticky }     from './components/scroll-sticky.js';
import { initArticleTaskCards } from './components/article-task-card.js';
import { initArticleNav }       from './components/article-nav.js';
import { initArticleCatalog }   from './components/article-catalog.js';
import { initCodeBlocks }       from './components/code-block.js';
import { initLessonCountdown }  from './components/lesson-countdown.js';
import { initSearchBox }        from './components/search-box.js';
import { initScrollTop }        from './components/scroll-top.js';
import { initFiltersToggle }    from './components/filters-toggle.js';
import { initApplyForm }        from './services/apply-form.js';
import { initLoginForm }        from './services/login-form.js';
import { initJoinForm }         from './services/join-form.js';
import { initExamSignup }       from './services/exam-signup.js';
import { initExamGuest }        from './services/exam-guest.js';
import { initAssessment }       from './services/assessment.js';
import { AllTasksPage }         from './services/all-tasks-page.js';
import { bindAnswerToggle }     from './modules/answer-toggle.js';
import { installNonceRefresh } from '../common/nonce-refresh.js';

// До любых запросов бандла: устаревший nonce обновляется и запрос повторяется сам.
installNonceRefresh();

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initCarousel();
    initArticleToc();
    initScrollSticky();
    initArticleTaskCards();
    initArticleNav();
    initArticleCatalog();
    initCodeBlocks();
    initLessonCountdown();
    initSearchBox();
    initScrollTop();
    initFiltersToggle();
    initApplyForm();
    initLoginForm();
    initJoinForm();
    initExamSignup();
    initExamGuest();
    initAssessment();
    // Кнопка ответа есть и на странице одного задания; повторная привязка на
    // «Всех заданиях» безвредна — bindAnswerToggle идемпотентен.
    bindAnswerToggle(document);
    new AllTasksPage().init();
});
