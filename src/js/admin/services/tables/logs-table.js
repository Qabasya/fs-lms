/**
 * @fileoverview Модуль управления экспортом журналов (логов) в CSV.
 *
 * @module LogsTable
 * @description Менеджер для вкладок с логами (аудит, зачисления, PII, email и т.д.).
 *              Отвечает за:
 *              - Маршрутизацию запросов экспорта к нужным AJAX-действиям на сервере
 *              - Безопасный парсинг фильтров из data-атрибутов кнопок
 *              - Инициацию скачивания CSV-файла через редирект
 *              - Управление состоянием кнопки экспорта (блокировка, восстановление иконки)
 *
 *              Архитектурная особенность: использование паттерна "Registry" (Реестр)
 *              через объект CHANNEL_ACTIONS для маппинга каналов на серверные действия.
 *              Это позволяет легко добавлять новые типы логов без изменения основной логики.
 *
 * @requires jQuery
 * @requires AlertModal - модальное окно для отображения ошибок
 */

import '../../_types.js';
import { AlertModal } from '../../modals/alert-modal.js';

const $ = jQuery;

/**
 * РЕЕСТР ДЕЙСТВИЙ (Registry Pattern).
 * Сопоставляет идентификатор канала лога (используется в data-channel кнопки)
 * с именем соответствующего AJAX-действия в WordPress (из fs_lms_vars.ajax_actions).
 *
 * ЗАЧЕМ ЭТО НУЖНО:
 * Вместо того чтобы писать множество if/else или switch/case внутри обработчика клика,
 * мы выносим маппинг в отдельную константу.
 * Если нужно добавить новый тип лога (например, 'payment_log'),
 * достаточно добавить одну строку сюда и зарегистрировать action на бэкенде.
 * Основной код обработчика событий при этом менять не нужно.
 */
const CHANNEL_ACTIONS = {
	entity_audit:   'exportEntityAuditLog',
	enrollment:     'exportEnrollmentLog',
	pii:            'exportPiiLog',
	export:         'exportExportLog',
	data_change:    'exportDataChangeLog',
	consent_change: 'exportConsentChangeLog',
	email:          'exportEmailLog',
	deletion:       'exportDeletionLog',
	auth:           'exportAuthLog',
};

/**
 * Менеджер таблиц логов.
 */
