import '../_types.js';
import { createSlotBuilder, post } from './slot-builder.js';

/* global fs_lms_vars */

/**
 * WorkBuilder — конструктор работы поверх общего {@link createSlotBuilder}.
 * Работа — плоский список задач (item_ids), без баллов и видов.
 */
export const WorkBuilder = {
	init() {
		document.querySelectorAll( '.fs-lms-work-builder' ).forEach( mount );
	},
};

function mount( el ) {
	const workId  = parseInt( el.dataset.workId, 10 ) || 0;
	const subject = String( el.dataset.subject || '' );
	const acts    = fs_lms_vars.ajax_actions;
	const nonces  = fs_lms_vars.nonces;

	createSlotBuilder( el, {
		treeTitle: 'Структура работы',
		emptyText: 'Нет заданий — нажмите «+ Задача».',

		// Пустые (ещё не заполненные) слоты — taskId=0 — в item_ids не идут: несколько
		// нулей подряд задевают гард дублей на бэкенде (WorkManager::setItemIds) и
		// сохранение целиком отклоняется.
		persist: ( slots ) => post( acts.saveWorkItems, nonces.authorWork, {
			work_id:  workId,
			item_ids: slots.filter( ( s ) => s.taskId > 0 ).map( ( s ) => s.taskId ),
		} ),

		// Дропдаун по умолчанию (пустой поиск) — только задания предмета; «Все
		// задания» (scope='all') или непустой поиск — предмет + глобальный банк.
		search: ( q, index, scope ) => post( acts.getWorkItemCandidates, nonces.authorWork, {
			subject_key: subject,
			search:      q,
			source:      q ? 'all' : ( scope || 'subject' ),
		} ),

		// Превью задачи переиспользует общий эндпоинт банка задач (нонс контрольной).
		preview: ( taskId ) => post( acts.getTaskPreview, nonces.authorAssessment, {
			task_id:     taskId,
			subject_key: subject,
		} ),

		createTask: ( title ) => post( acts.createProblemDraft, nonces.authorWork, { title } ),
	} );
}
