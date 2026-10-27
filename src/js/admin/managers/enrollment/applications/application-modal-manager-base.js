/**
 * @module ApplicationModalManagerBase
 * @description Общая часть менеджеров модалок заявки (редактирование и проверка): открытие по кнопке
 *              в строке (данные берутся из data-атрибутов) и сохранение с блокировкой кнопки.
 *              Модуль отличается только окном, кнопкой, чтением данных, действием и nonce.
 *
 * @requires jQuery
 */

import { showModalError } from '../../../modules/utils.js';

const $ = jQuery;

/**
 * @param {Object}   cfg
 * @param {Object}   cfg.modal          Модалка ({@link createApplicationModal}).
 * @param {string}   cfg.openSelector   Кнопка открытия в строке таблицы.
 * @param {(jQuery) => Object} cfg.readButton Данные заявки из data-атрибутов кнопки.
 * @param {() => string} cfg.action     AJAX-действие сохранения.
 * @param {() => string} cfg.nonce      Nonce сохранения.
 */
export const createApplicationModalManager = ( cfg ) => ( {

    /** Инициализация модалки и навешивание обработчиков (повторный вызов безопасен). */
    init() {
        cfg.modal.init();

        // Если модалки нет в DOM, не навешиваем обработчики менеджера
        if ( ! cfg.modal._initialized ) return;

        this._bindEvents();
    },

    /**
     * Делегирование через $(document): строки таблицы могут добавляться в DOM динамически.
     * @private
     */
    _bindEvents() {
        $( document ).on( 'click', cfg.openSelector, ( e ) => {
            e.preventDefault(); // Отменяет стандартное поведение браузера (переход по ссылке, прокрутка)
            cfg.modal.open( cfg.readButton( $( e.currentTarget ) ) );
        } );

        cfg.modal.onSave( ( data ) => this._handleSave( data ) );
    },

    /**
     * Отправляет данные формы на сервер, блокируя кнопку от двойного клика.
     * @private
     * @param {Object} data Данные формы из модального окна.
     */
    _handleSave( data ) {
        cfg.modal.setSaveState( true );

        const payload = {
            action:         cfg.action(),
            security:       cfg.nonce(), // Nonce защищает от CSRF-атак
            application_id: data.application_id,
        };
        cfg.modal.fields.forEach( ( field ) => {
            payload[ field ] = data[ field ];
        } );

        $.post( fs_lms_vars.ajaxurl, payload )
            .done( ( res ) => {
                if ( res.success ) {
                    cfg.modal.close();
                    location.reload();
                } else {
                    showModalError( res.data?.message || res.data || 'Ошибка сохранения.', cfg.modal.$modal );
                    cfg.modal.setSaveState( false );
                }
            } )
            .fail( () => {
                showModalError( 'Ошибка соединения.', cfg.modal.$modal );
                cfg.modal.setSaveState( false );
            } );
    },
} );
