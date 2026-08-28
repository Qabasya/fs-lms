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
	// .docs/Tasks.md §3.2). «Настройки контрольной» и «Таблица перевода баллов» —
	// теперь отдельные метабоксы (см. .docs/Tasks.md «тип экзамена — отдельный
	// метабокс»), скрывается сразу весь <div class="postbox"> атрибутом hidden
	// (не style — см. CLAUDE.md, CSS/JS правила), не отдельные поля внутри.
	// «Тип экзамена» (#fs_lms_assessment_kind) не трогаем — виден всегда.
	function toggleKindFields( kind ) {
		const isStation = isEge( kind );

		const settingsBox = document.getElementById( 'fs_lms_assessment_settings' );
		if ( settingsBox ) { settingsBox.hidden = isStation; }

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

	// Заполняется в onReady — используется onPick ниже (задача C, .docs/Tasks.md):
	// пик задания-ребёнка связки 19-21 в ЕГЭ/ОГЭ-слоте сразу проставляет сиблингов
	// в позиционные слоты 20/21, без ручного поиска по каждому номеру.
	let builderApi = null;

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
		// Дропдаун по умолчанию (пустой поиск) — только задания предмета; «Все
		// задания» (scope='all') или непустой поиск — предмет + глобальный банк
		// (с бейджем источника).
		search: ( q, index, scope ) => post( acts.getStepCandidates, nonces.authorLesson, {
			subject_key: subject,
			kind:        'task',
			source:      q ? 'all' : ( scope || 'subject' ),
			search:      q,
			position:    isEge( prevKind ) && 'number' === typeof index ? String( index + 1 ) : '',
		} ),

		preview: ( taskId ) => post( acts.getTaskPreview, nonces.authorAssessment, {
			task_id:     taskId,
			subject_key: subject,
		} ),

		subjectKey: subject,

		// EGE/ОГЭ-позиционный слот: пик задания с bundle_siblings (ребёнок связки
		// 19-21) сам расставляет все три номера по своим индексам (position - 1),
		// без splice — иначе слоты 20+ сместились бы (задача C, .docs/Tasks.md).
		// Возврат true отменяет дефолтный assignPicked() в slot-builder.js.
		onPick: ( index, id, title, item ) => {
			if ( ! builderApi || ! isEge( prevKind ) || ! item || ! item.bundle_siblings ) {
				return false;
			}

			const total = egeSlots( prevKind );
			const pairs = Object.keys( item.bundle_siblings )
				.map( ( number ) => ( {
					index:  parseInt( number, 10 ) - 1,
					taskId: item.bundle_siblings[ number ].id,
					title:  item.bundle_siblings[ number ].title,
				} ) )
				.filter( ( p ) => p.index >= 0 && p.index < total );

			if ( ! pairs.length ) { return false; }

			builderApi.assignManyAt( pairs );
			showToast( 'Связка разложена на ' + pairs.length + ' номера', 'success' );
			return true;
		},

		onReady: ( api ) => {
			builderApi = api;
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
