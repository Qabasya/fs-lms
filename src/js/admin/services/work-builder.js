import '../_types.js';
import { createSlotBuilder, post } from './slot-builder.js';
import { workSlot, workTaskPoints } from './work-task-points.js';
import { candidateSource } from '../modules/picker.js';

/* global fs_lms_vars */

/**
 * WorkBuilder — конструктор работы поверх общего {@link createSlotBuilder}.
 * Работа — упорядоченные задачи с баллами этой работы (по умолчанию один).
 */
export const WorkBuilder = {
	init() {
		document.querySelectorAll( '.fs-lms-work-builder' ).forEach( mount );
	},
};

function mount( el ) {
	const workId      = parseInt( el.dataset.workId, 10 ) || 0;
	const subject     = String( el.dataset.subject || '' );
	const numberPrefix = String( el.dataset.numberPrefix || '' );

	// Предметы-источники для «Массового добавления заданий»; свой предмет — первым (он выбран по умолчанию).
	const allSubjects  = fs_lms_vars.subjects || [];
	const bulkSubjects = [
		...allSubjects.filter( ( s ) => s.key === subject ),
		...allSubjects.filter( ( s ) => s.key !== subject ),
	];
	const acts        = fs_lms_vars.ajax_actions;
	const nonces      = fs_lms_vars.nonces;

	// Тип работы выбирается в соседнем метабоксе и может смениться без перезагрузки — читаем при каждом поиске.
	const workType = () => String( document.querySelector( '[name="fs_lms_meta[work_type]"]' )?.value || '' );

	createSlotBuilder( el, {
		treeTitle: 'Структура работы',
		reorderable: true,
		preserveSlotOnExpand: true,
		mapSlot: workSlot,
		newSlot: ( index ) => ( { key: 'slot_' + index, taskId: 0, title: '', points: 1 } ),
		renderExtraBody: ( body, slot, index, api ) => {
			const label = document.createElement( 'label' );
			label.className = 'fs-sb-task-section fs-sb-work-points';
			label.textContent = 'Баллов за задачу: ';
			const input = document.createElement( 'input' );
			input.type = 'number';
			input.required = true;
			input.min = '1';
			input.max = '999';
			input.step = '1';
			input.value = String( slot.points ?? 1 );
			input.addEventListener( 'change', () => {
				if ( !input.checkValidity() || !Number.isInteger( Number( input.value ) ) ) { input.reportValidity(); return; }
				slot.points = Number( input.value );
				api.save();
			} );
			label.appendChild( input );
			body.appendChild( label );
		},
		emptyText: 'Нет заданий — нажмите «+ Задача».',

		// Пустые (ещё не заполненные) слоты — taskId=0 — в item_ids не идут: несколько
		// нулей подряд задевают гард дублей на бэкенде (WorkManager::setItemIds) и
		// сохранение целиком отклоняется.
		persist: ( slots ) => post( acts.saveWorkItems, nonces.authorWork, {
			work_id:  workId,
			item_ids: slots.filter( ( s ) => s.taskId > 0 ).map( ( s ) => s.taskId ),
			task_points: workTaskPoints( slots ),
		} ),

		// Дропдаун по умолчанию (пустой поиск) — только задания предмета; «Все
		// задания» (scope='all') или непустой поиск — предмет + глобальный банк.
		search: ( q, index, scope ) => post( acts.getWorkItemCandidates, nonces.authorWork, {
			subject_key: subject,
			search:      q,
			source:      candidateSource( q, scope ),
			work_type:   workType(),
		} ),

		// Окно «Массовое добавление заданий»: тот же эндпоинт, но постранично. Источник
		// выбирает окно — 'subject' (приватный банк, как «Выбрать из банка») или 'public'.
		bulkSearch: ( q, scope, page, fromSubject ) => post( acts.getWorkItemCandidates, nonces.authorWork, {
			subject_key:    fromSubject || subject,
			target_subject: subject, // в какую работу добавляем: из чужого предмета — только опубликованное
			search:         q,
			source:         candidateSource( q, scope ),
			work_type:      workType(),
			page,
		} ),

		// Превью задачи переиспользует общий эндпоинт банка задач (нонс контрольной).
		preview: ( taskId ) => post( acts.getTaskPreview, nonces.authorAssessment, {
			task_id:     taskId,
			subject_key: subject,
		} ),

		subjectKey: subject,
		bulkSubjects,

		// Тип работы — для сужения шаблонов у новой задачи («Создать задачу»).
		workType,

		// «1.4. П-1» (префикс из настроек работы) → «1.4. П-1-1», «1.4. П-1-2»… — меняется
		// только последняя цифра (порядковый номер задачи в работе).
		suggestTitle: ( filledCount ) => ( numberPrefix ? `${ numberPrefix }-${ filledCount + 1 }` : '' ),
	} );
}
