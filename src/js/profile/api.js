/* ══════════════════════════════════════════════════════════════════════
   FS_LMS_API — единственный сетевой шов SPA профиля.

   Все экраны (журнал / КТП / проверка / …) ходят на бэкенд ТОЛЬКО через него:
   транспорт и авторизация (nonce, admin-ajax) заданы ЗДЕСЬ, а не размазаны
   по экранам.

   Точка переопределения без пересборки: внешний код может заменить
   `window.FS_LMS_API.request` своей реализацией — все экраны вызывают
   транспорт через объект, поэтому подмена подхватывается сразу.

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
/**
 * Параметр в форме PHP: массив скаляров — `key[]=…`, вложенные массивы и объекты — `key[i][поле]=…`.
 * `URLSearchParams` из объекта склеил бы массив в строку «1,2», а объект превратил бы в «[object Object]».
 */
export function appendParam(body, key, value) {
    if (Array.isArray(value)) {
        value.forEach((item, i) => appendParam(body, item !== null && typeof item === 'object' ? `${key}[${i}]` : `${key}[]`, item));
    } else if (value !== null && typeof value === 'object') {
        Object.entries(value).forEach(([field, item]) => appendParam(body, `${key}[${field}]`, item));
    } else if (value !== undefined && value !== null) {
        body.append(key, value);
    }
}

async function request(action, nonce, params) {
    const body = new URLSearchParams();
    Object.entries(Object.assign({ action, security: nonce }, params || {})).forEach(([key, value]) => appendParam(body, key, value));
    const res = await fetch(ajaxUrl(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    });
    const json = await res.json().catch(() => ({ success: false }));
    if (!json || !json.success) {
        // Код и номер обращения (`ref`) нужны экранам, различающим причину отказа (X-FULL, X-CLOSED …);
        // существующие вызовы читают только `message` и не ломаются.
        const err = new Error(json?.data?.message || (typeof json?.data === 'string' ? json.data : '') || 'Ошибка запроса');
        err.code = json?.data?.code || '';
        err.ref = json?.data?.ref || '';
        throw err;
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
