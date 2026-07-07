/* ══════════════════════════════════════════════════════════════════════
   Общая модалка индивидуального занятия (B2). Одно окно для создания и правки.
   Поля: группа, ученик, дата, время, кабинет, тема (урок банка → lesson_id).
   Часть полей фиксируется по контексту:
     • из «Группы»       — фиксированы группа и ученик (дата выбирается);
     • из календаря КТП  — фиксирована дата ячейки (группа/ученик выбираются);
     • правка слота      — группа фиксирована, остальное редактируется.
   Тема — выбор урока (lesson_id), не свободный текст (решение по B2).

   Вызов: openIndiModal({ api, anchor, groups, fixed:{date?,group?,student?},
                          edit?:{glid,group_id,student_person_id,student_name,
                                 date,time,room_id,room_name,lesson_id}, onSaved }).
   `api` — createApi(cfg) вызывающего экрана; его конфиг должен содержать действия
   getRoster / getFreeRooms / lessonCandidates / createIndividual / updateIndividual.
   ══════════════════════════════════════════════════════════════════════ */

import { esc, toast, todayIso, openGradePopPositioned, closeGradePop } from './utils.js';

export function openIndiModal( o ) {
    const pop = document.getElementById( 'profGradePop' );
    if ( ! pop ) { return; }

    const api    = o.api;
    const edit   = o.edit || null;
    const fx     = o.fixed || {};
    const groups = o.groups || [];

    const initGroup   = edit ? edit.group_id : ( fx.group ? fx.group.id : ( groups[ 0 ] && groups[ 0 ].id ) );
    const initStudent = edit ? edit.student_person_id : ( fx.student ? fx.student.person_id : 0 );
    const initDate    = edit ? edit.date : ( fx.date || todayIso() );
    const initTime    = edit ? ( edit.time || '15:00' ) : '15:00';
    const initTimeEnd = edit ? ( edit.time_end || addHour( initTime ) ) : addHour( initTime );
    const initRoom    = edit ? String( edit.room_id || '' ) : '';
    const initLesson  = edit ? String( edit.lesson_id || '' ) : '';

    const groupFixed   = !! fx.group || !! edit;   // группа не меняется
    const studentFixed = !! fx.student;            // ученик фиксирован (из «Группы»)
    const dateFixed    = !! fx.date && ! edit;      // дата фиксирована (ячейка календаря)

    pop.innerHTML = `
        <div class="gp-form gp-indi">
        <div class="gp-title">${ edit ? 'Правка занятия' : 'Инд. занятие' }</div>
        ${ groupFixed
            ? `<input type="hidden" id="giGroup" value="${ initGroup }">`
            : `<label class="gp-field"><span>Группа</span><select id="giGroup">${
                groups.map( g => `<option value="${ g.id }" ${ g.id === initGroup ? 'selected' : '' }>${ esc( g.name ) }</option>` ).join( '' )
            }</select></label>` }
        ${ studentFixed
            ? `<div class="gp-field"><span>Ученик</span><b class="gp-fixed">${ esc( fx.student.name || '' ) }</b><input type="hidden" id="giStudent" value="${ initStudent }"></div>`
            : `<label class="gp-field"><span>Ученик</span><select id="giStudent"><option value="">— загрузка… —</option></select></label>` }
        ${ dateFixed
            ? `<div class="gp-field"><span>Дата</span><b class="gp-fixed">${ esc( fmtDate( initDate ) ) }</b><input type="hidden" id="giDate" value="${ initDate }"></div>`
            : `<label class="gp-field"><span>Дата</span><input type="date" id="giDate" value="${ initDate }"></label>` }
        <div class="gp-field"><span>Время</span><div class="gp-time"><input type="time" id="giTime" value="${ initTime }"><span class="gp-dash">–</span><input type="time" id="giTimeEnd" value="${ initTimeEnd }"></div></div>
        <label class="gp-field"><span>Кабинет</span><select id="giRoom"><option value="">Без кабинета</option></select></label>
        <label class="gp-field"><span>Тема</span><select id="giLesson"><option value="">— без темы —</option></select></label>
        <div class="gp-row">
            <button class="prof-btn prof-btn-sm prof-btn-primary" data-gi="save">${ edit ? 'Сохранить' : 'Создать' }</button>
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
            if ( edit && initRoom && ! sel.querySelector( `option[value="${ initRoom }"]` ) ) {
                sel.insertAdjacentHTML( 'beforeend', `<option value="${ initRoom }" selected>${ esc( edit.room_name || 'Кабинет' ) }</option>` );
            }
        } catch { sel.innerHTML = '<option value="0">Без кабинета</option>'; }
    };

    // Темы (уроки предмета группы, курс-первыми).
    const loadLessons = async () => {
        const sel = $( '#giLesson' );
        sel.innerHTML = '<option value="">— загрузка… —</option>';
        try {
            const d = await api( 'lessonCandidates', { group_id: groupId() } );
            const lessons = ( d && d.lessons ) || [];
            sel.innerHTML = '<option value="">— без темы —</option>' +
                lessons.map( l => `<option value="${ l.id }" ${ String( l.id ) === initLesson ? 'selected' : '' }>${ esc( l.title || 'Без названия' ) }</option>` ).join( '' );
        } catch { sel.innerHTML = '<option value="">— без темы —</option>'; }
    };

    // Ученики выбранной группы (если ученик не фиксирован).
    const loadStudents = async () => {
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
        const studentId = studentFixed ? initStudent : ( Number( $( '#giStudent' ).value ) || 0 );
        const roomVal   = $( '#giRoom' ).value;
        const lessonVal = $( '#giLesson' ).value;
        if ( ! at ) { toast( 'Укажите дату' ); return; }
        if ( ! studentId ) { toast( 'Выберите ученика' ); return; }
        closeGradePop();
        try {
            if ( edit ) {
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
                await api( 'createIndividual', {
                    group_id:          groupId(),
                    student_person_id: studentId,
                    scheduled_at:      at,
                    ends_at:           endsAt(),
                    room_id:           roomVal,
                    lesson_id:         lessonVal,
                } );
                toast( 'Индивидуальное занятие создано' );
            }
            if ( o.onSaved ) { o.onSaved(); }
        } catch ( err ) { toast( err.message ); }
    } );

    openGradePopPositioned( pop, o.anchor );
}

/** 'YYYY-MM-DD' → 'DD.MM'. */
function fmtDate( iso ) {
    const p = String( iso ).split( '-' );
    return p.length === 3 ? `${ p[ 2 ] }.${ p[ 1 ] }` : iso;
}

/** '15:00' → '16:00' — дефолт времени окончания (начало + 1 час). */
function addHour( time ) {
    const [ h, m ] = String( time ).split( ':' ).map( Number );
    if ( Number.isNaN( h ) ) { return '16:00'; }
    return `${ String( ( h + 1 ) % 24 ).padStart( 2, '0' ) }:${ String( m || 0 ).padStart( 2, '0' ) }`;
}
