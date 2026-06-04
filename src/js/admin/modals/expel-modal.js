import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ExpelModal = {
    $modal:           null,
    _confirmCbs:      [],
    _initialized:     false,
    _bulkStudents:    null,
    _afterExpel:      null,
    _originalTitle:   '',
    _originalWarning: '',

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-expel-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._originalTitle   = this.$modal.find( '.fs-lms-modal-title' ).text();
        this._originalWarning = this.$modal.find( '.fs-expel-warning' ).html();

        this._bindReasonEvents();
        this._bindEvents();
    },

    _bindEvents() {
        this.$modal.on(
            'click',
            '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close',
            ( e ) => { e.preventDefault(); this.close(); }
        );

        $( '#fs-expel-form' ).on( 'submit.fs', ( e ) => {
            e.preventDefault();
            this._confirmCbs.forEach( cb => cb( this._collectFormData() ) );
        } );
    },

    onConfirm( callback ) {
        if ( typeof callback === 'function' ) this._confirmCbs.push( callback );
    },

    open( studentId, studentName ) {
        console.log('одинарная')
        if ( ! this._initialized ) return;

        this._bulkStudents = null;
        this.$modal.find( 'input[name="student_id"]' ).val( studentId );
        this.$modal.find( '.fs-expel-student-name' ).text( studentName ? `Ученик: ${ studentName }` : '' );
        this._resetFormFields();
        this._setSaving( false );

        openModal( this.$modal );
        bindEsc( 'expel', () => this.close() );
    },

    openBulk( students, options = {} ) {
        console.log('Булк')
        if ( ! this._initialized ) return;

        this._bulkStudents = students;
        this._afterExpel   = options.afterExpel || null;

        this.$modal.find( '.fs-lms-modal-title' ).text( 'Массовое отчисление' );
        this.$modal.find( '.fs-expel-warning' ).html(
            '<span class="dashicons dashicons-warning"></span>' +
            ' Будут удалены профили учеников и родителей. Данные сохранятся в архиве.'
        );

        const $nameEl = this.$modal.find( '.fs-expel-student-name' );
        $nameEl.empty();
        if ( students.length ) {
            const $list = $( '<ul class="fs-expel-bulk-list"></ul>' );
            students.forEach( s => {
                $( '<li></li>' ).text( s.name || `#${ s.id }` ).appendTo( $list );
            } );
            $nameEl.append( $( '<span>Ученики:</span>' ) ).append( $list );
        }

        this.$modal.find( 'input[name="student_id"]' ).val( '' );
        this._resetFormFields();
        this._setSaving( false );

        openModal( this.$modal );
        bindEsc( 'expel', () => this.close() );
    },

    close() {
        closeModal(this.$modal, () => {
            this._restoreDefaults();
            this._resetFormFields();
        });

        unbindEsc('expel');
    },

    setSaving( loading ) {
        this._setSaving( loading );
    },

    _setSaving( loading ) {
        this.$modal.find( '.js-expel-confirm' )
            .prop( 'disabled', loading )
            .text( loading ? 'Отчисление...' : 'Отчислить' );
    },

    _collectFormData() {
        const $select      = this.$modal.find( '#expel-reason' );
        const otherValue   = $select.data( 'other-value' );
        const reason       = $select.val();
        const customReason = this.$modal.find( '#expel-custom-reason' ).val().trim();

        const finalReason = reason === otherValue
            ? `${ otherValue }: ${ customReason }`
            : reason;

        return {
            student_id:    this._bulkStudents === null
                ? this.$modal.find( 'input[name="student_id"]' ).val()
                : null,
            student_ids:   this._bulkStudents
                ? this._bulkStudents.map( s => s.id )
                : null,
            reason:         finalReason,
            is_other_empty: reason === otherValue && ! customReason,
            afterExpel:     this._afterExpel,
        };
    },

    _restoreDefaults() {
        if ( this._bulkStudents === null ) return;
        this.$modal.find( '.fs-lms-modal-title' ).text( this._originalTitle );
        this.$modal.find( '.fs-expel-warning' ).html( this._originalWarning );
        this.$modal.find( '.fs-expel-student-name' ).empty();
        this._bulkStudents = null;
        this._afterExpel   = null;
    },

    _bindReasonEvents() {
        this.$modal.find( '#expel-reason' ).on( 'change', ( e ) => {
            const otherValue = $( e.target ).data( 'other-value' );
            const isOther = e.target.value === otherValue;
            this.$modal.find( '#fs-expel-custom-reason-wrap' ).prop( 'hidden', ! isOther );
        } );
    },

    _resetFormFields() {
        this.$modal.find( '#expel-reason' ).val( '' );
        this.$modal.find( '#expel-custom-reason' ).val( '' );
        this.$modal.find( '#fs-expel-custom-reason-wrap' ).prop( 'hidden', true );
    },
};
