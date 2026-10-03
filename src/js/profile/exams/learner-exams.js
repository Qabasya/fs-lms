/**
 * Экран "Мои экзамены" для ученика и родителя (Этап 5)
 */

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
		</div>
	`;

	// Загружаем список экзаменов
	const params = childId ? { student_person_id: childId } : {};
	api( 'getExams', params )
		.then( response => {
			if ( response.exams && response.exams.length > 0 ) {
				renderExamsTabs( root, response.exams, api, isParent() );
				// Показать первый экзамен по умолчанию
				if ( response.exams[0] ) {
					renderExamCard( root, response.exams[0], api );
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
				renderExamCard( root, exam, api );
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

function renderExamCard( root, exam, api ) {
	const cardContainer = root.querySelector( '#exams-card' );
	if ( ! cardContainer ) return;

	const stateColor = getStateColor( exam.state );
	const actionButtons = renderActionButtons( exam );
	const registrationInfo = exam.registration ? renderRegistrationInfo( exam.registration ) : '';
	const sessionsList = renderSessionsList( exam.sessions, exam.state );
	const hintText = getHintText( exam );

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
		${hintText ? `<div class="sc-hint">${hintText}</div>` : ''}
		${sessionsList}
	`;

	cardContainer.innerHTML = html;
	cardContainer.style.display = 'block';

	// Привязать обработчики действий
	const cardElement = cardContainer.querySelector( '.prof-card-header' );
	if ( cardElement ) {
		attachActionHandlers( cardElement, exam, api );
	}
}

function renderActionButtons( exam ) {
	if ( ! exam.actions || exam.actions.length === 0 ) {
		return '';
	}

	let html = '<div class="prof-card-actions-list">';
	exam.actions.forEach( action => {
		const label = getActionLabel( action );
		const isDisabled = exam.state === 'not_open' && action === 'register';
		html += `<button class="btn btn-primary ${isDisabled ? 'sc-dis' : ''}" data-action="${action}" ${isDisabled ? 'disabled' : ''}>${label}</button>`;
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

function renderSessionsList( sessions, state ) {
	if ( ! sessions || sessions.length === 0 ) {
		return '';
	}

	// Только показываем при статусе open/registered/entry_open
	if ( ! [ 'open', 'registered', 'entry_open', 'change' ].includes( state ) ) {
		return '';
	}

	let html = `<div class="exam-slots-carousel" style="display:none;" data-carousel="sessions">`;

	sessions.forEach( session => {
		const freeText = `осталось ${session.free} ${plural( session.free, 'место', 'места', 'мест' )}`;
		const isSelected = session.is_current ? ' exam-slot-selected' : '';
		html += `
			<div class="exam-slot${isSelected}" data-session-id="${session.session_id}">
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

function getTabSubtitle( exam ) {
	const state = exam.state;
	const now = new Date();

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
	const now = new Date();

	switch ( state ) {
		case 'not_open':
			return `Запись откроется ${formatDate( exam.registration_opens_at )} в ${exam.registration_opens_at ? new Date( exam.registration_opens_at ).toLocaleTimeString( 'ru-RU', { hour: '2-digit', minute: '2-digit' } ) : ''}`;
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

function attachActionHandlers( cardElement, exam, api ) {
	const buttons = cardElement.querySelectorAll( '[data-action]' );
	buttons.forEach( button => {
		button.addEventListener( 'click', async () => {
			const action = button.dataset.action;
			try {
				switch ( action ) {
					case 'register':
						// TODO: Открыть карусель сеансов (5.3)
						break;
					case 'change':
						// TODO: Смена сеанса (5.3)
						break;
					case 'cancel':
						// TODO: Отмена записи (5.3)
						break;
				}
			} catch ( error ) {
				console.error( `Ошибка при выполнении ${action}:`, error );
			}
		} );
	} );
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

/**
 * Открывает карточку экзамена по ID из расписания (5.5.3)
 */
export function openLearnerExam( eventId ) {
	// TODO: Реализация в 5.5
}
