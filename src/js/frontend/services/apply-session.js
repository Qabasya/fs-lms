/**
 * Метка формы и nonce приходят из отдельного запроса: HTML страницы может часами
 * оставаться в кеше, а подписанная метка годна лишь четыре часа.
 */
export function createApplySession( vars, request = fetch, now = () => performance.now(), wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) ) ) {
    const refreshAfter = 3 * 60 * 60 * 1000;
    const minimumAge = 3200;
    let issuedAt = null;
    let pending = null;

    async function refresh() {
        if ( pending ) { return pending; }

        pending = ( async () => {
            const body = new URLSearchParams( { action: vars.actions.session } );
            const response = await request( vars.ajax_url, { method: 'POST', body, cache: 'no-store' } );
            const result = await response.json();
            const data = result?.data;

            if ( ! result?.success || ! data?.form_token || ! data?.nonces?.apply || ! data?.nonces?.verify_otp || ! data?.nonces?.check_username || ! data?.nonces?.track ) {
                throw new Error( 'Не удалось обновить данные формы.' );
            }

            vars.form_token = data.form_token;
            Object.assign( vars.nonces, data.nonces );
            issuedAt = now();
        } )();

        try {
            await pending;
        } finally {
            pending = null;
        }
    }

    async function ensureFresh() {
        if ( pending ) { await pending; }
        if ( null === issuedAt || now() - issuedAt >= refreshAfter ) {
            await refresh();
        }
    }

    async function prepareOtp() {
        await ensureFresh();
        const remaining = minimumAge - ( now() - issuedAt );
        if ( remaining > 0 ) { await wait( remaining ); }
    }

    return { ensureFresh, prepareOtp };
}
