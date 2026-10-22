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

/* ── Кеш чтения ───────────────────────────────────────────────────────────
   Живёт в памяти вкладки и только на тех действиях, что экран явно объявил в
   `createApi(block, { cache })`. Остальное чтение (журнал и т.п.) идёт напрямую.

   Инвалидация — по соглашению об именах: действие, начинающееся с get/list/count,
   считается чтением и кеш не трогает; любое другое (сохранить, выставить, отменить…)
   сбрасывает ВЕСЬ кеш — до запроса и после ответа. Действия, которые не читают и не
   меняют данные кабинета (журнал событий, отметка уведомления), объявляются в `passive`.
   Данные хранятся строкой JSON: вызывающий получает свежую копию и не может испортить кеш,
   а сравнение «изменилось ли» при фоновой сверке сводится к сравнению строк. */

const READ_ACTION = /^(get|list|count)/;
const cacheStore  = new Map(); // ключ → { at, json }
const inflight    = new Map(); // ключ → Promise<string> (запрос предзагрузки, общий с настоящими вызовами)
let cacheEpoch    = 0;         // растёт при сбросе: ответ, начатый до записи, в кеш не попадает

/** Сбрасывает весь кеш чтения (вызывается и вручную, например при смене ребёнка). */
export function clearApiCache() {
    cacheEpoch++;
    cacheStore.clear();
    inflight.clear();
}

function cacheKey(action, params) {
    const sorted = Object.keys(params || {}).sort().map((k) => [k, params[k]]);
    return action + '|' + JSON.stringify(sorted);
}

/**
 * Запрос в кеш; результат сохраняется, только если кеш не сбрасывали за время запроса.
 * `share` — предзагрузка: её запрос видят настоящие вызовы и не шлют второй такой же.
 * Обычные вызовы друг к другу не присоединяются: каждый экран сам решает, чей ответ устарел.
 */
function loadJson(key, action, nonce, params, share = false) {
    if (inflight.has(key)) { return inflight.get(key); }
    const epoch = cacheEpoch;
    const promise = FS_LMS_API.request(action, nonce, params)
        .then((data) => {
            const json = JSON.stringify(data === undefined ? null : data);
            if (epoch === cacheEpoch) { cacheStore.set(key, { at: Date.now(), json }); }
            return json;
        })
        .finally(() => { if (inflight.get(key) === promise) { inflight.delete(key); } });
    if (share) { inflight.set(key, promise); }
    return promise;
}

/**
 * Чтение через кеш.
 * `rule.ttl` — сколько мс запись считается свежей; `rule.swr` — при попадании в кеш ещё и сверить
 * с сервером в фоне и, если ответ изменился, позвать `callOpts.onUpdate(свежие данные)`.
 */
function readCached(rule, action, nonce, params, callOpts) {
    const key   = cacheKey(action, params);
    const entry = cacheStore.get(key);
    if (entry && Date.now() - entry.at < rule.ttl) {
        if (rule.swr && typeof callOpts.onUpdate === 'function') {
            const shown = entry.json;
            loadJson(key, action, nonce, params)
                .then((json) => { if (json !== shown) { callOpts.onUpdate(JSON.parse(json)); } })
                .catch(() => { /* фоновая сверка: показанное остаётся */ });
        }
        return Promise.resolve(JSON.parse(entry.json));
    }
    return loadJson(key, action, nonce, params).then((json) => JSON.parse(json));
}

/** Запись: кеш сбрасывается до запроса и после ответа (успешного или нет — сервер мог успеть измениться). */
function writeThrough(action, nonce, params) {
    clearApiCache();
    return FS_LMS_API.request(action, nonce, params).then(
        (data) => { clearApiCache(); return data; },
        (err) => { clearApiCache(); throw err; },
    );
}

export const FS_LMS_API = {
    ajaxUrl,
    request,

    /**
     * Фабрика api-хелпера экрана. Принимает блок конфига `{nonce, actions}`
     * (например `window.fsProfile.journal`) и возвращает `api(actionKey, params, callOpts)`,
     * где actionKey — логический ключ действия из блока.
     *
     * @param {{nonce:string, actions:Object<string,string>}} block
     * @param {{cache?:Object<string,number|{ttl:number,swr?:boolean}>, passive?:string[]}} [opts]
     *        cache — ключи действий, чтение которых кешируется (число = ttl в мс);
     *        passive — ключи, не читающие и не меняющие данные (не сбрасывают кеш).
     * @returns {((actionKey:string, params?:Object, callOpts?:{onUpdate?:Function})=>Promise<any>) & {prefetch:Function}}
     */
    createApi(block, opts = {}) {
        const rules   = opts.cache || {};
        const passive = new Set(opts.passive || []);

        const resolve = (actionKey) => {
            const action = block && block.actions ? block.actions[actionKey] : null;
            return action || null;
        };

        const api = (actionKey, params, callOpts = {}) => {
            const action = resolve(actionKey);
            if (!action) {
                return Promise.reject(new Error('FS_LMS_API: неизвестное действие "' + actionKey + '"'));
            }
            // Через объект (не по замыканию) — чтобы override window.FS_LMS_API.request работал.
            if (rules[actionKey] !== undefined) {
                const rule = typeof rules[actionKey] === 'number' ? { ttl: rules[actionKey] } : rules[actionKey];
                return readCached(rule, action, block.nonce, params, callOpts);
            }
            if (READ_ACTION.test(actionKey) || passive.has(actionKey)) {
                return FS_LMS_API.request(action, block.nonce, params);
            }
            return writeThrough(action, block.nonce, params);
        };

        /** Прогревает кеш (наведение на строку списка); ошибки молча игнорируются. */
        api.prefetch = (actionKey, params) => {
            const action = resolve(actionKey);
            const rule   = rules[actionKey];
            if (!action || rule === undefined) { return; }
            const key   = cacheKey(action, params);
            const entry = cacheStore.get(key);
            const ttl   = typeof rule === 'number' ? rule : rule.ttl;
            if (entry && Date.now() - entry.at < ttl) { return; }
            loadJson(key, action, block.nonce, params, true).catch(() => {});
        };

        return api;
    },
};

if (typeof window !== 'undefined') {
    window.FS_LMS_API = FS_LMS_API;
}

/** Удобный именованный реэкспорт для экранов. */
export const createApi = (block, opts) => FS_LMS_API.createApi(block, opts);

export default FS_LMS_API;