export const LogsTable = {

	/**
	 * Инициализация модуля.
	 * Проверяет наличие вкладок с логами на текущей странице.
	 */
	init() {
		// СЛОЖНЫЙ CSS-СЕЛЕКТОР: [id^="js-"][id$="-tab"]
		// Этот селектор находит элементы, у которых id:
		// 1. Начинается с "js-" (^=)
		// 2. Заканчивается на "-tab" ($=)
		// Например, id="js-entity-audit-tab" подойдет, а id="js-users-table" — нет.
		// Это надежный способ определить, что мы находимся на странице с вкладками логов,
		// не привязываясь к конкретным жестко заданным ID.
		if ( ! $( '[id^="js-"][id$="-tab"]' ).length ) {
			return;
		}
		this.bindEvents();
	},

	/**
	 * Привязка обработчиков событий.
	 */
	bindEvents() {
		// Делегирование события клика по кнопке экспорта.
		// Кнопки могут находиться внутри динамически переключаемых вкладок,
		// поэтому используем делегирование через $(document).
		$( document ).on( 'click', '.js-export-log-csv', ( e ) => {
			const $btn    = $( e.currentTarget );
			const channel = $btn.data( 'channel' ); // Например, 'entity_audit'

			// Получаем имя серверного действия из нашего реестра.
			// Если канал неизвестен (например, опечатались в HTML), action будет undefined.
			const action  = CHANNEL_ACTIONS[ channel ];

			// ЗАЩИТА ОТ НЕКОРРЕКТНЫХ ДАННЫХ:
			// Проверяем, что канал найден в реестре И что соответствующее действие 
			// действительно существует в глобальном объекте fs_lms_vars.ajax_actions.
			// Это предотвращает отправку запроса с undefined в качестве action.
			if ( ! action || ! fs_lms_vars.ajax_actions[ action ] ) {
				console.error( '[fs-lms] Unknown export channel:', channel );
				return;
			}

			// ПАРСИНГ ФИЛЬТРОВ:
			// Кнопка может содержать дополнительные фильтры (например, даты или ID) 
			// в data-атрибуте data-filters в виде JSON-строки.
			let filters = {};
			try {
				const raw = $btn.attr( 'data-filters' );
				if ( raw ) {
					// JSON.parse может выбросить SyntaxError, если строка невалидна.
					// Оборачиваем в try...catch, чтобы падение парсинга не сломало весь скрипт.
					filters = JSON.parse( raw );
				}
			} catch ( err ) {
				// Игнорируем ошибку парсинга, просто отправим запрос без фильтров.
			}

			// Запускаем процесс экспорта, передавая имя действия, фильтры и кнопку.
			this._doExport( fs_lms_vars.ajax_actions[ action ], filters, $btn );
		} );
	},

	/**
	 * Выполняет AJAX-запрос для генерации и скачивания CSV-файла.
	 *
	 * @private
	 * @param {string} action - Имя WordPress AJAX action (например, 'exportEntityAuditLog').
	 * @param {Object} filters - Объект с дополнительными фильтрами для запроса.
	 * @param {jQuery} $btn - jQuery-объект кнопки экспорта (для управления её состоянием).
	 */
	_doExport( action, filters, $btn ) {
		// Блокируем кнопку и меняем текст, чтобы пользователь понял, что процесс идет.
		// Экспорт может занять несколько секунд, если логов много.
		$btn.prop( 'disabled', true ).text( 'Подготовка…' );

		$.post( fs_lms_vars.ajaxurl, {
			action,
			security: fs_lms_vars.nonces.manager,
			// ОПЕРАТОР SPREAD (...):
			// "Распаковывает" объект filters в текущий объект payload.
			// Если filters = { date_from: '2023-01-01', user_id: 5 },
			// то итоговый объект будет: { action: '...', security: '...', date_from: '...', user_id: 5 }.
			// Это элегантная альтернатива ручному копированию свойств или использованию $.extend().
			...filters,
		} )
			.done( ( res ) => {
				if ( res.success && res.data.url ) {
					// ПАТТЕРН: Скачивание файла через редирект.
					// AJAX сам по себе не умеет сохранять файлы на диск (он работает с текстом/JSON).
					// Поэтому сервер генерирует файл, сохраняет его во временную папку 
					// и возвращает URL для скачивания.
					// Присваивание window.location.href заставляет браузер перейти по этому URL,
					// что инициирует стандартное скачивание файла (появится диалог сохранения).
					window.location.href = res.data.url;
				} else {
					// Если сервер вернул ошибку (например, нет данных для экспорта),
					// показываем модальное окно с текстом ошибки.
					AlertModal.show( res.data || 'Ошибка экспорта' );
				}
			} )
			.fail( () => {
				// Обработка сетевых ошибок (потеря соединения, таймаут, 500 ошибка).
				AlertModal.show( 'Ошибка сервера при экспорте' );
			} )
			.always( () => {
				// ГАРАНТИРОВАННОЕ ВОССТАНОВЛЕНИЕ КНОПКИ:
				// .always() срабатывает в любом случае (успех, ошибка, таймаут).
				// Мы разблокируем кнопку и возвращаем ей исходный вид.
				// ВАЖНО: используем .html(), а не .text(), так как внутри есть HTML-тег <span> с иконкой.
				$btn.prop( 'disabled', false ).html(
					'<span class="dashicons dashicons-download"></span> Экспорт CSV'
				);
			} );
	},
};