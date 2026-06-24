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

		persist: ( slots ) => post( acts.saveWorkItems, nonces.authorWork, {
			work_id:  workId,
			item_ids: slots.map( ( s ) => s.taskId ),
		} ),

		search: ( q ) => post( acts.getWorkItemCandidates, nonces.authorWork, {
			subject_key: subject,
			search:      q,
		} ),

		// Превью задачи переиспользует общий эндпоинт банка задач (нонс контрольной).
		preview: ( taskId ) => post( acts.getTaskPreview, nonces.authorAssessment, {
			task_id:     taskId,
			subject_key: subject,
		} ),

		createTask: ( title ) => post( acts.createProblemDraft, nonces.authorWork, { title } ),
	} );
}
