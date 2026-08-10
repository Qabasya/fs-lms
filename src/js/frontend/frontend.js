import { initTabs }             from './components/task-tabs.js';
import { initCarousel }         from './components/article-carousel.js';
import { initArticleToc }       from './components/article-toc.js';
import { initArticleAside }     from './components/article-aside.js';
import { initArticleTaskCards } from './components/article-task-card.js';
import { initArticleNav }       from './components/article-nav.js';
import { initArticleCatalog }   from './components/article-catalog.js';
import { initCodeBlocks }       from './components/code-block.js';
import { initLessonCountdown }  from './components/lesson-countdown.js';
import { initSearchBox }        from './components/search-box.js';
import { initScrollTop }        from './components/scroll-top.js';
import { initApplyForm }        from './services/apply-form.js';
import { initJoinForm }         from './services/join-form.js';
import { initAssessment }       from './services/assessment.js';
import { AllTasksPage }         from './services/all-tasks-page.js';
import { bindAnswerToggle }     from './modules/answer-toggle.js';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initCarousel();
    initArticleToc();
    initArticleAside();
    initArticleTaskCards();
    initArticleNav();
    initArticleCatalog();
    initCodeBlocks();
    initLessonCountdown();
    initSearchBox();
    initScrollTop();
    initApplyForm();
    initJoinForm();
    initAssessment();
    // Кнопка ответа есть и на странице одного задания; повторная привязка на
    // «Всех заданиях» безвредна — bindAnswerToggle идемпотентен.
    bindAnswerToggle(document);
    new AllTasksPage().init();
});
