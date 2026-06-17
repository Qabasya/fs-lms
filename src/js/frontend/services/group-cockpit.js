/**
 * Group cockpit — управление программой группы на фронте.
 * Pure-JS function pattern (правило CLAUDE.md для frontend).
 */

export function initGroupCockpit() {
    const cockpit = document.getElementById( 'fs-group-cockpit' );
    if ( ! cockpit ) { return; }

    const groupId  = cockpit.dataset.groupId;
    const vars     = window.fs_lms_cockpit_vars || {};
    const ajaxUrl  = vars.ajax_url || '';
    const nonces   = vars.nonces || {};

    // Переключение видимости урока
    cockpit.addEventListener( 'click', async ( e ) => {
        const btnVis = e.target.closest( '.fs-cockpit-btn-visibility' );
        if ( btnVis ) {
            e.preventDefault();
            const glId = btnVis.dataset.groupLessonId;
            const item = btnVis.closest( '.fs-cockpit-lesson-item' );
            const current = item.dataset.visibility || 'hidden';
            const next = current === 'hidden' ? 'open'
                       : current === 'open'   ? 'archived'
                       : 'hidden';
            await apiCall( ajaxUrl, {
                action         : vars.actions?.setLessonVisibility || 'set_lesson_visibility',
                security       : nonces.setLessonVisibility || '',
                group_lesson_id: glId,
                visibility     : next,
            } );
            item.classList.remove( 'fs-cockpit-visibility-' + current );
            item.classList.add( 'fs-cockpit-visibility-' + next );
            item.dataset.visibility = next;
            item.querySelector( '.fs-cockpit-lesson-visibility' ).textContent = next;
            return;
        }

        const btnRemove = e.target.closest( '.fs-cockpit-btn-remove' );
        if ( btnRemove ) {
            e.preventDefault();
            if ( ! confirm( 'Удалить урок из программы?' ) ) { return; }
            const glId = btnRemove.dataset.groupLessonId;
            await apiCall( ajaxUrl, {
                action         : vars.actions?.removeLessonFromProgram || 'remove_lesson_from_program',
                security       : nonces.saveSchedule || '',
                group_lesson_id: glId,
            } );
            btnRemove.closest( '.fs-cockpit-lesson-item' )?.remove();
            return;
        }
    } );

    // Пагинация ленты
    const loadMoreBtn = document.getElementById( 'fs-cockpit-load-more' );
    if ( loadMoreBtn ) {
        loadMoreBtn.addEventListener( 'click', async () => {
            const page = parseInt( loadMoreBtn.dataset.page, 10 );
            const res  = await apiCall( ajaxUrl, {
                action   : vars.actions?.getGroupActivity || 'get_group_activity',
                security : nonces.saveSchedule || '',
                group_id : groupId,
                page     : page,
            } );
            if ( res?.data?.events ) {
                const list = document.querySelector( '.fs-cockpit-activity-list' );
                res.data.events.forEach( ev => {
                    const li = document.createElement( 'li' );
                    li.innerHTML = `<time datetime="${ ev.created_at }">${ ev.created_at }</time> — <span>${ ev.action }</span>`;
                    list?.appendChild( li );
                } );
                loadMoreBtn.dataset.page = page + 1;
                if ( res.data.total <= page * 20 ) {
                    loadMoreBtn.remove();
                }
            }
        } );
    }
}

async function apiCall( url, data ) {
    const body = new FormData();
    Object.entries( data ).forEach( ( [ k, v ] ) => body.append( k, v ) );
    try {
        const res = await fetch( url, { method: 'POST', body } );
        return await res.json();
    } catch {
        return null;
    }
}
