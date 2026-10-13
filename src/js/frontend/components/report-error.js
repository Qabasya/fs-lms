/**
 * Кнопка «Нашли ошибку?» на страницах заданий и статей: модалка с полем
 * для описания и отправка в журнал «Найденные ошибки».
 *
 * Разметку печатают партиалы partials/report-error-button.php и
 * partials/report-error-modal.php. Глобальная переменная: fs_lms_report_vars.
 */

/** @type {{ ajax_url: string, post_id: number, action: string, nonce: string }|undefined} */
const vars = window.fs_lms_report_vars;

/**
 * @returns {void}
 */
export function initReportError() {
    const modal = document.querySelector('.js-report-modal');
    if (!modal || !vars) return;

    const form   = modal.querySelector('.js-report-form');
    const field  = modal.querySelector('.js-report-text');
    const status = modal.querySelector('.js-report-status');
    const send   = modal.querySelector('.js-report-send');
    let taskId   = 0;

    const setStatus = (text, isError) => {
        status.textContent = text;
        status.hidden = text === '';
        status.classList.toggle('is-error', isError);
    };

    const close = () => {
        modal.hidden = true;
        document.removeEventListener('keydown', onKey);
    };

    const onKey = (e) => {
        if (e.key === 'Escape') close();
    };

    const open = (id) => {
        taskId = id;
        field.value = '';
        send.disabled = false;
        setStatus('', false);
        modal.hidden = false;
        document.addEventListener('keydown', onKey);
        field.focus();
    };

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('.js-report-open');
        if (trigger) {
            open(Number(trigger.dataset.taskId) || 0);
            return;
        }
        if (e.target.closest('.js-report-close')) close();
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const message = field.value.trim();
        if (message === '') {
            setStatus('Опишите найденную ошибку.', true);
            return;
        }

        send.disabled = true;
        setStatus('', false);

        try {
            const body = new URLSearchParams({
                action:  vars.action,
                security: vars.nonce,
                post_id: String(vars.post_id),
                task_id: String(taskId),
                message,
            });
            const res  = await fetch(vars.ajax_url, { method: 'POST', body });
            const json = await res.json();

            if (json?.success) {
                setStatus('Спасибо! Сообщение отправлено.', false);
                field.value = '';
                setTimeout(close, 1500);
                return;
            }

            const err = typeof json?.data === 'string' ? json.data : json?.data?.message;
            setStatus(err || 'Не удалось отправить. Попробуйте позже.', true);
        } catch {
            setStatus('Не удалось отправить. Проверьте соединение.', true);
        }

        send.disabled = false;
    });
}
