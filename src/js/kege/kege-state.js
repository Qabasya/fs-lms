/**
 * Состояние станции КЕГЭ в localStorage — единственный владелец ключа и схемы.
 *
 * Читают и пишут оба экрана: ритуал входа (kege-entry.js) и сам экзамен
 * (kege-exam.js — шапка КИМ/БР и стадия предпросмотра), поэтому дефолты живут
 * здесь, а не копируются в каждый модуль. Смысл состояния — пережить
 * обновление страницы: номер бланка, номер КИМ, код активации, текущая стадия
 * и открытое задание не должны вводиться заново.
 *
 * Ключ — свой на каждую контрольную. Общий ключ означал бы, что стадия
 * переносится между экзаменами: закончил предпросмотр одного — второй сразу
 * открывается финальным экраном с чужой контрольной суммой, минуя ритуал.
 *
 * Стадии: четыре шага ритуала (KEGE_RITUAL_STAGES) плюс 'exam' и 'done' —
 * последние две используются только в предпросмотре автора, где настоящей
 * попытки нет и серверу стадия неизвестна. В реальном прохождении стадию
 * после старта определяет сервер (активная попытка → сразу экран экзамена).
 */

const LS_PREFIX = 'fsKegeSimV2:';

/** Шаги ритуала входа — те, что умеет показывать kege/entry.php. */
export const KEGE_RITUAL_STAGES = [ 'entry', 'instr', 'reg', 'act' ];

const ALL_STAGES = KEGE_RITUAL_STAGES.concat( [ 'exam', 'done' ] );

/**
 * Идентификатор контрольной, к которой привязано состояние. Ставится один раз
 * при инициализации бандла (kege.js) — модулям остаётся звать функции без
 * лишнего параметра, а ключ при этом остаётся раздельным.
 */
let currentKey = LS_PREFIX + '0';

/** @param {string|number} assessmentId Контрольная, чьё состояние читаем/пишем */
export function useKegeAssessment( assessmentId ) {
	currentKey = LS_PREFIX + String( assessmentId || '0' );
}

function defaults() {
	return { stage: 'entry', br: [ '', '', '' ], slide: 0, kim: null, code: null, task: '', taskAttempt: '', answers: {} };
}

/**
 * Приведение прочитанного к схеме. Хранилище правит кто угодно (девтулзы,
 * недописанная запись, версия постарше), а модули потом зовут `state.br.join()`
 * и `Math.min( state.slide, … )` — без проверки типов одна кривая запись роняет
 * экран целиком, поэтому чужие значения не пропускаем, а заменяем дефолтом.
 */
function normalize( raw ) {
	const state = defaults();
	if ( ! raw || 'object' !== typeof raw ) { return state; }

	if ( ALL_STAGES.includes( raw.stage ) ) { state.stage = raw.stage; }
	if ( Array.isArray( raw.br ) ) { state.br = [ 0, 1, 2 ].map( ( i ) => String( raw.br[ i ] ?? '' ) ); }
	if ( Number.isInteger( raw.slide ) && raw.slide >= 0 ) { state.slide = raw.slide; }
	if ( 'string' === typeof raw.kim ) { state.kim = raw.kim; }
	if ( 'string' === typeof raw.code ) { state.code = raw.code; }
	if ( 'string' === typeof raw.task ) { state.task = raw.task; }
	if ( 'string' === typeof raw.taskAttempt ) { state.taskAttempt = raw.taskAttempt; }
	if ( raw.answers && 'object' === typeof raw.answers && ! Array.isArray( raw.answers ) ) {
		Object.entries( raw.answers ).forEach( ( [ taskId, text ] ) => {
			if ( 'string' === typeof text ) { state.answers[ String( taskId ) ] = text; }
		} );
	}

	return state;
}

export function loadKegeState() {
	try {
		return normalize( JSON.parse( localStorage.getItem( currentKey ) ) );
	} catch ( e ) {
		return defaults();
	}
}

/**
 * Запись слиянием поверх хранилища, а не подменой целиком: у состояния два
 * писателя с разным сроком жизни копии — ритуал держит свой объект от загрузки
 * страницы, экзамен пишет открытое задание по ходу дела. Подмена целиком
 * означала бы, что тот, кто загрузился раньше, затирает чужие ключи.
 */
export function saveKegeState( patch ) {
	try {
		localStorage.setItem( currentKey, JSON.stringify( Object.assign( loadKegeState(), patch ) ) );
	} catch ( e ) {
		// Приватный режим/переполнение — станция продолжает работать, но без
		// восстановления после обновления страницы.
	}
}

/** Сохранить только стадию, не трогая уже введённые бланк/КИМ/код. */
export function setKegeStage( stage ) {
	saveKegeState( { stage } );
}

/**
 * Открытое задание экзамена ('i' или порядковый номер) — чтобы обновление
 * страницы возвращало на то же задание, а не на вкладку инструкции. Номер
 * попытки хранится рядом: в предпросмотре его нет (0), и без привязки задание
 * открылось бы по позиции из прошлой попытки той же контрольной.
 *
 * @param {string|number} task      Ключ панели ('i' или порядковый номер)
 * @param {string|number} attemptId Попытка, которой принадлежит панель
 */
export function setKegeTask( task, attemptId ) {
	saveKegeState( { task: String( task ), taskAttempt: String( attemptId ) } );
}

/**
 * Ответы предпросмотра автора: попытки в БД нет, писать их некуда, а лист
 * ответов считается по накопленному в этой вкладке. Без хранения обновление
 * страницы обнуляло и набранные ответы, и уже показанный лист.
 *
 * В реальном прохождении не используется — там источник истины БД (autosave).
 *
 * @param {Object<string, string>} answers Ответ по task_id, как он лёг бы в answer_text
 */
export function setKegeAnswers( answers ) {
	saveKegeState( { answers } );
}

export function clearKegeState() {
	localStorage.removeItem( currentKey );
}

/**
 * Тренажёрные номера КИМ и бланка регистрации для шапок станции (экран экзамена
 * и лист ответов). Значения вводятся/генерируются в ритуале входа, а если
 * состояние вычищено (другой браузер, приватный режим) — детерминированный
 * фолбэк от номера попытки: шапка не должна оставаться пустой.
 *
 * @param {object}        state     Состояние станции ({@see loadKegeState})
 * @param {string|number} attemptId Попытка, чью шапку рисуем
 */
export function kegeKim( state, attemptId ) {
	return state.kim || '25' + String( attemptId ).padStart( 6, '0' ).slice( -6 );
}

/** @see kegeKim */
export function kegeBr( state, attemptId ) {
	return ( state.br || [] ).join( '' ) || String( attemptId ).padStart( 12, '0' ).slice( -12 );
}
