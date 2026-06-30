import { initProfile } from './app.js';

document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('.prof-app')) return;
    initProfile();
});
