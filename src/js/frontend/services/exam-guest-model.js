/**
 * Чистые решения страниц гостя (этап 11b.4): когда страницу, восстановленную из кеша переходов браузера, нужно перезагрузить.
 * Вынесено отдельно от DOM, чтобы проверяться юнит-тестом.
 */

/**
 * @param {{persisted?: boolean}|null|undefined} event Событие `pageshow`.
 * @return {boolean} true — страница возвращена из bfcache (кнопка «Назад»): содержимое устарело, сервер должен решить заново.
 */
export function shouldReloadOnPageshow( event ) {
    return Boolean( event && event.persisted );
}
