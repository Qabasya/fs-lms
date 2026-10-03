import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createApplySession } from '../../src/js/frontend/services/apply-session.js';

function fixture() {
    let clock = 0;
    let calls = 0;
    const waits = [];
    const vars = {
        ajax_url: '/wp-admin/admin-ajax.php',
        actions: { session: 'get_apply_session' },
        form_token: 'old-token-from-cached-page',
        nonces: { apply: 'old', verify_otp: 'old', check_username: 'old', track: 'old' },
    };
    const request = async ( url, options ) => {
        calls++;
        assert.equal( url, vars.ajax_url );
        assert.equal( options.body.get( 'action' ), vars.actions.session );
        assert.equal( options.cache, 'no-store' );
        return {
            async json() {
                return {
                    success: true,
                    data: {
                        form_token: `fresh-${ calls }`,
                        nonces: { apply: 'new', verify_otp: 'new', check_username: 'new', track: 'new' },
                    },
                };
            },
        };
    };
    const wait = async ( ms ) => { waits.push( ms ); clock += ms; };
    const session = createApplySession( vars, request, () => clock, wait );
    return { vars, session, waits, advance: ( ms ) => { clock += ms; }, calls: () => calls };
}

test( 'кешированная форма получает свежую метку и ждёт минимальное время перед OTP', async () => {
    const { vars, session, waits, calls } = fixture();
    await session.prepareOtp();
    assert.equal( calls(), 1 );
    assert.equal( vars.form_token, 'fresh-1' );
    assert.equal( vars.nonces.apply, 'new' );
    assert.deepEqual( waits, [ 3200 ] );
} );

test( 'метка обновляется после долгого ожидания, но не на каждом запросе', async () => {
    const { session, advance, waits, calls } = fixture();
    await session.ensureFresh();
    advance( 10 * 1000 );
    await session.prepareOtp();
    assert.equal( calls(), 1 );
    assert.deepEqual( waits, [] );

    advance( 3 * 60 * 60 * 1000 );
    await session.prepareOtp();
    assert.equal( calls(), 2 );
    assert.deepEqual( waits, [ 3200 ] );
} );

test( 'ответ без полной сессии не заменяет рабочие значения и следующая попытка повторяет запрос', async () => {
    const vars = { ajax_url: '/ajax', actions: { session: 'get_apply_session' }, form_token: 'old', nonces: { apply: 'old' } };
    let calls = 0;
    const session = createApplySession( vars, async () => {
        calls++;
        return { json: async () => ( { success: false } ) };
    } );

    await assert.rejects( session.ensureFresh(), /Не удалось обновить/ );
    await assert.rejects( session.ensureFresh(), /Не удалось обновить/ );
    assert.equal( calls, 2 );
    assert.equal( vars.form_token, 'old' );
    assert.equal( vars.nonces.apply, 'old' );
} );

test( 'одновременная инициализация и отправка используют один запрос за сессией', async () => {
    const vars = { ajax_url: '/ajax', actions: { session: 'get_apply_session' }, form_token: 'old', nonces: {} };
    let finish;
    let calls = 0;
    const request = () => {
        calls++;
        return new Promise( ( resolve ) => { finish = resolve; } );
    };
    const session = createApplySession( vars, request, () => 0, async () => {} );
    const first = session.ensureFresh();
    const second = session.prepareOtp();
    finish( { json: async () => ( {
        success: true,
        data: {
            form_token: 'new',
            nonces: { apply: 'a', verify_otp: 'b', check_username: 'c', track: 'd' },
        },
    } ) } );

    await Promise.all( [ first, second ] );
    assert.equal( calls, 1 );
    assert.equal( vars.form_token, 'new' );
} );
