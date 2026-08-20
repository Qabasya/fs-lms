import '../_types.js';
import { createSlotBuilder, post } from './slot-builder.js';
import { showToast } from '../modules/toast.js';

/* global fs_lms_vars */

/**
 * AssessmentBuilder — конструктор контрольной поверх общего {@link createSlotBuilder}.
 *
 * Специфика контрольной (через хуки конфига):
 *  - вид (kind): смена вида авто-заполняет/очищает слоты, блокируется при наличии задач;
 *  - баллы за задание (ЕГЭ): доп. поле в теле слота (`renderExtraBody`).
 */
export const AssessmentBuilder = {
	init() {
		document.querySelectorAll( '.fs-lms-assessment-builder' ).forEach( mount );
	},
};

function mount( el ) {
	const assessmentId  = parseInt( el.dataset.assessmentId, 10 ) || 0;
	const subject       = String( el.dataset.subject || '' );
	// Число позиций зависит от вида — ЕГЭ и ОГЭ считают разное количество
	// (см. AssessmentMetaBoxController::renderBuilderContent()); плоское число здесь
	// раньше приводило к тому, что ОГЭ ошибочно наследовал число слотов ЕГЭ.
	const egeSlotsByKind = JSON.parse( el.dataset.egeSlots || '{}' );
	const egeSlots       = ( kind ) => parseInt( egeSlotsByKind[ kind ], 10 ) || 0;
	const egeKinds      = JSON.parse( el.dataset.egeKinds || '[]' );
	const allowIncompleteKinds = JSON.parse( el.dataset.allowIncompleteKinds || '[]' );
	const taskPointsMap  = JSON.parse( el.dataset.taskPoints || '{}' );
	const acts          = fs_lms_vars.ajax_actions;
	const nonces        = fs_lms_vars.nonces;

	const kindSelect = document.querySelector( '.fs-lms-assessment-kind-select' );
	let prevKind     = kindSelect ? kindSelect.value : '';

	const isEge        = ( kind ) => egeKinds.includes( kind );
	// Тестовое окружение (см. AssessmentMetaBoxController::allowsIncompletePublish()) —
	// сервер уже разрешает публиковать эти виды неукомплектованными, поэтому клиентский
	// гейт (D16.5) их дизейблить не должен — только предупреждение остаётся видимым.
	const allowsIncomplete = ( kind ) => allowIncompleteKinds.includes( kind );
	const blankSlot    = ( i ) => ( { key: 'slot_' + i, taskId: 0, title: '', points: 1 } );
	const buildEgeSlots = ( count ) => Array.from( { length: count }, ( _, i ) => blankSlot( i ) );

	// D16.5: живой индикатор укомплектованности ЕГЭ — вставляется рядом с
	// конструктором (в .fs-sb-wrap), т.к. createSlotBuilder очищает innerHTML el.
	let statusBar = null;
	if ( egeSlots( prevKind ) > 0 && el.parentElement ) {
		statusBar = document.createElement( 'div' );
		statusBar.className = 'fs-ege-status';
		statusBar.hidden    = ! isEge( prevKind );
		el.parentElement.insertBefore( statusBar, el );
	}

	// Станции (ЕГЭ/ОГЭ) имитируют реальный экзамен: время/попытки/таблица баллов/
	// вступительный текст больше не редактируются автором работы — приходят из
	// module-level StationExamConfig (см. AssessmentMetaBoxController::handleAssessmentSave(),
	// .docs/Tasks.md §3.2). Скрываем эти поля вместо простого дизейбла — не
	// инлайновым style, а атрибутом hidden (см. CLAUDE.md, CSS/JS правила).
	// intro_html (EditorField) не имеет элемента с id="intro_html" (wp_editor
	// использует свой собственный id) — адресуем по классу-обёртке поля.
	const STATION_ONLY_HIDDEN_FIELD_IDS = [ 'time_limit_minutes', 'max_attempts', 'pass_score' ];

	function toggleKindFields( kind ) {
		const isStation = isEge( kind );

		STATION_ONLY_HIDDEN_FIELD_IDS.forEach( ( id ) => {
			const row = document.getElementById( id )?.closest( '.fs-field, .fs-lms-field-group' );
			if ( row ) { row.hidden = isStation; }
		} );
		// score_map — наоборот остальных station-only полей: нужен только ЕГЭ/ОГЭ
		// (перевод первичного балла во вторичный, SecondaryScoreService), у Control
		// нигде не читается — мёртвое поле, скрываем.
		const scoreMapRow = document.getElementById( 'score_map' )?.closest( '.fs-field, .fs-lms-field-group' );
		if ( scoreMapRow ) { scoreMapRow.hidden = ! isStation; }

		const introRow = document.querySelector( '.fs-lms-editor-field' );
		if ( introRow ) { introRow.hidden = isStation; }

		if ( statusBar ) { statusBar.hidden = ! isStation; }
		if ( ! isStation ) { gatePublish( true ); }
	}

	/**
	 * Гейт публикации (D16.5): для неукомплектованной ЕГЭ-работы блокируем кнопку
	 * «Опубликовать/Обновить». Черновик (Сохранить) остаётся доступен. Серверный
	 * гард (T16.7) — жёсткая страховка; здесь мягкий UX-барьер.
	 */
	function gatePublish( ok ) {
		const btn = document.getElementById( 'publish' );
		if ( ! btn ) { return; }
		btn.disabled = ! ok;
		btn.classList.toggle( 'disabled', ! ok );
		btn.setAttribute( 'aria-disabled', ok ? 'false' : 'true' );
	}

	/** Обновляет индикатор «Заполнено X/N» и подсветку пропусков/дублей/сирот. */
	function renderCompleteness( verdict ) {
		if ( ! statusBar || ! verdict ) { return; }

		const covered = Math.max( 0, verdict.expectedCount - ( verdict.missing?.length || 0 ) );
		statusBar.classList.toggle( 'is-complete', !! verdict.isComplete );
		statusBar.classList.toggle( 'is-incomplete', ! verdict.isComplete );

		const chips = [ `<span class="fs-ege-status__count">Заполнено ${ covered }/${ verdict.expectedCount }</span>` ];
		if ( verdict.missing?.length ) {
			chips.push( `<span class="fs-ege-status__warn">Не хватает номеров: ${ verdict.missing.join( ', ' ) }</span>` );
		}
		if ( verdict.duplicated?.length ) {
			chips.push( `<span class="fs-ege-status__warn">Дубли номеров: ${ verdict.duplicated.join( ', ' ) }</span>` );
		}
		if ( verdict.orphans?.length ) {
			chips.push( `<span class="fs-ege-status__warn">Заданий без номера: ${ verdict.orphans.length }</span>` );
		}
		if ( verdict.isComplete ) {
			chips.push( '<span class="fs-ege-status__ok">Работа укомплектована</span>' );
		}
		statusBar.innerHTML = chips.join( '' );

		gatePublish( !! verdict.isComplete || allowsIncomplete( prevKind ) );
	}

	function buildTaskPoints( slots ) {
		const map = {};
		slots.forEach( ( s ) => {
			if ( s.taskId > 0 ) { map[ s.taskId ] = s.points; }
		} );
		return map;
	}

	createSlotBuilder( el, {
		treeTitle: 'Структура контрольной',
		emptyText: 'Нет слотов — нажмите «+ Задача».',

		mapSlot: ( s, i ) => {
			const taskId = parseInt( s.payload?.ref, 10 ) || 0;
			return {
				key:    s.key || 'slot_' + i,
				taskId,
				title:  s._title || '',
				points: parseFloat( taskPointsMap[ taskId ] || 0 ),
			};
		},
		newSlot: blankSlot,

		// Пустые (ещё не заполненные) слоты — taskId=0 — в item_ids не идут: несколько
		// нулей подряд задевают гард дублей на бэкенде (AssessmentManager::setItemIds)
		// и сохранение целиком отклоняется с ложным «Экзамен не найден».
		persist: ( slots ) => post( acts.saveAssessmentItems, nonces.authorAssessment, {
			assessment_id: assessmentId,
			item_ids:      slots.filter( ( s ) => s.taskId > 0 ).map( ( s ) => s.taskId ),
			task_points:   buildTaskPoints( slots ),
		} ),

		// D16.5: ответ сохранения несёт строгий вердикт полноты (T16.10) —
		// обновляем индикатор и гейт публикации.
		onPersisted: ( data ) => {
			if ( data && data.completeness ) { renderCompleteness( data.completeness ); }
		},

		// Позиция слота (1-based) = номер задания экзамена: для ЕГЭ/ОГЭ отдаём её
		// бэкенду, чтобы выпадающий список показывал только подходящие по номеру
		// задачи (предметные — по терму {subject}_task_number, банковские — по
		// PostMetaName::BankTaskSubject/BankTaskNumber, см. LessonAuthoringService).
		search: ( q, index ) => post( acts.getStepCandidates, nonces.authorLesson, {
			subject_key: subject,
			kind:        'task',
			source:      'all', // и банк, и задачи предмета (как в Работах) — с бейджем источника
			search:      q,
			position:    isEge( prevKind ) && 'number' === typeof index ? String( index + 1 ) : '',
		} ),

		preview: ( taskId ) => post( acts.getTaskPreview, nonces.authorAssessment, {
			task_id:     taskId,
			subject_key: subject,
		} ),

		createTask: ( title ) => post( acts.createAssessmentTaskDraft, nonces.authorAssessment, {
			subject_key: subject,
			title,
		} ),

		onReady: ( api ) => {
			if ( kindSelect ) {
				toggleKindFields( prevKind );

				kindSelect.addEventListener( 'change', () => {
					const newKind = kindSelect.value;

					if ( api.getSlots().some( ( s ) => s.taskId > 0 ) ) {
						kindSelect.value = prevKind;
						showToast( 'Нельзя изменить тип: в контрольной уже есть задачи', 'error' );
						return;
					}

					prevKind = newKind;
					toggleKindFields( newKind );

					if ( isEge( newKind ) ) {
						if ( egeSlots( newKind ) > 0 ) {
							api.replaceSlots( buildEgeSlots( egeSlots( newKind ) ), 0 );
							api.save();
						}
					} else {
						api.replaceSlots( [], -1 );
					}
				} );
			}

			// Авто-заполнение слотов под число заданий ЕГЭ/ОГЭ: пустые (ещё не выбранные)
			// позиции в item_ids не сохраняются (см. persist() выше — taskId=0 отфильтрован),
			// поэтому после частичного заполнения сервер при перезагрузке отдаёт только
			// реально заполненные слоты. Без паддинга остальные заготовленные пустые
			// позиции (напр. 25 из 27 у ЕГЭ) при повторном открытии терялись — довозим
			// до egeSlots(kind), сохраняя уже заполненные слоты на своих местах.
			if ( isEge( prevKind ) ) {
				const expected = egeSlots( prevKind );
				const current  = api.getSlots();
				if ( expected > 0 && current.length < expected ) {
					const missing = Array.from(
						{ length: expected - current.length },
						( _, i ) => blankSlot( current.length + i )
					);
					api.replaceSlots( current.concat( missing ), 0 );
				}
				// Тихо запрашиваем вердикт полноты для индикатора (persist() отфильтрует
				// пустые слоты сам — серверный item_ids не меняется).
				api.save();
			}
		},
	} );
}
