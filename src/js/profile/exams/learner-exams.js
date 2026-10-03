/**
 * Экран "Мои экзамены" для ученика и родителя (Этап 5)
 */

let currentRequestKey = null;
let currentSelectedSessionId = null;
let pendingEventId = null; // Для открытия экзамена из расписания (5.5)

export function renderLearnerExams( root ) {
	const { createApi } = window.fsProfileApi;
	const { isParent, childId } = window.fsProfileUtils;

	const api = createApi( window.fsProfile.exams );

	root.innerHTML = `
		<div class="learner-exams-container">
			<div id="exams-tabs" class="exams-tabs sc-tabs" role="tablist">
				<!-- Плитки экзаменов генерируются JS -->
			</div>
			<div id="exams-card" class="prof-card sc-hero" style="display:none;">
				<!-- Карточка текущего экзамена -->
			</div>
			<div id="confirm-dialog" class="confirm-dialog" style="display:none;">
				<!-- Диалог подтверждения -->
			</div>
		</div>
	`;

	// Загружаем список экзаменов
	const params = childId ? { student_person_id: childId } : {};
	api( 'getExams', params )
		.then( response => {
			if ( response.exams && response.exams.length > 0 ) {
				renderExamsTabs( root, response.exams, api, isParent() );

				// Если есть ожидающий eventId (клик из расписания), открыть его
				if ( pendingEventId ) {
					const eventId = pendingEventId;
					pendingEventId = null; // Сбросить флаг
					const exam = response.exams.find( e => e.event_id === eventId );
					if ( exam ) {
						renderExamCard( root, exam, api, isParent() );
						// Скролить к карточке
						setTimeout( () => {
							const card = root.querySelector( '#exams-card' );
							if ( card ) card.scrollIntoView( { behavior: 'smooth' } );
						}, 100 );
					}
				} else if ( response.exams[0] ) {
					// Показать первый экзамен по умолчанию
					renderExamCard( root, response.exams[0], api, isParent() );
				}
			} else {
				showEmptyState( root );
			}
		} )
		.catch( error => {
			const container = root.querySelector( '#exams-tabs' );
			if ( container ) {
				container.innerHTML = `<p class="exams-error">Ошибка загрузки: ${error.message}</p>`;
			}
		} );
}

function renderExamsTabs( root, exams, api, isParent ) {
	const tabsContainer = root.querySelector( '#exams-tabs' );
	if ( ! tabsContainer ) return;

	let html = '';
	exams.forEach( ( exam, index ) => {
		const chipColor = getChipColor( exam.direction );
		const subtitle = getTabSubtitle( exam );
		html += `
			<div class="sc-tab" role="tab" data-exam-id="${exam.event_id}" tabindex="${index === 0 ? 0 : -1}">
				<div class="prof-card-chip ${chipColor}">${exam.direction === 'ege' ? 'ЕГЭ' : 'ОГЭ'}</div>
				<div class="prof-card-title">${exam.title}</div>
				<div class="prof-card-subtitle">${subtitle}</div>
			</div>
		`;
	} );

	tabsContainer.innerHTML = html;

	// Обработчик клика по плитке
	tabsContainer.querySelectorAll( '.sc-tab' ).forEach( tab => {
		tab.addEventListener( 'click', () => {
			const eventId = parseInt( tab.dataset.examId, 10 );
			const exam = exams.find( e => e.event_id === eventId );
			if ( exam ) {
				updateTabSelection( tabsContainer, eventId );
				renderExamCard( root, exam, api, isParent );
			}
		} );
	} );
}

function updateTabSelection( container, eventId ) {
	container.querySelectorAll( '.sc-tab' ).forEach( tab => {
		tab.classList.toggle( 'sc-active', parseInt( tab.dataset.examId, 10 ) === eventId );
		tab.setAttribute( 'tabindex', parseInt( tab.dataset.examId, 10 ) === eventId ? 0 : -1 );
	} );
}

