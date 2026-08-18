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
	const taskNumbersMap = JSON.parse( el.dataset.taskNumbers || '{}' );
	const acts          = fs_lms_vars.ajax_actions;
	const nonces        = fs_lms_vars.nonces;

	const kindSelect = document.querySelector( '.fs-lms-assessment-kind-select' );
	let prevKind     = kindSelect ? kindSelect.value : '';

	const isEge        = ( kind ) => egeKinds.includes( kind );
	// Тестовое окружение (см. AssessmentMetaBoxController::allowsIncompletePublish()) —
	// сервер уже разрешает публиковать эти виды неукомплектованными, поэтому клиентский
	// гейт (D16.5) их дизейблить не должен — только предупреждение остаётся видимым.
	const allowsIncomplete = ( kind ) => allowIncompleteKinds.includes( kind );
	const blankSlot    = ( i ) => ( { key: 'slot_' + i, taskId: 0, title: '', points: 1, number: '' } );
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
	const STATION_ONLY_HIDDEN_FIELD_IDS = [ 'time_limit_minutes', 'max_attempts', 'pass_score', 'score_map' ];

	function toggleKindFields( kind ) {
		const isStation = isEge( kind );

		STATION_ONLY_HIDDEN_FIELD_IDS.forEach( ( id ) => {
			const row = document.getElementById( id )?.closest( '.fs-field, .fs-lms-field-group' );
			if ( row ) { row.hidden = isStation; }
		} );
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

	// Задача 8: номера банковских задач (fs_lms_problems) — таксономии у них нет,
	// номер задаётся вручную здесь. Пустые не отправляем.
	function buildTaskNumbers( slots ) {
		const map = {};
		slots.forEach( ( s ) => {
			if ( s.taskId > 0 && s.number && '' !== String( s.number ).trim() ) {
				map[ s.taskId ] = String( s.number ).trim();
			}
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
				number: String( taskNumbersMap[ taskId ] || '' ),
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
			task_numbers:  buildTaskNumbers( slots ),
		} ),

		// D16.5: ответ сохранения несёт строгий вердикт полноты (T16.10) —
		// обновляем индикатор и гейт публикации.
		onPersisted: ( data ) => {
			if ( data && data.completeness ) { renderCompleteness( data.completeness ); }
		},

		search: ( q ) => post( acts.getStepCandidates, nonces.authorLesson, {
			subject_key: subject,
			kind:        'task',
			source:      'all', // и банк, и задачи предмета (как в Работах) — с бейджем источника
			search:      q,
		} ),

		preview: ( taskId ) => post( acts.getTaskPreview, nonces.authorAssessment, {
			task_id:     taskId,
			subject_key: subject,
		} ),

		createTask: ( title ) => post( acts.createAssessmentTaskDraft, nonces.authorAssessment, {
			subject_key: subject,
			title,
		} ),

		// Номер задания — только для ЕГЭ/ОГЭ-видов. Баллы за задание больше не
		// вводятся здесь (§3.5, .docs/Tasks.md): для станций это фиксированная
		// таблица «номер → баллы» в module-level конфиге (аналог KegeScaleConfig/
		// OgeCriteriaConfig), подставляется на чтении (AssessmentManager::STATION_SETTINGS_FILTER-
		// подобная подмена в EgeComputerModule) — редактировать её через builder UI нельзя.
		renderExtraBody: ( container, slot, index, api ) => {
			if ( ! isEge( prevKind ) ) { return; }

			// Задача 8: «Номер задания» для банковских задач (fs_lms_problems) — у них
			// нет таксономии {subject}_task_number. Для обычных subject-задач номер берётся
			// из таксономии автоматически (введённое здесь бэкенд игнорирует при наличии терма).
			const numWrap = document.createElement( 'div' );
			numWrap.className = 'fs-sb-task-number';

			const numLabel = document.createElement( 'label' );
			numLabel.textContent = 'Номер задания (для банка):';
			numLabel.htmlFor     = 'fs-sb-number-' + index;

			const numInput = document.createElement( 'input' );
			numInput.type      = 'text';
			numInput.id        = 'fs-sb-number-' + index;
			numInput.className = 'small-text';
			numInput.value     = String( slot.number || '' );
			numInput.addEventListener( 'change', () => {
				slot.number = numInput.value.trim();
				api.save();
			} );

			numWrap.appendChild( numLabel );
			numWrap.appendChild( numInput );
			container.appendChild( numWrap );
		},

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

			// Авто-заполнение слотов под число заданий ЕГЭ/ОГЭ при первом открытии.
			if ( egeSlots( prevKind ) > 0 && api.getSlots().length === 0 && isEge( prevKind ) ) {
				api.replaceSlots( buildEgeSlots( egeSlots( prevKind ) ), 0 );
				api.save();
			} else if ( isEge( prevKind ) ) {
				// Слоты уже есть — тихо запрашиваем начальный вердикт полноты для индикатора.
				api.save();
			}
		},
	} );
}
