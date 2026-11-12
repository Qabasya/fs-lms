/** Одноразовое уведомление после перехода с гостевой формы. */
export function showExamSignupNotice(location, history, notify) {
    const params = new URLSearchParams(location.search);
    if (params.get('exam_signup_notice') !== 'cabinet') { return; }
    notify('Вы уже учитесь у нас: запись на экзамен доступна в личном кабинете.');
    params.delete('exam_signup_notice');
    const query = params.toString();
    history.replaceState(history.state, '', location.pathname + (query ? `?${query}` : '') + location.hash);
}
