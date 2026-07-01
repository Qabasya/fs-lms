/* ══════════════════════════════════════════════════════════════════════
   FS_LMS_API — единственный сетевой шов SPA профиля.

   Все экраны (журнал / КТП / проверка / …) ходят на бэкенд ТОЛЬКО через него.
   Хочешь перенести кабинет в Telegram Web App или мобильное приложение —
   переопределяешь транспорт и авторизацию ЗДЕСЬ (nonce → токен,
   admin-ajax → REST), а экраны не трогаешь.

   Точка переопределения без пересборки: внешний код (напр. Telegram-мост)
   может заменить `window.FS_LMS_API.request` своей реализацией — все экраны
   вызывают транспорт через объект, поэтому подмена подхватывается сразу.

   См. .docs/FS_LMS_API.md → раздел «Клиентский шов (FS_LMS_API)».
   ══════════════════════════════════════════════════════════════════════ */

/** Базовый URL транспорта (admin-ajax по умолчанию). */
function ajaxUrl() {
    const p = window.fsProfile || {};
    return p.ajax?.url || (typeof window.ajaxurl === 'string' ? window.ajaxurl : '/wp-admin/admin-ajax.php');
}

/**
 * Низкоуровневый вызов: action + nonce + params → json.data (или throw).
 * Единственное место, знающее про admin-ajax + nonce. Замени его — сменишь
 * транспорт для всего кабинета.
 *
 * @param {string} action  WP-action (snake_case).
 * @param {string} nonce   nonce блока конфига.
 * @param {Object} [params]
 * @returns {Promise<any>} json.data
 */
async function request(action, nonce, params) {
    const body = new URLSearchParams(Object.assign({ action, security: nonce }, params || {}));
    const res = await fetch(ajaxUrl(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });
    const json = await res.json().catch(() => ({ success: false }));
    if (!json || !json.success) {
        throw new Error(json?.data?.message || json?.data || 'Ошибка запроса');
    }
    return json.data;
}

export const FS_LMS_API = {
    ajaxUrl,
    request,

    /**
     * Фабрика api-хелпера экрана. Принимает блок конфига `{nonce, actions}`
     * (например `window.fsProfile.journal`) и возвращает `api(actionKey, params)`,
     * где actionKey — логический ключ действия из блока.
     *
     * @param {{nonce:string, actions:Object<string,string>}} block
     * @returns {(actionKey:string, params?:Object)=>Promise<any>}
     */
    createApi(block) {
        return (actionKey, params) => {
            const action = block && block.actions ? block.actions[actionKey] : null;
            if (!action) {
                return Promise.reject(new Error('FS_LMS_API: неизвестное действие "' + actionKey + '"'));
            }
            // Через объект (не по замыканию) — чтобы override window.FS_LMS_API.request работал.
            return FS_LMS_API.request(action, block.nonce, params);
        };
    },
};

if (typeof window !== 'undefined') {
    window.FS_LMS_API = FS_LMS_API;
}

/** Удобный именованный реэкспорт для экранов. */
export const createApi = (block) => FS_LMS_API.createApi(block);

export default FS_LMS_API;
