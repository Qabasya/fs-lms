/**
 * Запросы плеера с кодами ошибок (журнал «Ошибки», ErrorCode на сервере).
 *
 * Любой провал превращается в PlayerRequestError с кодом и номером инцидента —
 * пользователь видит их в тосте, по скриншоту запись находится в журнале:
 *
 * - сервер отказал (`success: false`) — код и номер присылает сервер (`fail()`),
 *   у старых обработчиков (`error()`) их нет — тогда E-AJAX без номера;
 * - `-1` / `0` — не прошёл nonce: вкладка висела дольше суток или сменилась сессия (E-SESSION);
 *   сервер уже записал это в журнал сам;
 * - ответ не JSON — фатальная ошибка PHP или 5xx прокси (E-HTTP): сервер этого не видел,
 *   поэтому плеер отправляет отчёт сам;
 * - запрос не дошёл (E-NET).
 */

const vars = window.fs_lms_player_vars;

export class PlayerRequestError extends Error {
	/**
	 * @param {string} message Текст для пользователя
	 * @param {string} code    Код ошибки (E-…, W-…)
	 * @param {string} ref     Номер инцидента ('' — нет)
	 */
	constructor( message, code, ref = '' ) {
		super( message );
		this.code = code;
		this.ref  = ref;
	}

	/** «Срок сдачи истёк. Код W-DEADLINE · #A1B2C3» */
	toUserText() {
		return `${ this.message } Код ${ this.code }${ this.ref ? ` · #${ this.ref }` : '' }`;
	}
}

/**
 * POST на admin-ajax.php.
 *
 * @param {FormData} fd Тело запроса (с action и security)
 *
 * @returns {Promise<*>} `data` успешного ответа
 * @throws {PlayerRequestError}
 */
export async function playerPost( fd ) {
	const action = String( fd.get( 'action' ) || '' );

	let response;
	try {
		response = await fetch( vars.ajax_url, { method: 'POST', body: fd } );
	} catch {
		const err = new PlayerRequestError( 'Нет связи с сервером — проверьте интернет и попробуйте ещё раз.', 'E-NET', newRef() );
		report( err, action, 0, '' );
		throw err;
	}

	const text = await response.text();
	let json;
	try { json = JSON.parse( text ); } catch { json = undefined; }

	// check_ajax_referer() отвечает -1 (или 0) без JSON-обёртки.
	if ( -1 === json || 0 === json ) {
		throw new PlayerRequestError( 'Сессия устарела — обновите страницу, введённые ответы сохранятся.', 'E-SESSION' );
	}

	if ( ! json || 'object' !== typeof json ) {
		const err = new PlayerRequestError( 'Сбой на сервере — попробуйте ещё раз чуть позже.', 'E-HTTP', newRef() );
		report( err, action, response.status, text );
		throw err;
	}

	if ( ! json.success ) {
		const data = json.data;
		const msg  = ( data && 'object' === typeof data ? data.message : data ) || 'Не удалось выполнить действие.';
		throw new PlayerRequestError( String( msg ), data?.code || 'E-AJAX', data?.ref || '' );
	}

	return json.data;
}

/** Номер инцидента для сбоя, который сервер не видел. */
function newRef() {
	const bytes = new Uint8Array( 3 );
	crypto.getRandomValues( bytes );
	return Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' ).toUpperCase();
}

/**
 * Отчёт в журнал «Ошибки». Молча: сам отчёт тоже может не дойти (нет сети).
 *
 * @param {PlayerRequestError} err
 * @param {string}             action  AJAX-действие, которое упало
 * @param {number}             status  HTTP-статус (0 — ответа нет)
 * @param {string}             body    Начало ответа сервера
 */
function report( err, action, status, body ) {
	if ( ! vars?.actions?.reportClientError ) { return; }

	const fd = new FormData();
	fd.append( 'action', vars.actions.reportClientError );
	fd.append( 'security', vars.nonces.reportClientError );
	fd.append( 'code', err.code );
	fd.append( 'ref', err.ref );
	fd.append( 'message', err.message );
	fd.append( 'source_action', action );
	fd.append( 'page_url', window.location.href );
	fd.append( 'status', String( status ) );
	fd.append( 'snippet', String( body || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 ) );

	fetch( vars.ajax_url, { method: 'POST', body: fd } ).catch( () => {} );
}
