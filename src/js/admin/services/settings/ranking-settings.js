import '../../_types.js';

const $ = jQuery;

/** Поиск среди ещё не выбранных учеников. */
export function matchingStudents( options, query ) {
    const text = query.trim().toLocaleLowerCase();
    return text.length < 2 ? [] : Array.from( options )
        .filter( option => ! option.selected && option.text.toLocaleLowerCase().includes( text ) )
        .slice( 0, 20 );
}

/** Веса рейтинга: сохраняется только форма своей карточки. */
export const RankingSettings = {
    init() {
        const $form = $( '#fs-ranking-settings-form' );
        if ( ! $form.length || $form.data( 'rankingBound' ) ) {
            return;
        }
        this.bindStudentSearch( $form );
        $form.data( 'rankingBound', true ).on( 'submit', ( event ) => {
            event.preventDefault();
            this.save( $form );
        } );
    },

    bindStudentSearch( $form ) {
        const $input = $form.find( '#fs-ranking-student-search' );
        const $list = $form.find( '#fs-ranking-student-list' );
        const $select = $form.find( '#fs-ranking-excluded' );
        const $selected = $form.find( '.fs-ranking-selected' );
        let active = -1;
        let results = [];
        const close = () => {
            $list.prop( 'hidden', true );
            $input.attr( 'aria-expanded', 'false' ).removeAttr( 'aria-activedescendant' );
            active = -1;
        };
        const pick = option => {
            if ( ! option ) return;
            option.selected = true;
            const $item = $( '<span class="fs-ranking-selected__item">' )
                .attr( 'data-ranking-selected', option.value )
                .text( option.text + ' ' );
            $item.append( $( '<button type="button" class="fs-ranking-selected__remove">' )
                .attr( 'aria-label', `Удалить ${ option.text }` ).text( '×' ) );
            $selected.append( $item );
            $input.val( '' ).trigger( 'focus' );
            close();
        };
        $input.on( 'input', () => {
            const query = String( $input.val() ).trim().toLocaleLowerCase();
            if ( query.length < 2 ) { close(); return; }
            results = matchingStudents( $select[ 0 ].options, query );
            active = -1;
            $list.empty();
            if ( ! results.length ) $list.append( $( '<li class="fs-print-center__suggest-empty">' ).text( 'Ученики не найдены' ) );
            results.forEach( ( option, index ) => {
                $list.append( $( '<li class="fs-print-center__suggest-item" role="option">' )
                    .attr( { 'data-index': index, id: `fs-ranking-student-opt-${ index }` } )
                    .append( $( '<span class="fs-print-center__suggest-name">' ).text( option.text ) ) );
            } );
            $list.prop( 'hidden', false );
            $input.attr( 'aria-expanded', 'true' );
        } );
        $input.on( 'keydown', event => {
            if ( $list.prop( 'hidden' ) ) return;
            if ( 'Escape' === event.key ) { event.preventDefault(); close(); }
            else if ( ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) && results.length ) {
                event.preventDefault();
                active = ( active + ( 'ArrowDown' === event.key ? 1 : -1 ) + results.length ) % results.length;
                const $items = $list.children( '[data-index]' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
                const $current = $items.eq( active ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
                $input.attr( 'aria-activedescendant', $current.attr( 'id' ) );
                $current.get( 0 )?.scrollIntoView( { block: 'nearest' } );
            } else if ( 'Enter' === event.key ) { event.preventDefault(); pick( results[ Math.max( active, 0 ) ] ); }
        } );
        $input.on( 'blur', close );
        $list.on( 'mousedown', '[data-index]', event => {
            event.preventDefault();
            pick( results[ Number( event.currentTarget.dataset.index ) ] );
        } );
        $selected.on( 'click', '.fs-ranking-selected__remove', event => {
            const $item = $( event.currentTarget ).closest( '[data-ranking-selected]' );
            const id = String( $item.attr( 'data-ranking-selected' ) );
            Array.from( $select[ 0 ].options ).forEach( option => { if ( option.value === id ) option.selected = false; } );
            $item.remove();
        } );
    },

    save( $form ) {
        if ( $form.data( 'saving' ) || ! $form[ 0 ].reportValidity() ) {
            return;
        }
        const $status = $form.find( '.js-ranking-status' );
        const $buttons = $form.find( 'button' );
        const settings = {};
        $form.find( '[data-ranking-field]' ).each( ( _, field ) => {
            settings[ field.name ] = field.value;
        } );
        const hiddenSubjects = [];
        $form.find( '[data-ranking-subject]' ).each( ( _, row ) => {
            if ( ! row.querySelector( 'input[type=checkbox]' ).checked ) {
                hiddenSubjects.push( row.dataset.rankingSubject );
            }
        } );
        settings.hidden_subjects = hiddenSubjects.join( ',' );
        settings.excluded_students = ( $form.find( '#fs-ranking-excluded' ).val() || [] ).join( ',' );
        $form.data( 'saving', true ).attr( 'aria-busy', 'true' );
        $status.text( 'Сохранение…' ).removeClass( 'fs-config-status--ok fs-config-status--err' );
        $buttons.prop( 'disabled', true );
        $.post( fs_lms_vars.ajaxurl, {
            action: fs_lms_vars.ajax_actions.saveRankingSettings,
            security: fs_lms_vars.nonces.config,
            settings,
        } )
            .done( ( result ) => {
                if ( result.success ) {
                    $status.text( 'Сохранено.' ).addClass( 'fs-config-status--ok' );
                } else {
                    $status.text( this.errorMessage( result ) ).addClass( 'fs-config-status--err' );
                }
            } )
            .fail( ( xhr ) => {
                $status.text( this.errorMessage( xhr.responseJSON, 'Ошибка сети. Повторите сохранение.' ) ).addClass( 'fs-config-status--err' );
            } )
            .always( () => {
                $buttons.prop( 'disabled', false );
                $form.data( 'saving', false ).removeAttr( 'aria-busy' );
            } );
    },

    errorMessage( response, fallback = 'Не удалось сохранить настройки.' ) {
        const data = response && response.data;
        return typeof data === 'string' ? data : ( data && data.message ) || fallback;
    },
};
