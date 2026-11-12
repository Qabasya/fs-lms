/**
 * Когда у работы показывается «Исправить и сдать заново»: пока есть что исправлять, остались попытки.
 * Нерешённое задание — то, на которое дан неверный ответ или ответа нет; верное и ждущее ручной проверки не считаются.
 */

/**
 * @param {Object<string, {verdict?: string}>} verdicts Вердикты по ID задания
 * @param {string[]}                           taskIds  ID заданий работы
 */
export function hasUnsolved( verdicts, taskIds ) {
	return taskIds.some( ( id ) => {
		const verdict = verdicts?.[ id ]?.verdict;
		return 'correct' !== verdict && 'pending' !== verdict;
	} );
}

/**
 * @param {{left: number, allLocked: boolean, verdicts: Object, taskIds: string[]}} state
 *        left — осталось попыток (Infinity — без лимита); allLocked — все задания уже засчитаны
 */
export function retryAvailable( { left, allLocked, verdicts, taskIds } ) {
	return left > 0 && ! allLocked && hasUnsolved( verdicts, taskIds );
}

export function restoreWorkAnswer( { check, draft, submitted } ) {
	if ( check && 'correct' === check.status ) {
		const checked = check.answer;
		const text = 'string' === typeof checked ? checked : checked?.text;
		if ( draft && 'object' === typeof draft && 'string' === typeof draft.code && draft.text === text ) {
			return { text, code: draft.code };
		}
		return checked;
	}
	return draft !== undefined ? draft : ( check ? check.answer : submitted );
}
