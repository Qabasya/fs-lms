export function initTabs() {
    const container = document.querySelector('.fs-task-tabs');
    if (!container) return;

    const buttons = container.querySelectorAll('.fs-tab-btn');
    const panels  = container.querySelectorAll('.fs-tab-panel');

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target   = btn.dataset.tab;
            const isActive = btn.classList.contains('is-active');

            buttons.forEach(b => b.classList.remove('is-active'));
            panels.forEach(p => p.classList.remove('is-active'));

            if (!isActive) {
                btn.classList.add('is-active');
                container.querySelector(`[data-panel="${target}"]`)?.classList.add('is-active');
            }
        });
    });
}