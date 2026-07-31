import '../_types.js';
import { post } from './slot-builder.js';
import { showToast } from '../modules/toast.js';
import { debounce, escapeHtml, toggleVisible } from '../../common/utils.js';

/* global fs_lms_vars */

/**
 * ScoreMap — помощники поля «Таблица перевода баллов» (ЕГЭ).
 *
 * Разметку даёт PHP-поле {@link ScoreMapField}; здесь только поведение:
 *  - «Скопировать из другого экзамена» — список ЕГЭ-работ предмета с непустой
 *    шкалой (get_score_map_sources) и копирование выбранной (copy_score_map);
 *  - живой разбор вставленного из Excel текста (parse_score_map): сколько пар
 *    распознано и какие строки отброшены — до сохранения поста.
 *
 * Само значение хранится как TSV в textarea; в мету его переводит
 * `ScoreMapField::sanitize()` при сохранении.
 */
export const ScoreMap = {
	init() {
		document.querySelectorAll( '.fs-score-map' ).forEach( mount );
	},
};

/** Массив {первичный: вторичный} → TSV для textarea. */
function mapToTsv( map ) {
	return Object.keys( map )
		.map( Number )
		.sort( ( a, b ) => a - b )
		.map( ( primary ) => `${ primary }\t${ map[ primary ] }` )
		.join( '\n' );
}

function mount( root ) {
	const assessmentId = parseInt( root.dataset.assessmentId, 10 ) || 0;
	const textarea     = root.querySelector( '.fs-lms-score-map-field' );
	const toggle       = root.querySelector( '.fs-score-map__copy-toggle' );
	const panel        = root.querySelector( '.fs-score-map__copy' );
	const select       = root.querySelector( '.fs-score-map__source' );
	const apply        = root.querySelector( '.fs-score-map__apply' );
	const note         = root.querySelector( '.fs-score-map__copy-note' );
	const parsed       = root.querySelector( '.fs-score-map__parsed' );

	if ( ! textarea || ! assessmentId ) { return; }

	const acts   = fs_lms_vars.ajax_actions;
	const nonce  = fs_lms_vars.nonces.scoreMap;
	let   loaded = false;

	// ── Копирование из другого экзамена ──────────────────────────
	toggle?.addEventListener( 'click', async () => {
		const opening = panel.hidden;
		toggleVisible( panel, opening );

		if ( ! opening || loaded ) { return; }
		loaded = true;

		try {
			const data    = await post( acts.getScoreMapSources, nonce, { assessment_id: assessmentId } );
			const sources = data.sources || [];

			if ( ! sources.length ) {
				select.innerHTML = '<option value="">Нет работ ЕГЭ с заполненной шкалой</option>';
				note.textContent = 'Скопировать не из чего: у других работ предмета шкала пуста.';
				return;
			}

			select.innerHTML = '<option value="">— выберите работу —</option>' + sources.map( ( s ) =>
				`<option value="${ s.id }">${ escapeHtml( s.title ) } (${ s.pairs } пар, ${ escapeHtml( s.range ) })</option>`
			).join( '' );
			select.disabled = false;
		} catch ( err ) {
			loaded           = false;
			select.innerHTML = '<option value="">Ошибка загрузки</option>';
			showToast( String( err ), 'error' );
		}
	} );

	select?.addEventListener( 'change', () => {
		apply.disabled = '' === select.value;
	} );

	apply?.addEventListener( 'click', async () => {
		const sourceId = parseInt( select.value, 10 ) || 0;
		if ( ! sourceId ) { return; }

		apply.disabled = true;
		try {
			const data = await post( acts.copyScoreMap, nonce, {
				source_assessment_id: sourceId,
				target_assessment_id: assessmentId,
			} );

			textarea.value = mapToTsv( data.map || {} );
			renderParsed( Object.keys( data.map || {} ).length );
			// Серверный обработчик уже записал шкалу в мету — поле лишь показывает результат.
			showToast( 'Шкала скопирована и сохранена', 'success' );
			toggleVisible( panel, false );
		} catch ( err ) {
			showToast( String( err ), 'error' );
		} finally {
			apply.disabled = false;
		}
	} );

	// ── Живой разбор вставленного текста ─────────────────────────
	function renderParsed( count ) {
		if ( ! parsed ) { return; }
		parsed.textContent = count
			? `Распознано пар: ${ count }.`
			: 'Пары не распознаны — нужны два числовых столбца: первичный [Tab] вторичный.';
	}

	const reparse = debounce( async () => {
		const text = textarea.value.trim();
		if ( '' === text ) {
			if ( parsed ) { parsed.textContent = ''; }
			return;
		}

		try {
			const data = await post( acts.parseScoreMap, nonce, { text } );
			renderParsed( Object.keys( data.map || {} ).length );
		} catch {
			// Разбор — подсказка, а не валидация: молча пропускаем сетевую ошибку.
		}
	}, 400 );

	textarea.addEventListener( 'input', reparse );
	if ( '' !== textarea.value.trim() ) {
		renderParsed( textarea.value.trim().split( /\r\n|\r|\n/ ).length );
	}
}
