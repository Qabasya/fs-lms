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
	const egeSlots      = parseInt( el.dataset.egeSlots, 10 ) || 0;
	const egeKinds      = JSON.parse( el.dataset.egeKinds || '[]' );
	const taskPointsMap = JSON.parse( el.dataset.taskPoints || '{}' );
	const acts          = fs_lms_vars.ajax_actions;
	const nonces        = fs_lms_vars.nonces;

	const kindSelect = document.querySelector( '.fs-lms-assessment-kind-select' );
	let prevKind     = kindSelect ? kindSelect.value : '';

	const isEge        = ( kind ) => egeKinds.includes( kind );
	const blankSlot    = ( i ) => ( { key: 'slot_' + i, taskId: 0, title: '', points: 0 } );
	const buildEgeSlots = ( count ) => Array.from( { length: count }, ( _, i ) => blankSlot( i ) );

	function toggleKindFields( kind ) {
		const scoreMapRow = document.querySelector( '#score_map' )?.closest( '.fs-lms-field-group' );
		if ( scoreMapRow ) {
			scoreMapRow.style.display = isEge( kind ) ? '' : 'none';
		}
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

		persist: ( slots ) => post( acts.saveAssessmentItems, nonces.authorAssessment, {
			assessment_id: assessmentId,
			item_ids:      slots.map( ( s ) => s.taskId ),
			task_points:   buildTaskPoints( slots ),
		} ),

		search: ( q ) => post( acts.getStepCandidates, nonces.authorLesson, {
			subject_key: subject,
			kind:        'task',
			source:      'bank',
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

		// Баллы за задание — только для ЕГЭ-видов.
		renderExtraBody: ( container, slot, index, api ) => {
			if ( ! isEge( prevKind ) ) { return; }

			const wrap = document.createElement( 'div' );
			wrap.className = 'fs-sb-task-score';

			const label = document.createElement( 'label' );
			label.textContent = 'Баллов за задание:';
			label.htmlFor     = 'fs-sb-points-' + index;

			const input = document.createElement( 'input' );
			input.type      = 'number';
			input.id        = 'fs-sb-points-' + index;
			input.className = 'small-text';
			input.min       = '0';
			input.step      = '0.5';
			input.value     = String( slot.points || 0 );
			input.addEventListener( 'change', () => {
				slot.points = parseFloat( input.value ) || 0;
				api.save();
			} );

			wrap.appendChild( label );
			wrap.appendChild( input );
			container.appendChild( wrap );
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
						if ( egeSlots > 0 ) {
							api.replaceSlots( buildEgeSlots( egeSlots ), 0 );
							api.save();
						}
					} else {
						api.replaceSlots( [], -1 );
					}
				} );
			}

			// Авто-заполнение слотов под число заданий ЕГЭ при первом открытии.
			if ( egeSlots > 0 && api.getSlots().length === 0 && isEge( prevKind ) ) {
				api.replaceSlots( buildEgeSlots( egeSlots ), 0 );
				api.save();
			}
		},
	} );
}
