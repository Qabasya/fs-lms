/**
 * Group cockpit — управление программой группы на фронте.
 * Pure-JS function pattern (правило CLAUDE.md для frontend).
 */

export function initGroupCockpit() {
    const cockpit = document.getElementById( 'fs-group-cockpit' );
    if ( ! cockpit ) { return; }

    const groupId    = cockpit.dataset.groupId;
    const subjectKey = cockpit.dataset.subjectKey;
    const vars       = window.fs_lms_cockpit_vars || {};
    const ajaxUrl    = vars.ajax_url || '';
    const actions    = vars.actions || {};
    const nonces     = vars.nonces || {};

    const list = document.getElementById( 'fs-cockpit-lesson-list' );

    // ── Drag-drop reorder ─────────────────────────────────────────
    let dragId = null;

    if ( list ) {
        list.addEventListener( 'dragstart', ( e ) => {
            const item = e.target.closest( '.fs-cockpit-lesson-item' );
            if ( ! item ) { return; }
            dragId = item.dataset.groupLessonId;
            item.classList.add( 'fs-dragging' );
            e.dataTransfer.effectAllowed = 'move';
        } );

        list.addEventListener( 'dragover', ( e ) => {
            e.preventDefault();
            const over = e.target.closest( '.fs-cockpit-lesson-item' );
            if ( ! over || over.dataset.groupLessonId === dragId ) { return; }
            const rect    = over.getBoundingClientRect();
            const after   = e.clientY > rect.top + rect.height / 2;
            const dragged = list.querySelector( `[data-group-lesson-id="${ dragId }"]` );
            if ( dragged ) {
                list.insertBefore( dragged, after ? over.nextSibling : over );
            }
        } );

        list.addEventListener( 'dragend', async ( e ) => {
            const item = e.target.closest( '.fs-cockpit-lesson-item' );
            item?.classList.remove( 'fs-dragging' );
            dragId = null;
            const ids = [ ...list.querySelectorAll( '.fs-cockpit-lesson-item' ) ]
                .map( el => el.dataset.groupLessonId );
            await apiPost( ajaxUrl, {
                action      : actions.reorderProgram || 'reorder_program',
                security    : nonces.saveSchedule,
                group_id    : groupId,
                ordered_ids : ids,
            } );
        } );
    }

    // ── Delegated click ───────────────────────────────────────────
    cockpit.addEventListener( 'click', async ( e ) => {

        // Visibility toggle
        const btnVis = e.target.closest( '.fs-cockpit-btn-visibility' );
        if ( btnVis ) {
            const glId = btnVis.dataset.groupLessonId;
            const item = btnVis.closest( '.fs-cockpit-lesson-item' );
            const cur  = item.dataset.visibility || 'hidden';
            const next = cur === 'hidden' ? 'open' : cur === 'open' ? 'archived' : 'hidden';
            const res  = await apiPost( ajaxUrl, {
                action          : actions.setLessonVisibility,
                security        : nonces.setLessonVisibility,
                group_lesson_id : glId,
                visibility      : next,
            } );
            if ( res?.success ) {
                item.classList.replace(
                    `fs-cockpit-visibility-${ cur }`,
                    `fs-cockpit-visibility-${ next }`
                );
                item.dataset.visibility = next;
                btnVis.classList.replace( `fs-vis-${ cur }`, `fs-vis-${ next }` );
                btnVis.textContent = next;
            }
            return;
        }

        // Remove lesson
        const btnRemove = e.target.closest( '.fs-cockpit-btn-remove' );
        if ( btnRemove ) {
            // eslint-disable-next-line no-alert
            if ( ! confirm( 'Удалить урок из программы?' ) ) { return; }
            const glId = btnRemove.dataset.groupLessonId;
            const res  = await apiPost( ajaxUrl, {
                action          : actions.removeLessonFromProgram,
                security        : nonces.saveSchedule,
                group_lesson_id : glId,
            } );
            if ( res?.success ) {
                btnRemove.closest( '.fs-cockpit-lesson-item' )?.remove();
            }
            return;
        }

        // Assign course
        const btnAssign = e.target.closest( '#fs-btn-assign-course' );
        if ( btnAssign ) {
            const courseId = document.getElementById( 'fs-course-select' )?.value;
            const policy   = document.getElementById( 'fs-assign-policy' )?.value || 'append';
            if ( ! courseId ) { return; }
            btnAssign.disabled = true;
            const res = await apiPost( ajaxUrl, {
                action    : actions.assignCourse,
                security  : nonces.assignCourse,
                group_id  : groupId,
                course_id : courseId,
                policy,
            } );
            btnAssign.disabled = false;
            if ( res?.success ) {
                window.location.reload();
            }
            return;
        }

        // Toggle picker panel
        const btnAdd = e.target.closest( '#fs-btn-add-lesson' );
        if ( btnAdd ) {
            const panel = document.getElementById( 'fs-lesson-picker-panel' );
            if ( panel ) {
                panel.hidden = ! panel.hidden;
                if ( ! panel.hidden ) { fetchPickerLessons(); }
            }
            return;
        }

        // Add lesson from picker
        const btnPick = e.target.closest( '.fs-picker-add-btn' );
        if ( btnPick ) {
            btnPick.disabled = true;
            const res = await apiPost( ajaxUrl, {
                action    : actions.addLessonToProgram,
                security  : nonces.saveSchedule,
                group_id  : groupId,
                lesson_id : btnPick.dataset.lessonId,
            } );
            if ( res?.success ) {
                window.location.reload();
            } else {
                btnPick.disabled = false;
            }
            return;
        }
    } );

    // ── Date input — debounced save ───────────────────────────────
    let dateTimer = null;
    cockpit.addEventListener( 'change', ( e ) => {
        const input = e.target.closest( '.fs-lesson-date' );
        if ( ! input ) { return; }
        clearTimeout( dateTimer );
        dateTimer = setTimeout( async () => {
            await apiPost( ajaxUrl, {
                action          : actions.saveLessonSchedule,
                security        : nonces.saveSchedule,
                group_lesson_id : input.dataset.groupLessonId,
                scheduled_at    : input.value,
            } );
        }, 600 );
    } );

    // ── Lesson picker — search ────────────────────────────────────
    let searchTimer = null;
    const searchInput = document.getElementById( 'fs-picker-search-input' );

    searchInput?.addEventListener( 'input', () => {
        clearTimeout( searchTimer );
        searchTimer = setTimeout( fetchPickerLessons, 400 );
    } );

    document.querySelectorAll( '[name="fs-picker-scope"]' ).forEach( r => {
        r.addEventListener( 'change', fetchPickerLessons );
    } );

    async function fetchPickerLessons() {
        const search = searchInput?.value || '';
        const scope  = document.querySelector( '[name="fs-picker-scope"]:checked' )?.value || 'mine';
        const res    = await apiPost( ajaxUrl, {
            action      : actions.getCourseLessonCandidates,
            security    : nonces.authorCourse,
            subject_key : subjectKey,
            scope,
            search,
        } );
        const results = document.getElementById( 'fs-picker-results' );
        if ( ! results ) { return; }
        results.innerHTML = '';
        const lessons = Array.isArray( res?.data ) ? res.data : [];
        if ( ! lessons.length ) {
            results.innerHTML = `<li class="fs-picker-empty">${ search ? 'Уроков не найдено.' : 'Введите запрос для поиска.' }</li>`;
            return;
        }
        lessons.forEach( lesson => {
            const li  = document.createElement( 'li' );
            li.className = 'fs-picker-result';
            li.innerHTML = `<span class="fs-picker-result-title">${ escHtml( lesson.title ) }</span>
<button class="fs-picker-add-btn" type="button" data-lesson-id="${ lesson.id }">+</button>`;
            results.appendChild( li );
        } );
    }

    // ── Load more activity ────────────────────────────────────────
    const loadMoreBtn = document.getElementById( 'fs-cockpit-load-more' );
    if ( loadMoreBtn ) {
        loadMoreBtn.addEventListener( 'click', async () => {
            const page = parseInt( loadMoreBtn.dataset.page, 10 );
            const res  = await apiPost( ajaxUrl, {
                action   : actions.getGroupActivity,
                security : nonces.saveSchedule,
                group_id : groupId,
                page,
            } );
            if ( res?.data?.events ) {
                const actList = document.querySelector( '.fs-cockpit-activity-list' );
                res.data.events.forEach( ev => {
                    const li = document.createElement( 'li' );
                    li.innerHTML = `<time datetime="${ ev.created_at }">${ ev.created_at }</time> — <span>${ escHtml( ev.action ) }</span>`;
                    actList?.appendChild( li );
                } );
                loadMoreBtn.dataset.page = page + 1;
                if ( res.data.total <= page * 20 ) { loadMoreBtn.remove(); }
            }
        } );
    }
}

function escHtml( str ) {
    return String( str )
        .replace( /&/g, '&amp;' )
        .replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' )
        .replace( /"/g, '&quot;' );
}

async function apiPost( url, data ) {
    const body = new FormData();
    Object.entries( data ).forEach( ( [ k, v ] ) => {
        if ( Array.isArray( v ) ) {
            v.forEach( val => body.append( `${ k }[]`, val ) );
        } else {
            body.append( k, String( v ) );
        }
    } );
    try {
        const res = await fetch( url, { method: 'POST', body } );
        return await res.json();
    } catch {
        return null;
    }
}
