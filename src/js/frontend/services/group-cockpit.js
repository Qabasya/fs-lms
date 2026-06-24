/**
 * Group cockpit — управление программой группы на фронте.
 * Pure-JS function pattern (правило CLAUDE.md для frontend).
 */

import { confirmDialog } from '../../common/components/confirm-dialog.js';

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
            if ( ! await confirmDialog( 'Удалить урок из программы?' ) ) { return; }
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
            const label = document.getElementById( 'fs-picker-label-input' )?.value || '';
            const res = await apiPost( ajaxUrl, {
                action    : actions.addLessonToProgram,
                security  : nonces.saveSchedule,
                group_id  : groupId,
                lesson_id : btnPick.dataset.lessonId,
                label,
            } );
            if ( res?.success ) {
                window.location.reload();
            } else {
                btnPick.disabled = false;
            }
            return;
        }

        // Step settings panel
        const btnStepSettings = e.target.closest( '.fs-cockpit-btn-step-settings' );
        if ( btnStepSettings ) {
            const item = btnStepSettings.closest( '.fs-cockpit-lesson-item' );
            await toggleStepSettingsPanel( item, item.dataset.groupLessonId );
            return;
        }

        // Save step settings
        const btnSaveSettings = e.target.closest( '.fs-step-settings-save' );
        if ( btnSaveSettings ) {
            const panel = btnSaveSettings.closest( '.fs-step-settings-panel' );
            await saveStepSettings( panel, panel.dataset.groupLessonId );
            return;
        }

        // Ответы учеников по шагам
        const btnAnswers = e.target.closest( '.fs-cockpit-btn-answers' );
        if ( btnAnswers ) {
            const item = btnAnswers.closest( '.fs-cockpit-lesson-item' );
            await toggleAnswersPanel( item, item.dataset.groupLessonId );
            return;
        }

        // Дублировать lesson — провести ещё раз на другую дату
        const btnDup = e.target.closest( '.fs-cockpit-btn-duplicate' );
        if ( btnDup ) {
            btnDup.disabled = true;
            const res = await apiPost( ajaxUrl, {
                action          : actions.duplicateProgramLesson,
                security        : nonces.saveSchedule,
                group_lesson_id : btnDup.dataset.groupLessonId,
            } );
            if ( res?.success ) {
                window.location.reload();
            } else {
                btnDup.disabled = false;
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
            const subjectTag = lesson.subject_name
                ? `<span class="fs-picker-result-subject">${ escHtml( lesson.subject_name ) }</span>`
                : '';
            li.innerHTML = `<span class="fs-picker-result-title">${ escHtml( lesson.title ) }</span>${ subjectTag }
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

async function toggleStepSettingsPanel( item, glId ) {
    let panel = item.querySelector( '.fs-step-settings-panel' );
    if ( panel ) {
        panel.hidden = ! panel.hidden;
        return;
    }
    const res = await apiPost( ajaxUrl, {
        action          : actions.getStepSettings,
        security        : nonces.stepSettings,
        group_lesson_id : glId,
    } );
    if ( ! res?.success ) { return; }
    panel = renderStepSettingsPanel( glId, res.data?.steps ?? [] );
    item.appendChild( panel );
}

function renderStepSettingsPanel( glId, steps ) {
    const panel = document.createElement( 'div' );
    panel.className = 'fs-step-settings-panel';
    panel.dataset.groupLessonId = glId;

    if ( ! steps.length ) {
        panel.innerHTML = '<p class="fs-step-settings-empty">В этом уроке нет заданий.</p>';
        return panel;
    }

    steps.forEach( step => {
        const eff = step.override ?? step.settings;
        const row = document.createElement( 'div' );
        row.className = 'fs-step-settings-row';
        row.dataset.stepKey = step.key;
        row.innerHTML = `
<strong class="fs-step-settings-label">${ escHtml( step.label ) }</strong>
<label class="fs-step-settings-field">
    <span>Попыток <small>(0 = ∞)</small></span>
    <input type="number" class="fs-ss-attempts" min="0" value="${ eff.max_attempts ?? 0 }">
</label>
<label class="fs-step-settings-field">
    <span>Перемешать варианты</span>
    <input type="checkbox" class="fs-ss-shuffle"${ eff.shuffle ? ' checked' : '' }>
</label>
<label class="fs-step-settings-field">
    <span>Подсказка после N ошибок <small>(0 = сразу)</small></span>
    <input type="number" class="fs-ss-hint-after" min="0" value="${ eff.hint_after_errors ?? 0 }">
</label>`;
        panel.appendChild( row );
    } );

    const footer = document.createElement( 'div' );
    footer.className = 'fs-step-settings-footer';
    footer.innerHTML = '<button type="button" class="fs-step-settings-save fs-cockpit-btn-primary">Сохранить</button>'
        + '<span class="fs-step-settings-status" aria-live="polite"></span>';
    panel.appendChild( footer );

    return panel;
}

async function saveStepSettings( panel, glId ) {
    const overrides = {};
    panel.querySelectorAll( '.fs-step-settings-row' ).forEach( row => {
        overrides[ row.dataset.stepKey ] = {
            max_attempts      : parseInt( row.querySelector( '.fs-ss-attempts' ).value, 10 ) || 0,
            shuffle           : row.querySelector( '.fs-ss-shuffle' ).checked,
            hint_after_errors : parseInt( row.querySelector( '.fs-ss-hint-after' ).value, 10 ) || 0,
        };
    } );

    const btn    = panel.querySelector( '.fs-step-settings-save' );
    const status = panel.querySelector( '.fs-step-settings-status' );
    if ( btn ) { btn.disabled = true; }

    const res = await apiPost( ajaxUrl, {
        action          : actions.saveStepSettings,
        security        : nonces.stepSettings,
        group_lesson_id : glId,
        overrides       : JSON.stringify( overrides ),
    } );

    if ( btn ) { btn.disabled = false; }
    if ( status ) { status.textContent = res?.success ? 'Сохранено ✓' : 'Ошибка при сохранении'; }
}

async function toggleAnswersPanel( item, glId ) {
    let panel = item.querySelector( '.fs-answers-panel' );
    if ( panel ) {
        panel.hidden = ! panel.hidden;
        return;
    }
    const res = await apiPost( ajaxUrl, {
        action          : actions.getStepSettings,
        security        : nonces.stepSettings,
        group_lesson_id : glId,
    } );
    const steps = res?.data?.steps ?? [];

    panel = document.createElement( 'div' );
    panel.className = 'fs-answers-panel';
    panel.dataset.groupLessonId = glId;

    if ( ! steps.length ) {
        panel.innerHTML = '<p class="fs-answers-empty">В этом уроке нет заданий.</p>';
        item.appendChild( panel );
        return;
    }

    steps.forEach( step => {
        const section = document.createElement( 'div' );
        section.className = 'fs-answers-step';
        section.innerHTML = `<strong class="fs-answers-step-label">${ escHtml( step.label ) }</strong>
            <button type="button" class="fs-answers-load-btn fs-cockpit-btn-secondary"
                data-step-key="${ escHtml( step.key ) }">Загрузить ответы</button>
            <div class="fs-answers-list"></div>`;
        section.querySelector( '.fs-answers-load-btn' ).addEventListener( 'click', async function () {
            this.disabled = true;
            const r = await apiPost( ajaxUrl, {
                action          : actions.getTaskAttempts,
                security        : nonces.stepSettings,
                group_lesson_id : glId,
                step_key        : step.key,
            } );
            const list = section.querySelector( '.fs-answers-list' );
            if ( ! r?.success || ! r.data.length ) {
                list.innerHTML = '<p class="fs-answers-none">Ответов нет.</p>';
                return;
            }
            list.innerHTML = r.data.map( student => `
                <div class="fs-answers-student">
                    <span class="fs-answers-student-name">${ escHtml( student.student_name ) }</span>
                    <ul class="fs-answers-attempts">
                        ${ student.attempts.map( ( a, i ) => `
                            <li class="fs-answers-attempt${ a.is_correct ? ' fs-answers-correct' : ( null === a.is_correct ? '' : ' fs-answers-wrong' ) }">
                                #${ a.attempt_number } — ${ null === a.is_correct ? 'На проверке' : ( a.is_correct ? '✓' : '✗' ) }
                                ${ null !== a.score ? `(${ a.score }/${ a.max_score })` : '' }
                                <small>${ escHtml( a.created_at ) }</small>
                            </li>`
                        ).join( '' ) }
                    </ul>
                </div>` ).join( '' );
        } );
        panel.appendChild( section );
    } );

    item.appendChild( panel );
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