function renderExamCard( root, exam, api, isParent ) {
	const cardContainer = root.querySelector( '#exams-card' );
	if ( ! cardContainer ) return;

	const stateColor = getStateColor( exam.state );
	const actionButtons = renderActionButtons( exam, isParent );
	const registrationInfo = exam.registration ? renderRegistrationInfo( exam.registration ) : '';
	const hintText = getHintText( exam );
	const parentNotice = isParent ? `<div class="sc-notice">Записывается и сдаёт экзамен сам ученик из своего кабинета.</div>` : '';

	const html = `
		<div class="prof-card-header">
			<div>
				<h2 class="prof-card-title">${exam.title}</h2>
				<div class="prof-card-info">
					<span>${exam.registration ? formatDate( exam.registration.date ) + ', ' + exam.registration.time_start : 'Дата не выбрана'}</span>
					${exam.registration && exam.registration.room ? `<span>${exam.registration.room}</span>` : ''}
				</div>
			</div>
			<div class="prof-card-actions">
				<div class="prof-state-pill ${stateColor}">${exam.state_label}</div>
				${actionButtons}
			</div>
		</div>
		${registrationInfo}
		${parentNotice}
		${hintText ? `<div class="sc-hint">${hintText}</div>` : ''}
		<div id="sessions-carousel-wrapper" style="display:none;">
			<!-- Карусель сеансов -->
		</div>
	`;

	cardContainer.innerHTML = html;
	cardContainer.style.display = 'block';
	cardContainer.dataset.examId = exam.event_id;

	// Привязать обработчики действий (только для ученика)
	if ( ! isParent ) {
		attachActionHandlers( cardContainer, exam, api, isParent );
	}
}

function renderActionButtons( exam, isParent ) {
	if ( isParent || ! exam.actions || exam.actions.length === 0 ) {
		return '';
	}

	let html = '<div class="prof-card-actions-list">';
	exam.actions.forEach( action => {
		const label = getActionLabel( action );
		const isDisabled = exam.state === 'not_open' && action === 'register';
		html += `<button class="btn btn-primary ${isDisabled ? 'sc-dis' : ''}" data-action="${action}" data-event-id="${exam.event_id}" ${isDisabled ? 'disabled' : ''}>${label}</button>`;
	} );
	html += '</div>';
	return html;
}

function renderRegistrationInfo( registration ) {
	if ( ! registration ) return '';

	return `
		<div class="prof-card-registration">
			<div class="prof-card-label">Ваша запись:</div>
			<div class="prof-card-date">${formatDate( registration.date )} ${registration.weekday}</div>
			<div class="prof-card-time">${registration.time_start}–${registration.time_end}</div>
			${registration.room ? `<div class="prof-card-room">${registration.room}</div>` : ''}
		</div>
	`;
}

function renderSessionsList( sessions, state, currentSessionId ) {
	if ( ! sessions || sessions.length === 0 ) {
		return '';
	}

	let html = `<div class="exam-slots-carousel" role="region" aria-label="Доступные сеансы">`;

	sessions.forEach( session => {
		const freeText = `осталось ${session.free} ${plural( session.free, 'место', 'места', 'мест' )}`;
		const isSelected = session.session_id === currentSessionId ? ' exam-slot-selected' : '';
		const isSelectable = session.selectable ? '' : ' exam-slot-disabled';
		html += `
			<div class="exam-slot${isSelected}${isSelectable}" data-session-id="${session.session_id}" role="button" tabindex="0">
				<div class="exam-slot-date">${formatDate( session.date )}</div>
				<div class="exam-slot-weekday">${session.weekday}</div>
				<div class="exam-slot-time">${session.time_start}</div>
				<div class="exam-slot-room">${session.room}</div>
				<div class="exam-slot-free">${freeText}</div>
			</div>
		`;
	} );

	html += '</div>';
	return html;
}

function showEmptyState( root ) {
	const tabsContainer = root.querySelector( '#exams-tabs' );
	if ( tabsContainer ) {
		tabsContainer.innerHTML = `
			<div class="exams-empty">
				<p class="exams-empty-text">
					Экзаменов пока нет. Когда преподаватель назначит экзамен, он появится здесь.
				</p>
			</div>
		`;
	}
	const cardContainer = root.querySelector( '#exams-card' );
	if ( cardContainer ) {
		cardContainer.style.display = 'none';
	}
}

function attachActionHandlers( cardElement, exam, api, isParent ) {
	const buttons = cardElement.querySelectorAll( '[data-action]' );
	buttons.forEach( button => {
		button.addEventListener( 'click', async () => {
			const action = button.dataset.action;
			const eventId = parseInt( button.dataset.eventId, 10 );

			try {
				switch ( action ) {
					case 'register':
						handleRegisterAction( cardElement, exam, api );
						break;
					case 'change':
						handleChangeAction( cardElement, exam, api );
						break;
					case 'cancel':
						handleCancelAction( cardElement, exam, api );
						break;
				}
			} catch ( error ) {
				console.error( `Ошибка при выполнении ${action}:`, error );
			}
		} );
	} );
}

