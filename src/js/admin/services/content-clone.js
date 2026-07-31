/**
 * @fileoverview Дублирование записи банка контента из строки таблицы.
 *
 * @module admin/services/content-clone
 * @description Кнопку «Дублировать» ставит LearningMenuController::addCloneRowAction();
 * здесь — вызов соответствующего AJAX-хука (`clone_lesson`/`clone_work`/
 * `clone_assessment`/`clone_course`) и переход к редактированию копии.
 *
 * Для курса спрашиваем режим: `shallow` — копия структуры со ссылками на те же
 * уроки, `deep` — с копиями уроков (так же, как понимает CloneCallbacks).
 *
 * @requires jQuery
 */

import '../_types.js';
import { showNotice, toggleButton } from '../modules/utils.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

const $ = window.jQuery || jQuery;

/** Хук клонирования и имя параметра ID по типу записи. */
const CLONE_MAP = {
    lesson:     { action: 'cloneLesson',     param: 'lesson_id' },
    work:       { action: 'cloneWork',       param: 'work_id' },
    assessment: { action: 'cloneAssessment', param: 'assessment_id' },
    course:     { action: 'cloneCourse',     param: 'course_id' },
};

export const ContentClone = {

    _initialized: false,

    init() {
        if ( this._initialized ) { return; }
        this._initialized = true;

        $( document ).on( 'click', '.js-fs-clone', ( e ) => {
            e.preventDefault();
            this.handle( $( e.currentTarget ) );
        } );
    },

    /**
     * Обрабатывает клик по «Дублировать».
     *
     * @param {jQuery} $link Ссылка-действие строки.
     * @return {void}
     */
    handle( $link ) {
        const type = $link.data( 'clone-type' );
        const id   = parseInt( $link.data( 'clone-id' ), 10 );
        const cfg  = CLONE_MAP[ type ];

        if ( ! cfg || ! id ) { return; }

        // Для курса режим влияет на объём копирования — спрашиваем явно.
        // confirm() резолвится на «С уроками» и реджектится на «Только структуру».
        if ( 'course' === type ) {
            ConfirmModal.confirm( {
                title:       'Дублировать курс',
                message:     'Скопировать вместе с уроками? «С уроками» создаст копии уроков, «Только структуру» — ссылки на те же уроки.',
                confirmText: 'С уроками',
                cancelText:  'Только структуру',
                isDanger:    false,
            } ).then(
                () => this.request( $link, cfg, id, 'deep' ),
                () => this.request( $link, cfg, id, 'shallow' )
            );

            return;
        }

        this.request( $link, cfg, id );
    },

    /**
     * Отправляет запрос клонирования и открывает копию.
     *
     * @param {jQuery} $link Ссылка-действие (блокируется на время запроса).
     * @param {Object} cfg   Конфигурация хука: { action, param }.
     * @param {number} id    ID исходной записи.
     * @param {string} [mode] Режим клонирования курса.
     * @return {void}
     */
    request( $link, cfg, id, mode ) {
        const data = {
            action:     fs_lms_vars.ajax_actions[ cfg.action ],
            security:   fs_lms_vars.nonces.subject,
            [ cfg.param ]: id,
        };

        if ( mode ) { data.mode = mode; }

        toggleButton( $link, true, 'Дублирование…' );

        $.post( fs_lms_vars.ajaxurl, data )
            .done( ( res ) => {
                if ( ! res || ! res.success ) {
                    showNotice( ( res && res.data ) || 'Не удалось дублировать запись.', 'error' );
                    return;
                }

                // Копия создаётся черновиком — сразу открываем её на редактирование.
                window.location.href = `post.php?post=${ res.data.id }&action=edit`;
            } )
            .fail( () => showNotice( 'Ошибка сети при дублировании.', 'error' ) )
            .always( () => toggleButton( $link, false, 'Дублировать' ) );
    },
};
