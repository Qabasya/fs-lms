import { esc } from './utils.js';

/** Авторское решение приходит только из teacher-only AJAX и очищено на сервере. */
export function solutionBlock(solution) {
    const html = typeof solution?.html === 'string' ? solution.html : '';
    const code = typeof solution?.code === 'string' ? solution.code : '';
    if (!html.trim() && !code.trim()) { return ''; }

    return `<details class="wr-solution">
        <summary class="prof-btn prof-btn-sm wr-solution-toggle">Показать эталонное решение</summary>
        <div class="wr-solution-body">
            ${html.trim() ? `<div class="wr-solution-text">${html}</div>` : ''}
            ${code.trim() ? `<div class="wr-solution-code">
                <span class="sta-label">Листинг кода:</span>
                <pre class="sum-task-code-pre"><code>${esc(code)}</code></pre>
            </div>` : ''}
        </div>
    </details>`;
}
