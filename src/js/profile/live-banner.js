/**
 * Баннер «Занятие уже идёт» по центру шапки кабинета ученика.
 *
 * Данные — `window.fsProfile.live` (LiveLessonService::windowsForStudent): окна занятий
 * ученика на ближайшие сутки с готовыми адресами урока и трансляции. Время сверяем
 * с серверным (`live.now`), а не с часами устройства, и перепроверяем раз в 15 секунд —
 * баннер появляется и пропадает сам, без перезагрузки страницы.
 *
 * Кнопка «Присоединиться» открывает урок в этой вкладке, а трансляцию группы (если ссылка
 * задана) — в новой. Окно в новой вкладке открывается прямо из клика, иначе его заблокирует браузер.
 */
const TICK_MS = 15000;

export function initLiveBanner() {
    const live = window.fsProfile?.live;
    const box  = document.getElementById('profLive');
    const btn  = document.getElementById('profLiveJoin');
    if (!live || !box || !btn || !Array.isArray(live.lessons) || !live.lessons.length) { return; }

    const skew = live.now - Math.floor(Date.now() / 1000);
    let current = null;

    const pick = () => {
        const t = Math.floor(Date.now() / 1000) + skew;
        return live.lessons.find((l) => l.start <= t && t < l.end) || null;
    };

    const render = () => {
        current = pick();
        box.hidden = !current;
        if (current) {
            box.title = [current.group_name, current.topic].filter(Boolean).join(' · ');
        }
    };

    btn.addEventListener('click', () => {
        if (!current) { return; }
        if (current.stream_url) { window.open(current.stream_url, '_blank', 'noopener'); }
        if (current.player_url) { window.location.href = current.player_url; }
    });

    render();
    window.setInterval(render, TICK_MS);
}
