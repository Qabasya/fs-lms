/* ══════════════════════════════════════════════════════════════════════
   Память экранов кабинета: состояние (группа, месяц, вкладка…) живёт в адресе.
   «Назад» из плеера урока (или перезагрузка) открывает кабинет с тем же состоянием,
   в котором его оставили: выбранная группа, месяц, вкладка.

   Чтобы экран запоминал состояние, достаточно:
     1. дописать его в NAV_SCREENS — перечень полей (короткие ключи);
     2. при инициализации читать `readNav(screen)`;
     3. при смене состояния звать `saveNav(screen, { ключ: значение })`.
   Параметры в адресе называются `<экран>_<ключ>` (`ktp_g`, `journal_m`) и не пересекаются
   с прочими параметрами кабинета. Чужие экраны и пустые значения адрес очищают сами.
   ══════════════════════════════════════════════════════════════════════ */

/** Экран → поля состояния, которые он помнит. Новый экран добавляется строкой здесь. */
export const NAV_SCREENS = {
    ktp:     ['g', 'm'],
    journal: ['g', 'm'],
    ranking: ['s', 'g'],
    summary: ['g', 'p', 'tab'],
    works:   ['g', 't', 's'],
};

/** Адрес страницы; вне браузера (тесты, SSR) его нет — состояние тогда просто не помнится. */
const currentUrl = () => (typeof window !== 'undefined' && window.location?.href ? new URL(window.location.href) : null);

const paramName = (screen, key) => `${screen}_${key}`;

/** Все параметры адреса, которые принадлежат экранам, кроме `exceptScreen`. */
export function navParamsExcept(exceptScreen) {
    return Object.entries(NAV_SCREENS)
        .filter(([screen]) => screen !== exceptScreen)
        .flatMap(([screen, keys]) => keys.map(key => paramName(screen, key)));
}

/** Сохранённое состояние экрана из адреса: `{ g: '4', m: '2026-10' }` (только заданные поля). */
export function readNav(screen) {
    const params = currentUrl()?.searchParams ?? new URLSearchParams();
    const out = {};
    (NAV_SCREENS[screen] || []).forEach(key => {
        const value = params.get(paramName(screen, key));
        if (null !== value && '' !== value) { out[key] = value; }
    });
    return out;
}

/**
 * Записывает состояние экрана в адрес (без новой записи в истории). Поля, которых нет в `values`
 * или пустые, убираются. Если сейчас открыт другой экран — ничего не пишет: фоновая перерисовка
 * не должна менять адрес чужого экрана.
 */
export function saveNav(screen, values) {
    const url = currentUrl();
    if (!url || url.searchParams.get('screen') !== screen) { return; }
    (NAV_SCREENS[screen] || []).forEach(key => {
        const value = values[key];
        const name = paramName(screen, key);
        if (undefined === value || null === value || '' === value) { url.searchParams.delete(name); }
        else { url.searchParams.set(name, String(value)); }
    });
    window.history.replaceState(window.history.state, '', url);
}