function handleRegisterAction( cardElement, exam, api ) {
	// Генерируем request_key для dedup protection
	currentRequestKey = generateUUID();
	currentSelectedSessionId = null;

	// Показываем карусель сеансов
	const wrapper = cardElement.querySelector( '#sessions-carousel-wrapper' );
	if ( wrapper ) {
		wrapper.innerHTML = renderSessionsList( exam.sessions, exam.state, null ) + `
			<div class="exam-registration-actions">
				<button class="btn btn-primary" id="confirm-register" disabled>Подтвердить запись</button>
				<button class="btn btn-secondary" id="cancel-register">Отмена</button>
			</div>
		`;
		wrapper.style.display = 'block';

		// Обработчики для сеансов
		wrapper.querySelectorAll( '.exam-slot' ).forEach( slot => {
			if ( slot.classList.contains( 'exam-slot-disabled' ) ) return;

			slot.addEventListener( 'click', () => {
				wrapper.querySelectorAll( '.exam-slot' ).forEach( s => s.classList.remove( 'exam-slot-selected' ) );
				slot.classList.add( 'exam-slot-selected' );
				currentSelectedSessionId = parseInt( slot.dataset.sessionId, 10 );
				wrapper.querySelector( '#confirm-register' ).disabled = false;
			} );
		} );

		// Кнопка подтверждения
		wrapper.querySelector( '#confirm-register' ).addEventListener( 'click', async () => {
			if ( ! currentSelectedSessionId ) return;

			try {
				const response = await api( 'register', {
					session_id: currentSelectedSessionId,
					request_key: currentRequestKey,
				} );

				// Перезагрузить все карточки
				location.reload();
			} catch ( error ) {
				handleRegistrationError( wrapper, error, api );
			}
		} );

		// Кнопка отмены
		wrapper.querySelector( '#cancel-register' ).addEventListener( 'click', () => {
			wrapper.style.display = 'none';
			currentRequestKey = null;
			currentSelectedSessionId = null;
		} );
	}
}

function handleChangeAction( cardElement, exam, api ) {
	// Генерируем request_key
	currentRequestKey = generateUUID();
	currentSelectedSessionId = exam.registration?.session_id || null;

	const wrapper = cardElement.querySelector( '#sessions-carousel-wrapper' );
	if ( wrapper ) {
		wrapper.innerHTML = renderSessionsList( exam.sessions, exam.state, currentSelectedSessionId ) + `
			<div class="exam-registration-actions">
				<button class="btn btn-primary" id="confirm-change">Подтвердить смену</button>
				<button class="btn btn-secondary" id="cancel-change">Отмена выбора</button>
			</div>
		`;
		wrapper.style.display = 'block';

		// Обработчики для сеансов
		wrapper.querySelectorAll( '.exam-slot' ).forEach( slot => {
			if ( slot.classList.contains( 'exam-slot-disabled' ) ) return;

			slot.addEventListener( 'click', () => {
				wrapper.querySelectorAll( '.exam-slot' ).forEach( s => s.classList.remove( 'exam-slot-selected' ) );
				slot.classList.add( 'exam-slot-selected' );
				currentSelectedSessionId = parseInt( slot.dataset.sessionId, 10 );
			} );
		} );

		// Кнопка подтверждения
		wrapper.querySelector( '#confirm-change' ).addEventListener( 'click', async () => {
			if ( ! currentSelectedSessionId ) return;
			if ( currentSelectedSessionId === exam.registration?.session_id ) {
				alert( 'Выберите другой сеанс' );
				return;
			}

			if ( confirm( `Сменить запись на ${formatDate( exam.sessions.find( s => s.session_id === currentSelectedSessionId )?.date )}, ${exam.sessions.find( s => s.session_id === currentSelectedSessionId )?.time_start}?` ) ) {
				try {
					const response = await api( 'change', {
						session_id: currentSelectedSessionId,
						request_key: currentRequestKey,
					} );

					location.reload();
				} catch ( error ) {
					handleRegistrationError( wrapper, error, api );
				}
			}
		} );

		// Кнопка отмены выбора
		wrapper.querySelector( '#cancel-change' ).addEventListener( 'click', () => {
			wrapper.style.display = 'none';
			currentRequestKey = null;
			currentSelectedSessionId = null;
		} );
	}
}

function handleCancelAction( cardElement, exam, api ) {
	currentRequestKey = generateUUID();

	if ( confirm( 'Отменить запись на экзамен? Место освободится.' ) ) {
		api( 'cancel', {
			event_id: exam.event_id,
			request_key: currentRequestKey,
		} )
			.then( () => location.reload() )
			.catch( error => {
				console.error( 'Ошибка при отмене:', error );
				alert( `Ошибка: ${error.message}` );
			} );
	}
}

