/**
 * Когда у работы показывается «Исправить и сдать заново»: пока есть что исправлять, остались попытки и срок сдачи не вышел.
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
 * @param {{left: number, allLocked: boolean, pastDue: boolean, verdicts: Object, taskIds: string[]}} state
 *        left — осталось попыток (Infinity — без лимита); allLocked — все задания уже засчитаны; pastDue — срок сдачи вышел
 */
export function retryAvailable( { left, allLocked, pastDue, verdicts, taskIds } ) {
	return left > 0 && ! allLocked && ! pastDue && hasUnsolved( verdicts, taskIds );
}
