<?php

declare( strict_types=1 );

namespace Inc\Enums\Wp;

/**
 * Enum PageRoutes
 *
 * Перечисление маршрутов (slug) для служебных страниц плагина.
 *
 * @package Inc\Enums
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение слагов страниц** — централизованное хранение идентификаторов страниц.
 * 2. **Генерация URL** — построение полного URL для страницы.
 * 3. **Проверка текущей страницы** — определение, находится ли пользователь на этой странице.
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthPageController, ProfileController и PageGeneratorService
 * для единообразной работы со служебными страницами плагина (вход, регистрация, профиль).
 */
enum PageRoutes: string {

	/** Страница авторизации (вход в личный кабинет) */
	case SignIn      = 'sign-in';

//	/** Страница регистрации нового пользователя */
//	case SignUp      = 'sign-up';

	/** Страница подачи заявки на обучение */
	case Apply = 'apply';

	/** Форма записи гостя на экзамен по ссылке школы (страницу создаёт этап 11a; здесь только слаг для адреса ссылки) */
	case ExamSignup = 'exam-signup';

	/** Вход гостя на экзамен по личной ссылке от сотрудника (этап 11b) */
	case ExamEntry = 'exam-entry';

	/** Результат гостя сразу после сдачи и по личной ссылке результата (этап 11b) */
	case ExamResult = 'exam-result';

	/** Страница личного кабинета пользователя */
	case UserProfile = 'profile';

	/** Плеер урока: занятие группы по `?gid=N&gl=M` (см. LessonPlayerController) */
	case LessonPlayer = 'lesson';

	/** Preview-плеер курса для преподавателя/офиса/автора (Фаза 5, ?course=N) */
	case CoursePreview = 'course-preview';

	/**
	 * Возвращает полный абсолютный URL для текущего маршрута.
	 *
	 * @return string
	 */
	public function url(): string {
		// home_url() — возвращает URL главной страницы сайта
		// esc_url() — экранирует URL для безопасного вывода в HTML
		return esc_url( home_url( '/' . $this->value . '/' ) );
	}

	/**
	 * Deep-link в плеер урока занятия: маршрут плеера + `?gid=&gl=`.
	 * Единый владелец формата (Р2.2) — раньше был скопирован в LearnerService/
	 * DashboardService/ScheduleService и inline в AssessmentPageController.
	 * Осмысленно для {@see self::LessonPlayer}.
	 *
	 * @param int $groupId       ID группы
	 * @param int $groupLessonId ID занятия группы (group_lessons.id)
	 *
	 * @return string
	 */
	public function lessonUrl( int $groupId, int $groupLessonId ): string {
		return (string) add_query_arg(
			array(
				'gid' => $groupId,
				'gl'  => $groupLessonId,
			),
			$this->url()
		);
	}

	/**
	 * Deep-link на экран кабинета: `/profile/?screen=<ключ>` (ключи — `src/js/profile/app.js`, список экранов).
	 * Осмысленно для {@see self::UserProfile}.
	 *
	 * @param string $screen Ключ экрана, напр. `learner-exams`
	 *
	 * @return string
	 */
	public function screenUrl( string $screen ): string {
		return (string) add_query_arg( array( 'screen' => $screen ), $this->url() );
	}

	/**
	 * Проверяет, находится ли пользователь сейчас на этой странице.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		// is_page() — WordPress-функция для проверки текущей страницы по slug/ID
		return is_page( $this->value );
	}
}
