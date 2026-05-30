import { initTabs }       from './components/task-tabs.js';
import { initCarousel }   from './components/article-carousel.js';
import { initApplyForm }  from './services/apply-form.js';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initCarousel();
    initApplyForm();
});