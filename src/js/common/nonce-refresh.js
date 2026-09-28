/**
 * Автообновление nonce: запрос с устаревшим токеном повторяется со свежим, без
 * перезагрузки страницы.
 *
 * Токен WordPress живёт не дольше суток и меняется после входа/выхода в другой
 * вкладке. На провал nonce плагина сервер (`Nonce::verify()`) отвечает 403 с кодом
 * `E-SESSION` и свежим токеном: `data.nonce = { field, value }`. Перехватчик
 * `fetch` и `jQuery.ajax` подставляет его в тело и повторяет запрос один раз;
 * повтор помечен заголовком — только его провал сервер пишет в журнал «Ошибки».
 *
 * Токены захвачены вызывающим кодом (конструкторы, замыкания), поэтому пара
 * «старый → свежий» запоминается: следующие запросы со старым токеном уходят
 * сразу со свежим, без лишнего круга.
 *
 * Ставится один раз на окно (флаг на обёртке) — каждый бандл вызывает install
 * у себя, срабатывает первый.
 */

const RETRY_HEADER = 'X-FS-Nonce-Retry';

/** Старый токен → свежий. */
const fresh = new Map();

function isAjaxUrl( url ) {
	return String( url || '' ).includes( 'admin-ajax.php' );
}

/**
 * Свежий токен из ответа сервера, если это отказ по nonce.
 *
 * @param {*} payload Разобранный JSON ответа
 *
 * @returns {{field: string, value: string}|null}
 */
function refreshFrom( payload ) {
	const nonce = payload?.data?.nonce;
	if ( false !== payload?.success || 'E-SESSION' !== payload?.data?.code ) {
		return null;
	}

	return nonce?.field && nonce?.value ? { field: String( nonce.field ), value: String( nonce.value ) } : null;
}

/** Заменяет в URLSearchParams / FormData известные устаревшие токены. */
function swapParams( params ) {
	for ( const [ key, value ] of Array.from( params.entries() ) ) {
		if ( 'string' === typeof value && fresh.has( value ) ) {
			params.set( key, fresh.get( value ) );
		}
	}
}

/**
 * Тело запроса как изменяемый объект: URLSearchParams / FormData — как есть,
 * строка — разбирается. Прочее (JSON, Blob) не трогаем — там нашего nonce нет.
 *
 * @returns {{params: URLSearchParams|FormData, rebuild: function(): *}|null}
 */
function editable( body ) {
	if ( body instanceof URLSearchParams || body instanceof FormData ) {
		return { params: body, rebuild: () => body };
	}
	if ( 'string' === typeof body && ! body.trimStart().startsWith( '{' ) ) {
		const params = new URLSearchParams( body );
		return { params, rebuild: () => params.toString() };
	}

	return null;
}

function remember( params, refresh ) {
	const stale = params.get( refresh.field );
	if ( 'string' === typeof stale && '' !== stale ) {
		fresh.set( stale, refresh.value );
	}
	params.set( refresh.field, refresh.value );
}

function installFetch() {
	if ( 'function' !== typeof window.fetch || window.fetch.fsNonceRefresh ) {
		return;
	}

	const original = window.fetch.bind( window );

	const wrapped = async ( input, init = {} ) => {
		const url  = 'string' === typeof input || input instanceof URL ? String( input ) : input?.url;
		const body = isAjaxUrl( url ) ? editable( init?.body ) : null;
		if ( ! body ) {
			return original( input, init );
		}

		swapParams( body.params );
		const response = await original( input, { ...init, body: body.rebuild() } );
		if ( 403 !== response.status ) {
			return response;
		}

		const refresh = refreshFrom( await response.clone().json().catch( () => null ) );
		if ( ! refresh ) {
			return response;
		}

		remember( body.params, refresh );
		const headers = new Headers( init?.headers || {} );
		headers.set( RETRY_HEADER, '1' );

		return original( input, { ...init, headers, body: body.rebuild() } );
	};

	wrapped.fsNonceRefresh = true;
	window.fetch = wrapped;
}

/** Данные jQuery-запроса: объект, строка или FormData. */
function swapJqueryData( data ) {
	if ( data && 'object' === typeof data && ! ( data instanceof FormData ) && ! ( data instanceof URLSearchParams ) ) {
		const copy = { ...data };
		Object.keys( copy ).forEach( ( key ) => {
			if ( 'string' === typeof copy[ key ] && fresh.has( copy[ key ] ) ) {
				copy[ key ] = fresh.get( copy[ key ] );
			}
		} );
		return copy;
	}

	const body = editable( data );
	if ( body ) {
		swapParams( body.params );
		return body.rebuild();
	}

	return data;
}

function withRefresh( data, refresh ) {
	if ( data && 'object' === typeof data && ! ( data instanceof FormData ) && ! ( data instanceof URLSearchParams ) ) {
		const stale = data[ refresh.field ];
		if ( 'string' === typeof stale && '' !== stale ) {
			fresh.set( stale, refresh.value );
		}
		return { ...data, [ refresh.field ]: refresh.value };
	}

	const body = editable( data ?? '' );
	if ( body ) {
		remember( body.params, refresh );
		return body.rebuild();
	}

	return data;
}

/**
 * Обёртка `jQuery.ajax` (через неё идут и `$.post` / `$.get`). Колбэки
 * success/error/complete из настроек снимаются с первой попытки и вызываются
 * по итогу — иначе отказ по nonce успел бы показать ошибку до повтора.
 */
function installJquery( $ ) {
	if ( ! $?.ajax || $.ajax.fsNonceRefresh ) {
		return;
	}

	const original = $.ajax;

	const wrapped = function ( url, options ) {
		const settings = 'object' === typeof url ? { ...url } : { ...( options || {} ), url };
		if ( ! isAjaxUrl( settings.url ?? $.ajaxSettings.url ) ) {
			return original.call( $, settings );
		}

		const { success, error, complete } = settings;
		const context = settings.context || settings;
		delete settings.success;
		delete settings.error;
		delete settings.complete;
		settings.data = swapJqueryData( settings.data );

		const deferred = $.Deferred();
		let xhr = original.call( $, settings );
		let retried = false;

		const settle = ( request ) => {
			request
				.done( ( data, status, jqXhr ) => deferred.resolveWith( context, [ data, status, jqXhr ] ) )
				.fail( ( jqXhr, status, thrown ) => {
					const refresh = 403 === jqXhr.status && ! retried ? refreshFrom( jqXhr.responseJSON ) : null;
					if ( ! refresh ) {
						deferred.rejectWith( context, [ jqXhr, status, thrown ] );
						return;
					}

					retried = true;
					xhr     = original.call( $, {
						...settings,
						data:    withRefresh( settings.data, refresh ),
						headers: { ...( settings.headers || {} ), [ RETRY_HEADER ]: '1' },
					} );
					settle( xhr );
				} );
		};
		settle( xhr );

		const fire = ( callbacks, args ) => [].concat( callbacks || [] ).forEach( ( fn ) => fn.apply( context, args ) );
		deferred
			.done( ( ...args ) => fire( success, args ) )
			.fail( ( ...args ) => fire( error, args ) )
			// always: у успеха (data, status, jqXHR), у отказа (jqXHR, status, error) — complete ждёт (jqXHR, status).
			.always( ( first, status, third ) => fire( complete, [ 'number' === typeof first?.readyState ? first : third, status ] ) );

		return deferred.promise( { abort: ( reason ) => xhr.abort( reason ) } );
	};

	wrapped.fsNonceRefresh = true;
	$.ajax = wrapped;
}

export function installNonceRefresh() {
	installFetch();
	installJquery( window.jQuery );
}
