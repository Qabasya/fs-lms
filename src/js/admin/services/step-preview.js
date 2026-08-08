import { escapeHtml as esc } from '../../common/utils.js';
import { ajax, acts } from './step-ajax.js';

/**
 * step-preview.js — превью ссылочного контента шага (задача / работа / экзамен):
 * условие, варианты ответа, решение, подсказка. Вынесено из step-editor.js
 * без изменения поведения; потребитель — редактор ссылочного шага.
 */

function buildAnswerSection( data ) {
	const lbl = ( t ) => '<div class="fs-cb-tp-label">' + t + '</div>';

	if ( data.options && Array.isArray( data.options.options ) && data.options.options.length ) {
		const html = data.options.options.map( ( o ) =>
			'<div class="fs-cb-tp-option' + ( o.correct ? ' is-correct' : '' ) + '">' +
			'<span class="fs-cb-tp-opt-mark">' + ( o.correct ? '✓' : '·' ) + '</span>' +
			'<span>' + esc( String( o.text || '' ) ) + '</span>' +
			'</div>'
		).join( '' );
		return lbl( 'Варианты ответа' ) + '<div class="fs-cb-tp-options">' + html + '</div>';
	}

	if ( data.pairs && Array.isArray( data.pairs.pairs ) && data.pairs.pairs.length ) {
		const html = data.pairs.pairs.map( ( p ) =>
			'<div class="fs-cb-tp-pair">' +
			'<span class="fs-cb-tp-pair-l">' + esc( String( p.left || '' ) ) + '</span>' +
			'<span class="fs-cb-tp-pair-arrow">→</span>' +
			'<span class="fs-cb-tp-pair-r">' + esc( String( p.right || '' ) ) + '</span>' +
			'</div>'
		).join( '' );
		return lbl( 'Сопоставление' ) + '<div class="fs-cb-tp-pairs">' + html + '</div>';
	}

	if ( data.order_items && Array.isArray( data.order_items.items ) && data.order_items.items.length ) {
		const html = data.order_items.items.map( ( item ) => '<li>' + esc( String( item ) ) + '</li>' ).join( '' );
		return lbl( 'Порядок элементов' ) + '<ol class="fs-cb-tp-order">' + html + '</ol>';
	}

	if ( data.gap_text ) {
		const processed = esc( data.gap_text ).replace( /\[\[([^\]]+)\]\]/g, '<span class="fs-cb-tp-gap-fill">$1</span>' );
		return lbl( 'Текст с пропусками' ) + '<div class="fs-cb-tp-gap">' + processed + '</div>';
	}

	if ( Array.isArray( data.three_in_one ) && data.three_in_one.length ) {
		const html = data.three_in_one.map( ( sub, i ) =>
			'<div class="fs-cb-tp-subtask">' +
			'<div class="fs-cb-tp-subtask-num">Подзадание ' + ( i + 1 ) + '</div>' +
			( sub.condition ? '<div class="fs-cb-tp-subtask-cond">' + sub.condition + '</div>' : '' ) +
			( sub.answer ? '<div class="fs-cb-tp-subtask-ans">' + esc( sub.answer ) + '</div>' : '' ) +
			'</div>'
		).join( '' );
		return lbl( 'Подзадания' ) + '<div class="fs-cb-tp-subtasks">' + html + '</div>';
	}

	if ( data.answer_html ) {
		return lbl( 'Ответ' ) + '<div class="fs-cb-tp-body fs-cb-tp-answer">' + data.answer_html + '</div>';
	}

	return '';
}

function buildRefTaskBody( task ) {
	let html = '';
	if ( task.condition_html ) {
		html += '<div class="fs-cb-tp-section"><div class="fs-cb-tp-label">Условие</div><div class="fs-cb-tp-body">' + task.condition_html + '</div></div>';
	}
	const ans = buildAnswerSection( task );
	if ( ans ) { html += '<div class="fs-cb-tp-section">' + ans + '</div>'; }
	return html || '<div class="fs-cb-tp-section"><div class="fs-cb-tp-loading">Нет содержимого</div></div>';
}

export function loadRefPreview( container, refId, type ) {
	container.innerHTML = '<div class="fs-cb-tp-loading">Загрузка задач…</div>';
	ajax( acts().getRefPreview, { ref_id: refId, ref_type: type } )
		.then( ( data ) => {
			if ( ! data.tasks || ! data.tasks.length ) {
				container.innerHTML = '<div class="fs-cb-tp-loading">Задачи не добавлены</div>';
				return;
			}
			let html = '<div class="fs-modal-accordion">';
			data.tasks.forEach( ( task, i ) => {
				html +=
					'<div class="fs-modal-accordion__item">' +
					'<button type="button" class="fs-modal-accordion__header" aria-expanded="false">' +
					'<h3>' + ( i + 1 ) + '. ' + esc( task.title ) + '</h3>' +
					'<span class="dashicons dashicons-arrow-down-alt2"></span>' +
					'</button>' +
					'<div class="fs-modal-accordion__body" hidden>' + buildRefTaskBody( task ) + '</div>' +
					'</div>';
			} );
			html += '</div>';
			container.innerHTML = html;
			container.querySelectorAll( '.fs-modal-accordion__header' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
					btn.setAttribute( 'aria-expanded', String( ! expanded ) );
					btn.nextElementSibling.hidden = expanded;
				} );
			} );
		} )
		.catch( () => {
			container.innerHTML = '<div class="fs-cb-tp-loading">Ошибка загрузки</div>';
		} );
}

export function loadTaskPreview( container, taskId ) {
	const box = container.querySelector( '[data-task-preview]' );
	if ( ! box ) { return; }
	box.innerHTML = '<div class="fs-cb-tp-loading">…</div>';
	ajax( acts().getTaskPreview, { task_id: taskId } )
		.then( ( data ) => renderTaskPreview( box, data ) )
		.catch( () => { box.innerHTML = ''; } );
}

function renderTaskPreview( box, data ) {
	const parts = [];

	if ( data.condition_html ) {
		parts.push(
			'<div class="fs-cb-tp-section">' +
			'<div class="fs-cb-tp-label">Условие</div>' +
			'<div class="fs-cb-tp-body">' + data.condition_html + '</div>' +
			'</div>'
		);
	}

	if ( data.audio_url ) {
		parts.push(
			'<div class="fs-cb-tp-section">' +
			'<audio controls class="fs-cb-tp-audio" src="' + esc( data.audio_url ) + '"></audio>' +
			'</div>'
		);
	}

	const answerSec = buildAnswerSection( data );
	if ( answerSec ) { parts.push( '<div class="fs-cb-tp-section">' + answerSec + '</div>' ); }

	if ( data.solution_html ) {
		parts.push(
			'<div class="fs-cb-tp-section">' +
			'<div class="fs-cb-tp-label">Решение</div>' +
			'<div class="fs-cb-tp-body">' + data.solution_html + '</div>' +
			'</div>'
		);
	}

	if ( data.hint_html ) {
		parts.push(
			'<div class="fs-cb-tp-section">' +
			'<div class="fs-cb-tp-label">Подсказка</div>' +
			'<div class="fs-cb-tp-body">' + data.hint_html + '</div>' +
			'</div>'
		);
	}

	box.innerHTML = parts.join( '' );
}
