/**
 * Экран "Мои экзамены" для ученика и родителя (Этап 5)
 */

export function renderLearnerExams( root ) {
	const { createApi } = window.fsProfileApi;
	const { isParent } = window.fsProfileUtils;
	const { childId } = window.fsProfileUtils;

	const api = createApi( window.fsProfile.exams );

	root.innerHTML = `
		<div class="learner-exams-container">
			<div class="exam-tabs-wrapper">
				<div class="exams-empty">
					<p class="exams-empty-text">
						Экзаменов пока нет. Когда преподаватель назначит экзамен, он появится здесь.
					</p>
				</div>
			</div>
		</div>
	`;

	// Загружаем список экзаменов
	const params = childId ? { student_person_id: childId } : {};
	api( 'getExams', params )
		.then( response => {
			if ( response.exams && response.exams.length > 0 ) {
				renderExamsTabs( root, response.exams, api, isParent() );
			}
		} )
		.catch( error => {
			root.querySelector( '.exams-empty' ).innerHTML = `
				<p class="exams-error">Ошибка загрузки: ${error.message}</p>
			`;
		} );
}

function renderExamsTabs( root, exams, api, isParent ) {
	// TODO: Реализация в 5.2
	// Рендер плиток и карточки экзамена
}

/**
 * Открывает карточку экзамена по ID из расписания (5.5.3)
 */
export function openLearnerExam( eventId ) {
	// TODO: Реализация в 5.5
}
