/* ══════════════════════════════════════════════════════════════════════
   Общая модалка индивидуального занятия (B2). Одно окно для создания и правки.
   Поля: группа, ученик, дата, время, кабинет, тема (урок банка → lesson_id).
   Часть полей фиксируется по контексту:
     • из «Группы»       — фиксированы группа и ученик (дата выбирается);
     • из календаря КТП  — фиксирована дата ячейки (группа/ученик выбираются);
     • правка слота      — группа фиксирована, остальное редактируется.
   Тема — выбор урока (lesson_id), не свободный текст (решение по B2).

   Режим `multi` — дополнительное занятие вне расписания для части группы: группа фиксирована,
   ученики — флажками из её состава, тема — только из курса группы; на каждого ученика
   создаётся своё занятие (`student_person_ids[]`).

   Режим `extra` — правка уже созданного дополнительного занятия (карточка календаря КТП): те же поля,
   состав учеников и тема предзаполнены, сохранение правит занятие целиком (`updateExtra`).
   `extra` = { extra_key, group_id, scheduled_at, ends_at, room_id, room, lesson_id, students:[{person_id,name}] }.

   Вызов: openIndiModal({ api, anchor, groups, multi?, extra?, fixed:{date?,group?,student?},
                          edit?:{glid,group_id,student_person_id,student_name,
                                 date,time,room_id,room_name,lesson_id}, onSaved }).
   `api` — createApi(cfg) вызывающего экрана; его конфиг должен содержать действия
   getRoster / getFreeRooms / lessonCandidates / createIndividual / updateIndividual (+ updateExtra для `extra`).
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, todayIso, openGradePopPositioned, closeGradePop, fmtDate, openCtxMenuRaw } from './utils.js';

export function openIndiModal( o ) {
    const pop = document.getElementById( 'profGradePop' );
    if ( ! pop ) { return; }

    const api    = o.api;
    const edit   = o.edit || null;
    const extra  = o.extra || null;
    const fx     = o.fixed || {};
    const groups = o.groups || [];

    const initGroup   = edit ? edit.group_id : ( extra ? extra.group_id : ( fx.group ? fx.group.id : ( groups[ 0 ] && groups[ 0 ].id ) ) );
    const initStudent = edit ? edit.student_person_id : ( fx.student ? fx.student.person_id : 0 );
    const initDate    = edit ? edit.date : ( extra ? datePart( extra.scheduled_at ) : ( fx.date || todayIso() ) );
    const initTime    = edit ? ( edit.time || '15:00' ) : ( extra ? ( timePart( extra.scheduled_at ) || '15:00' ) : '15:00' );
    const initTimeEnd = edit ? ( edit.time_end || addHour( initTime ) ) : ( extra ? ( timePart( extra.ends_at ) || addHour( initTime ) ) : addHour( initTime ) );
    const initRoom    = edit ? String( edit.room_id || '' ) : ( extra ? String( extra.room_id || '' ) : '' );
    const initLesson  = edit ? String( edit.lesson_id || '' ) : ( extra ? String( extra.lesson_id || '' ) : '' );

    const multi        = ( !! o.multi || !! extra ) && ! edit; // доп. занятие для части группы
    const groupFixed   = !! fx.group || !! edit || !! extra;   // группа не меняется
    const studentFixed = !! fx.student;            // ученик фиксирован (из «Группы»)
    const dateFixed    = !! fx.date && ! edit && ! extra; // дата фиксирована (ячейка календаря)

    pop.innerHTML = `
        <div class="gp-form gp-indi">
        <div class="gp-title">${ edit ? 'Правка занятия' : ( extra ? 'Правка доп. занятия' : ( multi ? 'Доп. занятие' : 'Инд. занятие' ) ) }</div>
        ${ groupFixed
            ? `<input type="hidden" id="giGroup" value="${ initGroup }">`
            : `<label class="gp-field"><span>Группа</span><select id="giGroup">${
                groups.map( g => `<option value="${ g.id }" ${ g.id === initGroup ? 'selected' : '' }>${ esc( g.name ) }</option>` ).join( '' )
            }</select></label>` }
        ${ multi
            ? `<div class="gp-field gp-field--students"><span>Ученики</span><div class="gp-multi" id="giStudents"><em>загрузка…</em></div></div>`
            : studentFixed
            ? `<div class="gp-field"><span>Ученик</span><b class="gp-fixed">${ esc( fx.student.name || '' ) }</b><input type="hidden" id="giStudent" value="${ initStudent }"></div>`
            : `<label class="gp-field"><span>Ученик</span><select id="giStudent"><option value="">— загрузка… —</option></select></label>` }
        ${ dateFixed
            ? `<div class="gp-field"><span>Дата</span><b class="gp-fixed">${ esc( fmtDate( initDate ) ) }</b><input type="hidden" id="giDate" value="${ initDate }"></div>`
            : `<label class="gp-field"><span>Дата</span><input type="date" id="giDate" value="${ initDate }"></label>` }
        <div class="gp-field"><span>Время</span><div class="gp-time"><input type="time" id="giTime" value="${ initTime }"><span class="gp-dash">–</span><input type="time" id="giTimeEnd" value="${ initTimeEnd }"></div></div>
        <label class="gp-field"><span>Кабинет</span><select id="giRoom"><option value="">Без кабинета</option></select></label>
        <label class="gp-field"><span>Тема</span><select id="giLesson"><option value="">— без темы —</option></select></label>
        <div class="gp-row">
            <button class="prof-btn prof-btn-sm prof-btn-primary" data-gi="save">${ edit || extra ? 'Сохранить' : 'Создать' }</button>
            <button class="prof-btn prof-btn-sm" data-gi="cancel">Отмена</button>
        </div>
        </div>`;

    const $ = sel => pop.querySelector( sel );
    const groupId     = () => Number( $( '#giGroup' ).value ) || initGroup;
    const scheduledAt = () => {
        const date = $( '#giDate' ).value;
        const time = $( '#giTime' ).value || '15:00';
        return date ? `${ date } ${ time }:00` : '';
    };
    const endsAt = () => {
        const date = $( '#giDate' ).value;
        const time = $( '#giTimeEnd' ).value;
        return date && time ? `${ date } ${ time }:00` : '';
    };

    // Свободные кабинеты по предмету группы + окну времени; текущий кабинет правки
    // добавляем принудительно (он «занят» самим этим занятием).
    const loadRooms = async () => {
        const sel = $( '#giRoom' );
        const at  = scheduledAt();
        if ( ! at ) { sel.innerHTML = '<option value="0">Без кабинета</option>'; return; }
        sel.innerHTML = '<option value="">— загрузка… —</option>';
        try {
            const d = await api( 'getFreeRooms', { group_id: groupId(), scheduled_at: at, ends_at: endsAt() } );
            const rooms = ( d && d.rooms ) || [];
            sel.innerHTML = '<option value="0">Без кабинета</option>' +
                rooms.map( r => `<option value="${ r.id }" ${ String( r.id ) === initRoom ? 'selected' : '' }>${ esc( r.name ) }</option>` ).join( '' );
            if ( ( edit || extra ) && initRoom && ! sel.querySelector( `option[value="${ initRoom }"]` ) ) {
                sel.insertAdjacentHTML( 'beforeend', `<option value="${ initRoom }" selected>${ esc( ( edit || extra ).room_name || ( edit || extra ).room || 'Кабинет' ) }</option>` );
            }
        } catch { sel.innerHTML = '<option value="0">Без кабинета</option>'; }
    };

    // Темы (уроки предмета группы, курс-первыми).
    const loadLessons = async () => {
        const sel = $( '#giLesson' );
        sel.innerHTML = '<option value="">— загрузка… —</option>';
        try {
            const d = await api( 'lessonCandidates', multi ? { group_id: groupId(), course_only: 1 } : { group_id: groupId() } );
            const lessons = ( d && d.lessons ) || [];
            sel.innerHTML = '<option value="">— без темы —</option>' +
                lessons.map( l => `<option value="${ l.id }" ${ String( l.id ) === initLesson ? 'selected' : '' }>${ esc( l.title || 'Без названия' ) }</option>` ).join( '' );
        } catch { sel.innerHTML = '<option value="">— без темы —</option>'; }
    };

    // Ученики выбранной группы (если ученик не фиксирован).
    const loadStudents = async () => {
        if ( multi ) { return loadStudentChecks(); }
        if ( studentFixed ) { return; }
        const sel = $( '#giStudent' );
        sel.innerHTML = '<option value="">— загрузка… —</option>';
        try {
            const d = await api( 'getRoster', { group_id: groupId() } );
            const students = ( d && d.students ) || [];
            sel.innerHTML = students.length
                ? students.map( s => `<option value="${ s.person_id }" ${ s.person_id === initStudent ? 'selected' : '' }>${ esc( s.name ) }</option>` ).join( '' )
                : '<option value="">— нет учеников —</option>';
        } catch { sel.innerHTML = '<option value="">— ошибка —</option>'; }
    };

    // Ученики группы: кнопка открывает меню (prof-ctx-menu) — у каждого имени справа флажок, первая строка «Все».
    // Выбор хранится в `chosen` и применяется при закрытии меню, как в выборе сеансов ссылки (exam-sources).
    const chosen = new Set( extra ? extra.students.map( s => Number( s.person_id ) ) : [] );
    let roster   = [];
    const summary = () => {
        const n = chosen.size;
        return 0 === n ? 'Выберите учеников' : ( n === roster.length ? 'Все ученики' : `Выбрано: ${ n } из ${ roster.length }` );
    };
    const pickStudents = btn => {
        const row = ( attrs, name ) => `<label class="ctx-item ctx-item--multi"><span class="ctx-lbl">${ name }</span><input type="checkbox" ${ attrs }></label>`;
        openCtxMenuRaw(
            row( 'data-gi="all"', 'Все' ) + roster.map( s => row( `data-gi="stu" value="${ s.person_id }" ${ chosen.has( s.person_id ) ? 'checked' : '' }`, esc( s.name ) ) ).join( '' ),
            btn,
            () => {
                chosen.clear();
                document.querySelectorAll( '#profCtxMenu [data-gi="stu"]:checked' ).forEach( c => chosen.add( Number( c.value ) ) );
                btn.querySelector( '[data-gi="summary"]' ).textContent = summary();
            }
        );
        const menu   = document.getElementById( 'profCtxMenu' );
        menu.classList.toggle( 'prof-ctx-menu--scroll', roster.length > 12 );
        const all    = menu.querySelector( '[data-gi="all"]' );
        const checks = Array.from( menu.querySelectorAll( '[data-gi="stu"]' ) );
        const sync   = () => { all.checked = checks.every( c => c.checked ); };
        all.addEventListener( 'change', () => { checks.forEach( c => { c.checked = all.checked; } ); } );
        checks.forEach( c => c.addEventListener( 'change', sync ) );
        sync();
    };
    const loadStudentChecks = async () => {
        const box = $( '#giStudents' );
        try {
            const d = await api( 'getRoster', { group_id: groupId() } );
            roster = ( d && d.students ) || [];
            if ( ! roster.length ) { box.innerHTML = '<em>В группе нет учеников</em>'; return; }
            box.innerHTML = `<button type="button" class="gp-multi-btn" data-gi="students"><span data-gi="summary">${ summary() }</span><span class="gp-multi-caret">▾</span></button>`;
            box.querySelector( '[data-gi="students"]' ).addEventListener( 'click', e => pickStudents( e.currentTarget ) );
        } catch { box.innerHTML = '<em>Не удалось загрузить учеников</em>'; }
    };
    const selectedStudents = () => Array.from( chosen );

    if ( ! groupFixed ) {
        $( '#giGroup' ).addEventListener( 'change', () => { loadStudents(); loadRooms(); loadLessons(); } );
    }
    if ( ! dateFixed ) {
        $( '#giDate' ).addEventListener( 'change', loadRooms );
    }
    $( '#giTime' ).addEventListener( 'change', loadRooms );
    $( '#giTimeEnd' ).addEventListener( 'change', loadRooms );

    loadStudents();
    loadRooms();
    loadLessons();

    $( '[data-gi="cancel"]' ).addEventListener( 'click', closeGradePop );
    $( '[data-gi="save"]' ).addEventListener( 'click', async () => {
        const at        = scheduledAt();
        const chosen    = multi ? selectedStudents() : [];
        const studentId = multi ? 0 : ( studentFixed ? initStudent : ( Number( $( '#giStudent' ).value ) || 0 ) );
        const roomVal   = $( '#giRoom' ).value;
        const lessonVal = $( '#giLesson' ).value;
        if ( ! at ) { toast( 'Укажите дату', 'error' ); return; }
        if ( multi && ! chosen.length ) { toast( 'Выберите хотя бы одного ученика', 'error' ); return; }
        if ( ! multi && ! studentId ) { toast( 'Выберите ученика', 'error' ); return; }
        closeGradePop();
        try {
            if ( extra ) {
                await api( 'updateExtra', {
                    group_id:           groupId(),
                    extra_key:          extra.extra_key,
                    student_person_ids: chosen,
                    scheduled_at:       at,
                    ends_at:            endsAt(),
                    room_id:            roomVal,
                    lesson_id:          lessonVal,
                } );
                toast( 'Дополнительное занятие обновлено' );
            } else if ( edit ) {
                await api( 'updateIndividual', {
                    group_lesson_id:   edit.glid,
                    scheduled_at:      at,
                    ends_at:           endsAt(),
                    room_id:           roomVal,
                    student_person_id: studentId,
                    lesson_id:         lessonVal,
                } );
                toast( 'Занятие обновлено' );
            } else {
                await api( 'createIndividual', Object.assign( {
                    group_id:     groupId(),
                    scheduled_at: at,
                    ends_at:      endsAt(),
                    room_id:      roomVal,
                    lesson_id:    lessonVal,
                }, multi ? { student_person_ids: chosen } : { student_person_id: studentId } ) );
                toast( multi ? 'Дополнительное занятие создано' : 'Индивидуальное занятие создано' );
            }
            if ( o.onSaved ) { o.onSaved(); }
        } catch ( err ) { toast( err.message, 'error' ); }
    } );

    openGradePopPositioned( pop, o.anchor );
}

/** 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DD'. */
function datePart( at ) { return String( at || '' ).slice( 0, 10 ); }

/** 'YYYY-MM-DD HH:MM:SS' → 'HH:MM' ('' — времени нет). */
function timePart( at ) { return String( at || '' ).split( ' ' )[ 1 ]?.slice( 0, 5 ) || ''; }

/** '15:00' → '16:00' — дефолт времени окончания (начало + 1 час). */
function addHour( time ) {
    const [ h, m ] = String( time ).split( ':' ).map( Number );
    if ( Number.isNaN( h ) ) { return '16:00'; }
    return `${ String( ( h + 1 ) % 24 ).padStart( 2, '0' ) }:${ String( m || 0 ).padStart( 2, '0' ) }`;
}
