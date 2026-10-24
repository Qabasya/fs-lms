/**
 * Honeypot-ловушка публичных форм (заявка, запись гостя на экзамен): разметка — partials/form-trap.php.
 *
 * Ловушка ловит скрипты, которые вписывают текст во все поля. Браузер, подставивший данные профиля
 * (автозаполнение вписывает и скрытые поля), — это человек, поэтому значение, пришедшее от браузера
 * (`:autofill`), очищается до отправки. Отказ сервера по ловушке восстановим: поле очищено,
 * повторная отправка проходит.
 */

/** Селектор ловушки: по атрибуту, а не по имени — имя приходит с сервера. */
export const TRAP_SELECTOR = '[data-fs-trap]';

/** `:autofill` — стандарт, `-webkit-`/`-moz-` — старые браузеры; незнакомый селектор бросает SyntaxError. */
const AUTOFILL_SELECTORS = [ ':autofill', ':-webkit-autofill', ':-moz-autofill' ];

/**
 * Значение в поле вписал браузер (после правки скриптом или с клавиатуры признак пропадает).
 *
 * @param {HTMLInputElement} input
 * @returns {boolean}
 */
export function isAutofilled( input ) {
    return AUTOFILL_SELECTORS.some( ( selector ) => {
        try {
            return input.matches( selector );
        } catch {
            return false;
        }
    } );
}

/**
 * Поле ловушки для тела запроса: значение, вписанное автозаполнением, снимается.
 *
 * @param {ParentNode} [root]
 * @returns {Record<string, string>} `{ имя: значение }` или пустой объект, если ловушки на странице нет.
 */
export function trapField( root = document ) {
    const input = root.querySelector( TRAP_SELECTOR );
    if ( ! input ) { return {}; }

    if ( '' !== input.value && isAutofilled( input ) ) { input.value = ''; }

    return { [ input.name ]: input.value };
}

/**
 * Очищает ловушку после отказа сервера: человеку с расширением, которое вписало поле мимо `:autofill`,
 * повторная отправка должна пройти.
 *
 * @param {ParentNode} [root]
 */
export function resetTrap( root = document ) {
    const input = root.querySelector( TRAP_SELECTOR );
    if ( input ) { input.value = ''; }
}
