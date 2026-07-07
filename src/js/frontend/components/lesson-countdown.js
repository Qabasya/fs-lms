/**
 * #3b: обратный отсчёт до начала занятия на экране блокировки урока.
 *
 * Активируется только при наличии [data-lesson-countdown] с data-seconds
 * (сервер отдаёт секунды до старта лишь когда до начала ≤ часа). Тикает раз в
 * секунду; по достижении времени перезагружает страницу — гейт по дате
 * снимется (scheduled_at <= now) и урок откроется.
 */
export function initLessonCountdown() {
    const el = document.querySelector( '[data-lesson-countdown]' );
    if ( ! el ) {
        return;
    }

    let remaining = parseInt( el.getAttribute( 'data-seconds' ), 10 );
    if ( ! Number.isFinite( remaining ) || remaining <= 0 ) {
        window.location.reload();
        return;
    }

    const out = el.querySelector( '[data-countdown-value]' ) || el;

    const pad = ( n ) => String( n ).padStart( 2, '0' );
    const fmt = ( s ) => {
        const h = Math.floor( s / 3600 );
        const m = Math.floor( ( s % 3600 ) / 60 );
        const sec = s % 60;
        return h > 0 ? `${ h }:${ pad( m ) }:${ pad( sec ) }` : `${ pad( m ) }:${ pad( sec ) }`;
    };

    out.textContent = fmt( remaining );

    const tick = setInterval( () => {
        remaining -= 1;
        if ( remaining <= 0 ) {
            clearInterval( tick );
            window.location.reload();
            return;
        }
        out.textContent = fmt( remaining );
    }, 1000 );
}