function handleRegistrationError( wrapper, error, api ) {
	const code = error.code || '';
	const message = error.message || 'Неизвестная ошибка';

	let errorText = '';
	switch ( code ) {
		case 'X-FULL':
			errorText = 'Это место только что заняли. Выберите другой сеанс.';
			// Перезагрузить список
			api( 'getExams', {} ).then( r => location.reload() );
			break;
		case 'X-HELD':
			errorText = message || 'Часть мест удерживается до оплаты. Попробуйте позже.';
			break;
		case 'X-CLOSED':
			errorText = message || 'Запись закрыта.';
			location.reload();
			break;
		case 'X-CONFLICT':
			errorText = message || 'Конфликт расписания.';
			break;
		default:
			errorText = message;
	}

	const errorDiv = document.createElement( 'div' );
	errorDiv.className = 'sc-notice';
	errorDiv.textContent = errorText;
	wrapper.insertBefore( errorDiv, wrapper.firstChild );
}

function getTabSubtitle( exam ) {
	const state = exam.state;

	switch ( state ) {
		case 'not_open':
			return `Запись с ${formatDate( exam.registration_opens_at )}`;
		case 'open':
			return 'Запись открыта';
		case 'full':
			return 'Свободных мест нет';
		case 'closed':
			return 'Запись закрыта';
		case 'registered':
		case 'entry_open':
			return exam.registration ? `${formatDate( exam.registration.date )}, ${exam.registration.time_start}` : '—';
		case 'in_progress':
			return 'Выполняется';
		case 'awaiting_approval':
			return 'Ожидает утверждения';
		case 'missed':
			return 'Экзамен пропущен';
		case 'cancelled_by_staff':
			return 'Запись отменена';
		case 'event_cancelled':
			return 'Проведение отменено';
		default:
			return '';
	}
}

function getChipColor( direction ) {
	return direction === 'ege' ? 'chip-ege' : 'chip-oge';
}

function getStateColor( state ) {
	const colorMap = {
		'not_open': 'state-gray',
		'open': 'state-green',
		'full': 'state-red',
		'closed': 'state-red',
		'registered': 'state-blue',
		'entry_open': 'state-yellow',
		'in_progress': 'state-yellow',
		'awaiting_approval': 'state-gray',
		'approved': 'state-green',
		'cancelled_by_staff': 'state-red',
		'missed': 'state-red',
		'event_cancelled': 'state-red',
	};
	return colorMap[ state ] || 'state-gray';
}

function getActionLabel( action ) {
	const labels = {
		'register': 'Записаться',
		'change': 'Сменить сеанс',
		'cancel': 'Отменить запись',
		'start': 'Приступить',
		'resume': 'Продолжить',
		'results': 'Результаты',
	};
	return labels[ action ] || action;
}

function getHintText( exam ) {
	const state = exam.state;

	switch ( state ) {
		case 'not_open':
			return `Запись откроется ${formatDate( exam.registration_opens_at )}`;
		case 'registered':
			return `Кнопка станет активной в ${exam.registration ? exam.registration.time_start : ''}. Смена и отмена записи — до начала выбранного сеанса.`;
		case 'entry_open':
			return 'Начать можно до конца экзамена';
		case 'full':
			return `Свободных мест нет. Обратитесь к преподавателю: ${exam.teacher_name}.`;
		case 'closed':
			return `Запись закрыта. Обратитесь к преподавателю: ${exam.teacher_name}.`;
		case 'awaiting_approval':
			return 'Работа сдана и ожидает утверждения преподавателем.';
		case 'event_cancelled':
			return `Проведение отменено. Причина: ${exam.last_reason || 'не указана'}.`;
		default:
			return '';
	}
}

function formatDate( dateStr ) {
	if ( ! dateStr ) return '—';
	const date = new Date( dateStr );
	return date.toLocaleDateString( 'ru-RU', { day: 'numeric', month: 'long', year: 'numeric' } );
}

function plural( n, form1, form2, form5 ) {
	const mod10 = n % 10;
	const mod100 = n % 100;
	if ( mod10 === 1 && mod100 !== 11 ) return form1;
	if ( mod10 >= 2 && mod10 <= 4 && ( mod100 < 12 || mod100 > 14 ) ) return form2;
	return form5;
}

function generateUUID() {
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function( c ) {
		const r = ( Math.random() * 16 ) | 0;
		const v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
		return v.toString( 16 );
	} );
}

/**
 * Открывает карточку экзамена по ID из расписания (5.5)
 * Сохраняет eventId, переходит на экран, и renderLearnerExams откроет нужную карточку
 */
export function openLearnerExam( eventId ) {
	pendingEventId = eventId;
	// Приложение (app.js) отвечает за переход на экран learner-exams
}
