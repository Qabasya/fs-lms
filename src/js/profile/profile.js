import { initProfile } from './app.js';
import { installNonceRefresh } from '../common/nonce-refresh.js';

// До любых запросов бандла: устаревший nonce обновляется и запрос повторяется сам.
installNonceRefresh();

document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('.prof-app')) return;
    initProfile();
});
