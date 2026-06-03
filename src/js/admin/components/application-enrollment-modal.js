import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';

const $ = jQuery;

export const ApplicationEnrollmentModal = {
    $modal: null,
    _enrollCallbacks: [],
    _closeCallbacks: [],
    _completed: false,
    _initialized: false,

    $form: null,
    $enrollBtn: null,

    init() {
        if ( this._initialized ) return;

        this.$modal = $( '#fs-application-enrollment-modal' );
        if ( ! this.$modal.length ) return;

        this._initialized = true;
        this._cacheElements();
        this._bindEvents();
    },

    _cacheElements() {
        this.$form      = $( '#fs-application-enrollment-form' );
        this.$enrollBtn = $( '#enrollment-modal-enroll-btn' );
    },

    _bindEvents() {
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .fs-lms-modal-cancel, .js-modal-close, .fs-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        this.$modal.on( 'click', '.fs-modal-accordion__header', ( e ) => {
            e.preventDefault();
            this._toggleAccordion( $( e.currentTarget ) );
        } );

        this.$form.on( 'submit.fs', ( e ) => {
            e.preventDefault();
            if ( ! this._validateEnrollment() ) return;
            const data = this._collectEnrollmentData();
            this._enrollCallbacks.forEach( cb => cb( data ) );
        } );

        this.$modal.on( 'keydown', ( e ) => {
            if ( e.key === 'Enter' && ! $( e.target ).is( 'textarea, select, input[type="date"]' ) ) {
                e.preventDefault();
                this.$form.trigger( 'submit' );
            }
        } );
    },

    open( appId ) {
        $( '#enrollment-modal-id' ).text( '#' + appId );
        this.$form.find( '[name="application_id"]' ).val( appId );
        this._completed = false;

        this._resetDetailFields();
        this._resetAccordion();

        openModal( this.$modal );
        bindEsc( 'application_enrollment', () => this.close() );
    },

    close() {
        if ( ! this._completed ) {
            const appId = this.$form.find( '[name="application_id"]' ).val();
            if ( appId ) {
                this._closeCallbacks.forEach( cb => cb( { application_id: appId } ) );
            }
        }
        closeModal( this.$modal );
        unbindEsc( 'application_enrollment' );
    },

    markCompleted() {
        this._completed = true;
    },

    onClose( callback ) {
        if ( typeof callback === 'function' ) { this._closeCallbacks.push( callback ); }
    },

    _toggleAccordion( $header ) {
        const isOpen = $header.attr( 'aria-expanded' ) === 'true';
        const bodyId = $header.attr( 'aria-controls' );

        this.$modal.find( '.fs-modal-accordion__header' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).prop( 'hidden', true );

        if ( ! isOpen ) {
            $header.attr( 'aria-expanded', 'true' );
            $( '#' + bodyId ).prop( 'hidden', false );
        }
    },

    onEnroll( callback ) {
        if ( typeof callback === 'function' ) { this._enrollCallbacks.push( callback ); }
    },

    populateStudentData( student ) {
        if ( ! student ) return;
        this._setField( 's_last_name',  student.last_name );
        this._setField( 's_first_name', student.first_name );
        this._setField( 's_middle_name', student.middle_name );
        this._setField( 's_birth_date', student.birth_date );
        this._setField( 's_email',      student.email );
        this._setField( 's_phone',      student.phone );
        this._setField( 's_school',     student.school );
        this._setField( 's_grade',      student.grade ? student.grade + ' класс' : '—' );
        this._setField( 's_doc',        [ student.doc_type, student.doc_number ].filter( Boolean ).join( ' ' ) );
        this._setField( 's_inn',        student.inn );
    },

    populateParentData( parent ) {
        if ( ! parent ) return;
        this._setField( 'p_last_name',     parent.last_name );
        this._setField( 'p_first_name',    parent.first_name );
        this._setField( 'p_middle_name',   parent.middle_name );
        this._setField( 'p_birth_date',    parent.birth_date );
        this._setField( 'p_relation_type', parent.relation_type );
        this._setField( 'p_email',         parent.email );
        this._setField( 'p_phone',         parent.phone );
        this._setField( 'p_doc',           [ parent.doc_type, parent.doc_number, parent.doc_issued_by, parent.doc_issued_date ].filter( Boolean ).join( ', ' ) );
        this._setField( 'p_inn',           parent.inn );
        this._setField( 's_inn_p',         parent.student_inn ?? '—' );
        this._setField( 'p_address',       parent.address );
    },

    populatePeriods( periods, currentId ) {
        const $select = this.$modal.find( '[name="period_key"]' );
        $select.empty().append( '<option value="">— Выберите период —</option>' );
        periods.forEach( p => {
            const selected = p.id === currentId ? ' selected' : '';
            $select.append( `<option value="${ p.id }"${ selected }>${ p.name }</option>` );
        } );
    },

    populateSubjects( subjects ) {
        const $select = this.$modal.find( '[name="subject_key"]' );
        $select.empty().append( '<option value="">— Выберите предмет —</option>' );
        subjects.forEach( s => {
            $select.append( `<option value="${ s.key }">${ s.name }</option>` );
        } );
    },

    populateGroups( groups ) {
        const $select = this.$modal.find( '[name="group_id"]' );
        $select.empty();
        if ( ! groups.length ) {
            $select.append( '<option value="">— Нет доступных групп —</option>' ).prop( 'disabled', true );
        } else {
            $select.append( '<option value="">— Выберите группу —</option>' );
            groups.forEach( g => {
                $select.append( `<option value="${ g.id }">${ g.title }</option>` );
            } );
            $select.prop( 'disabled', false );
        }
    },

    setEnrollState( loading ) {
        this.$enrollBtn.prop( 'disabled', loading ).text( loading ? 'Зачисление...' : 'Зачислить' );
    },

    _setField( key, value ) {
        this.$modal.find( `[data-field="${ key }"]` ).text( value || '—' );
    },

    _resetDetailFields() {
        this.$modal.find( '.fs-detail-value' ).text( '—' );
    },

    _resetAccordion() {
        this.$modal.find( '.fs-modal-accordion__header' ).first().attr( 'aria-expanded', 'true' );
        this.$modal.find( '.fs-modal-accordion__header' ).not( ':first' ).attr( 'aria-expanded', 'false' );
        this.$modal.find( '.fs-modal-accordion__body' ).first().prop( 'hidden', false );
        this.$modal.find( '.fs-modal-accordion__body' ).not( ':first' ).prop( 'hidden', true );
    },

    _validateEnrollment() {
        const period  = this.$form.find( '[name="period_key"]' ).val();
        const subject = this.$form.find( '[name="subject_key"]' ).val();
        const group   = this.$form.find( '[name="group_id"]' ).val();

        if ( ! period || ! subject || ! group ) {
            // Open enrollment section if not visible
            const $header = this.$modal.find( '[aria-controls="enroll-acc-form"]' );
            if ( $header.attr( 'aria-expanded' ) !== 'true' ) {
                $header.trigger( 'click' );
            }
            alert( 'Выберите период, предмет и группу.' );
            return false;
        }
        return true;
    },

    _collectEnrollmentData() {
        const f          = this.$form;
        const order_date = f.find( '[name="order_date"]' ).val();
        return {
            application_id: f.find( '[name="application_id"]' ).val(),
            contract_no:    f.find( '[name="contract_no"]' ).val() || 'б/н',
            contract_date:  f.find( '[name="contract_date"]' ).val(),
            order_no:       f.find( '[name="order_no"]' ).val() || 'б/н',
            order_date,
            enrolled_at:    order_date,
            period_key:     f.find( '[name="period_key"]' ).val(),
            subject_key:    f.find( '[name="subject_key"]' ).val(),
            group_id:       f.find( '[name="group_id"]' ).val(),
        };
    },
};