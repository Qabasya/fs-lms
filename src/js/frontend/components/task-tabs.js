// Блок вкладок — на странице задания один, а в разборе экзамена (результат, отчёт школе) их по одному на задание.
export function initTabs() {
    document.querySelectorAll('.fs-task-tabs').forEach(initTabsBlock);
}

function initTabsBlock(container) {
    const buttons = container.querySelectorAll('.fs-tab-btn');
    const panels  = container.querySelectorAll('.fs-tab-panel');

    // Клик по активному табу сворачивает панель — состояние «ни один не выбран»
    // допустимо, поэтому aria-selected снимается со всех кнопок.
    const setActive = btn => {
        buttons.forEach(b => {
            const on = b === btn;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(p => p.classList.remove('is-active'));

        if (btn) {
            container.querySelector(`[data-panel="${btn.dataset.tab}"]`)?.classList.add('is-active');
        }
    };

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            setActive(btn.classList.contains('is-active') ? null : btn);
        });
    });
}