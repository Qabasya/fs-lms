/**
 * Названия типов работ и автозаполнение названия новой работы из конструктора курса.
 */

/** Тип работы → название. */
export const WORK_NAMES = {
	practice:    'Практика',
	independent: 'Самостоятельная работа',
	homework:    'Домашняя работа',
	survey:      'Опрос',
};

/** Типы, которые идут по прошлой теме: опрос и самостоятельная проверяют уже пройденное. */
const PREVIOUS_TOPIC_TYPES = [ 'survey', 'independent' ];

/**
 * «Тема. Тип работы»: для практики и домашней работы тема — текущая, для опроса и
 * самостоятельной — прошлая (у первого урока прошлой нет — берётся текущая).
 *
 * @param {string} workType        practice | independent | homework | survey
 * @param {string} currentTopic    Название текущего урока
 * @param {string} previousTopic   Название предыдущего урока ('' — нет)
 * @returns {string} Название; '' — тип неизвестен
 */
export function suggestWorkTitle( workType, currentTopic, previousTopic ) {
	const name = WORK_NAMES[ workType ];
	if ( ! name ) { return ''; }

	const topic = ( PREVIOUS_TOPIC_TYPES.includes( workType ) ? ( previousTopic || currentTopic ) : currentTopic ) || '';

	return topic.trim() ? `${ topic.trim() }. ${ name }` : name;
}
