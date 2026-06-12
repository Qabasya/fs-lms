/**
 * @fileoverview Модуль управления настройками согласий (Consents) для плагина FS-LMS.
 *
 * @module ConsentSettings
 * @description Менеджер для вкладки "Согласия".
 *              Отвечает за:
 *              - Управление аккордеоном в таблице согласий (раскрытие/скрытие деталей)
 *              - Удаление определений согласий с подтверждением через ConfirmModal
 *              - Работу с кастомным модальным окном создания нового согласия
 *              - Автогенерацию slug-ключа из названия с учетом ручного редактирования
 *              - Клиентскую валидацию перед отправкой AJAX-запроса
 *
 * @requires jQuery
 * @requires toSlug - утилита для транслитерации и создания slug
 * @requires AlertModal, ConfirmModal - модальные окна для уведомлений и подтверждений
 */

import './../_types.js';
import { toSlug } from '../modules/utils.js';
import { AlertModal } from '../modals/alert-modal.js';
import { ConfirmModal } from '../modals/confirm-modal.js';

const $ = jQuery;

/**
 * Менеджер настроек согласий.
 * Управляет UI-взаимодействиями на вкладке "Согласия" (tab-consents).
 */
export const ConsentSettings = {

	/**
	 * Инициализация модуля.
	 * Проверяет наличие целевой вкладки перед навешиванием событий.
	 */
	init() {
		// Guard clause: если вкладки "Согласия" нет на текущей странице,
		// не тратим ресурсы на инициализацию и не рискуем получить ошибки
		// при поиске несуществующих элементов.
		if ( ! $( '#tab-consents' ).length ) {
			return;
		}
		this.bindEvents();
	},

	/**
	 * Привязка обработчиков событий.
	 * Использует делегирование событий для элементов внутри таблицы и документа.
	 */
	bindEvents() {
		// ==========================================
		// 1. АККОРДЕОН (Раскрытие/скрытие деталей согласия)
		// ==========================================
		$( '#js-consents-table' ).on( 'click', '.js-consent-toggle', ( e ) => {
			// ВАЖНЫЙ UX-ПАТТЕРН: Игнорирование клика, если он был по интерактивному элементу.
			// Если внутри заголовка аккордеона (.js-consent-toggle) находится кнопка "Удалить"
			// или ссылка, и пользователь кликает по ней, мы НЕ должны сворачивать/разворачивать аккордеон.
			// closest() проверяет, был ли клик по ссылке или кнопке (или внутри них).
			if ( $( e.target ).closest( 'a, button' ).length ) return;

			const key   = $( e.currentTarget ).data( 'key' );
			const $row  = $( `#consent-accordion-${key}` ); // Тело аккордеона (отдельная строка таблицы)
			const $icon = $( e.currentTarget ).find( '.accordion-arrow' );

			// Переключаем видимость тела аккордеона
			$row.toggleClass( 'hidden' );

			// Поворот стрелки задаётся CSS-классом is-open (см. _consents.scss),
			// анимация — через transition на .accordion-arrow.
			$icon.toggleClass( 'is-open', ! $row.hasClass( 'hidden' ) );
		} );

		// ==========================================
		// 2. УДАЛЕНИЕ СОГЛАСИЯ (с подтверждением)
		// ==========================================
		$( '#js-consents-table' ).on( 'click', '.js-delete-consent', async ( e ) => {
			e.preventDefault();
			const $btn = $( e.currentTarget );
			const key  = $btn.data( 'key' );
			const name = $btn.data( 'name' );

			// ПАТТЕРН: async/await с промисифицированным ConfirmModal.
			// Мы ждем результата подтверждения. Если пользователь нажмет "Отмена",
			// Promise отклонится (reject), и выполнение прыгнет в блок catch.
			try {
				await ConfirmModal.confirm( {
					message:     `Удалить определение «${name}»?\n\nСтраница WP останется для истории.`,
					confirmText: 'Удалить',
					isDanger:    true,
				} );
			} catch {
				// Пустой catch: если пользователь отменил действие, мы просто выходим.
				// Никаких ошибок показывать не нужно, это штатная ситуация.
				return;
			}

			// Если мы дошли до этой строки, значит пользователь подтвердил удаление.
			this._deleteConsent( key, $btn.closest( 'tr' ) );
		} );

		// ==========================================
		// 3. УПРАВЛЕНИЕ КАСТОМНЫМ МОДАЛЬНЫМ ОКНОМ
		// ==========================================
		// Открытие и закрытие модалки
		$( document ).on( 'click', '.js-open-consent-modal', () => this._openModal() );
		$( document ).on( 'click', '.js-close-consent-modal', () => this._closeModal() );

		// Закрытие по клавише Escape.
		// Навешиваем на document, чтобы срабатывало откуда угодно на странице.
		$( document ).on( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) this._closeModal();
		} );

		// ==========================================
		// 4. АВТО-ГЕНЕРАЦИЯ КЛЮЧА (SLUG) ИЗ НАЗВАНИЯ
		// ==========================================
		// Слушаем ввод в поле "Название"
		$( '#consent-def-name' ).on( 'input', function () {
			const $keyField = $( '#consent-def-key' );

			// ПАТТЕРН: Уважение ручного ввода пользователя.
			// Если пользователь уже начал редактировать поле ключа вручную (флаг user-edited = true),
			// мы перестаем автоматически перезаписывать его значение.
			// Это предотвращает раздражение, когда автогенерация портит то, что пользователь уже исправил.
			if ( $keyField.data( 'user-edited' ) ) return;

			// Транслитерируем и очищаем название, превращая его в валидный slug
			$keyField.val( toSlug( $( this ).val() ) );
		} );

		// Слушаем ввод в поле "Ключ", чтобы зафиксировать факт ручного редактирования
		$( '#consent-def-key' ).on( 'input', function () {
			// Устанавливаем флаг в true, если поле не пустое.
			// data() в jQuery сохраняет значение в памяти, не добавляя реальный data-атрибут в HTML.
			$( this ).data( 'user-edited', $( this ).val() !== '' );
		} );

		// ==========================================
		// 5. ОТПРАВКА ФОРМЫ СОЗДАНИЯ СОГЛАСИЯ
		// ==========================================
		$( '#js-consent-def-submit' ).on( 'click', () => this._submitModal() );
	},

	/**
	 * Открывает модальное окно создания нового согласия.
	 * Сбрасывает поля и устанавливает фокус на первое поле.
	 * @private
	 */
	_openModal() {
		// Очищаем поля от данных предыдущего открытия
		$( '#consent-def-name' ).val( '' );
		$( '#consent-def-key' ).val( '' ).removeData( 'user-edited' ); // Сбрасываем флаг ручного ввода
		$( '#js-consent-modal-notice' ).hide().text( '' ); // Скрываем возможные старые ошибки

		$( '#consent-definition-modal' ).show();

		// УСТАНОВКА ФОКУСА С ЗАДЕРЖКОЙ:
		// Используем setTimeout, чтобы дать браузеру время отрисовать модалку (применить display: block).
		// Если вызвать .focus() синхронно сразу после .show(), фокус может не сработать
		// или страница прокрутится в странное место. 50мс достаточно для рендеринга.
		setTimeout( () => $( '#consent-def-name' ).trigger( 'focus' ), 50 );
	},

	/**
	 * Закрывает модальное окно создания согласия.
	 * @private
	 */
	_closeModal() {
		$( '#consent-definition-modal' ).hide();
	},

	/**
	 * Обрабатывает отправку формы создания согласия.
	 * Выполняет клиентскую валидацию и отправляет AJAX-запрос.
	 * @private
	 */
	_submitModal() {
		const name = $( '#consent-def-name' ).val().trim();
		const key  = $( '#consent-def-key' ).val().trim();
		const $btn = $( '#js-consent-def-submit' );
		const $notice = $( '#js-consent-modal-notice' );

		// КЛИЕНТСКАЯ ВАЛИДАЦИЯ:
		// Проверяем заполненность полей до отправки запроса на сервер.
		// Это экономит трафик и дает мгновенную обратную связь.
		if ( ! name || ! key ) {
			this._showModalError( 'Заполните все поля.' );
			return;
		}

		// Проверка формата ключа с помощью регулярного выражения.
		// Ключ должен состоять только из строчных латинских букв, цифр, подчеркиваний и дефисов.
		// Это важно, так как ключ часто используется в коде, URL или как идентификатор в БД.
		if ( ! /^[a-z0-9_\-]+$/.test( key ) ) {
			this._showModalError( 'Ключ может содержать только строчные буквы, цифры, _ и -.' );
			return;
		}

		// Блокируем кнопку и показываем индикатор загрузки
		$btn.prop( 'disabled', true ).text( 'Создание…' );
		$notice.hide();

		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.addConsentDefinition,
			security: fs_lms_vars.nonces.manager,
			name,
			key,
		} )
			.done( ( res ) => {
				if ( ! res.success ) {
					// Если сервер вернул ошибку (например, ключ уже существует), показываем её в модалке
					this._showModalError( res.data || 'Ошибка создания.' );
					return;
				}
				// УСПЕХ: Перезагружаем страницу.
				// Так как добавление нового согласия требует отрисовки сложной структуры
				// (главная строка + строка аккордеона), проще и надежнее перезагрузить страницу,
				// чем пытаться динамически вставить новый HTML-блок через JS.
				window.location.reload();
			} )
			.fail( () => this._showModalError( 'Ошибка сервера.' ) )
			.always( () => $btn.prop( 'disabled', false ).text( 'Создать согласие' ) );
	},

	/**
	 * Выполняет AJAX-запрос на удаление определения согласия.
	 * @private
	 * @param {string} key - Уникальный ключ удаляемого согласия.
	 * @param {jQuery} $mainRow - jQuery-объект главной строки таблицы.
	 */
	_deleteConsent( key, $mainRow ) {
		$.post( fs_lms_vars.ajaxurl, {
			action:   fs_lms_vars.ajax_actions.deleteConsentDefinition,
			security: fs_lms_vars.nonces.manager,
			key,
		} )
			.done( ( res ) => {
				if ( res.success ) {
					// СПЕЦИФИКА HTML-ТАБЛИЦ С АККОРДЕОНАМИ:
					// В HTML-таблицах тело аккордеона часто реализуется как отдельная строка <tr>,
					// идущая сразу за главной строкой. Поэтому при удалении нужно удалить ОБЕ строки:
					// 1. $mainRow.next('.consent-accordion-row') — строка с деталями аккордеона
					// 2. $mainRow — сама главная строка с названием и кнопками
					$mainRow.next( '.consent-accordion-row' ).remove();
					$mainRow.remove();
				} else {
					// Используем AlertModal для показа критических ошибок,
					// так как модалка создания уже закрыта к этому моменту.
					AlertModal.show( res.data || 'Ошибка удаления.' );
				}
			} )
			.fail( () => AlertModal.show( 'Ошибка сервера.' ) );
	},

	/**
	 * Показывает текст ошибки внутри модального окна создания.
	 * @private
	 * @param {string} msg - Текст ошибки.
	 */
	_showModalError( msg ) {
		$( '#js-consent-modal-notice' ).text( msg ).show();
	},
};