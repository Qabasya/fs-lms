/* Заглушка контекстного меню кабинета (#profCtxMenu): общий openCtxMenu() рисует пункты в разметку и
   вешает клики. Тесты экранов с пикерами выбирают пункт через pick(), не имея настоящего DOM. */

/**
 * Ставит заглушку в globalThis.document (и размеры окна в globalThis.window — на них опирается позиционирование).
 *
 * @returns {{ values: () => string[], labels: () => string[], active: () => string[], isOpen: () => boolean, pick: (value: string) => void }}
 */
export function installCtxMenu() {
    const shown = { html: '', handlers: new Map(), open: false };
    const menu = {
        classList: { toggle() {}, add() { shown.open = true; }, remove() { shown.open = false; } },
        style: {},
        set innerHTML(html) { shown.html = html; shown.handlers.clear(); },
        get innerHTML() { return shown.html; },
        querySelectorAll: () => [...shown.html.matchAll(/data-v="([^"]*)"/g)].map((m) => ({
            dataset: { v: m[1] },
            addEventListener: (type, callback) => shown.handlers.set(m[1], callback),
        })),
        getBoundingClientRect: () => ({ width: 200, height: 100, left: 0, right: 200, top: 0, bottom: 100 }),
    };
    const backdrop = { classList: { add() {}, remove() {} } };

    globalThis.window = Object.assign(globalThis.window || {}, { innerWidth: 1280, innerHeight: 800 });
    globalThis.document = { getElementById: (id) => ({ profCtxMenu: menu, profCtxBackdrop: backdrop })[id] ?? null };

    return {
        values: () => [...shown.html.matchAll(/data-v="([^"]*)"/g)].map((m) => m[1]),
        labels: () => [...shown.html.matchAll(/class="ctx-lbl">([^<]*)</g)].map((m) => m[1]),
        active: () => [...shown.html.matchAll(/class="ctx-item on" data-v="([^"]*)"/g)].map((m) => m[1]),
        isOpen: () => shown.open,
        pick: (value) => shown.handlers.get(value)?.(),
    };
}
