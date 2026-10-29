<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Modules\Masterclass\Services\MasterclassConversionService;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassEnrollmentController
 *
 * Мост между воронкой мастер-класса и заявкой/зачислением ядра, только общие хуки:
 * - `fs_lms_application_created` — заявку подал залогиненный ребёнок: привязать её к его аккаунту (аккаунт — из сессии);
 * - `fs_lms_student_enrolled` — ученика зачислили: аккаунт становится «зачислен», имя учётки — настоящее;
 * - `fs_lms_apply_prefill` (Ш5) — форма заявки для ребёнка с аккаунтом открывается с направлением и школой;
 * - `fs_lms_apply_response` — к ответу формы добавляется подсказка «входите с новыми логином и паролем»: привязка
 *   заявки меняет логин и пароль учётки и обрывает сессию ребёнка.
 *
 * Обработчики — однострочные вызовы сервиса. Сбой в них не должен ломать заявку, зачисление или показ формы.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassEnrollmentController {

	/** Подсказка на экране успеха заявки: старые логин и пароль перестали работать. */
	public const CREDENTIALS_NOTICE = 'Теперь входите с новыми логином и паролем, которые вы указали в заявке: прежние из флаера больше не работают.';

	/** @var array<int, true> Заявки, привязанные к аккаунту в этом запросе (хук создания идёт раньше фильтра ответа). */
	private array $bound = array();

	public function __construct(
		private readonly MasterclassConversionService $conversion,
	) {}

	public function register(): void {
		add_action( 'fs_lms_application_created', array( $this, 'onApplicationCreated' ) );
		add_action( 'fs_lms_student_enrolled', array( $this, 'onStudentEnrolled' ), 10, 2 );
		add_filter( 'fs_lms_apply_prefill', array( $this, 'filterApplyPrefill' ) );
		add_filter( 'fs_lms_apply_response', array( $this, 'filterApplyResponse' ), 10, 2 );
	}

	/** Гость (0) и пользователь без аккаунта мастер-класса — ничего: сервис сам отказывает без записи в журнал. */
	public function onApplicationCreated( int $applicationId ): void {
		$userId = get_current_user_id();
		if ( $userId <= 0 ) {
			return;
		}

		if ( $this->conversion->bindApplication( $applicationId, $userId ) ) {
			$this->bound[ $applicationId ] = true;
		}
	}

	/**
	 * Ответ формы заявки: у привязанной к аккаунту заявки — подсказка про новые логин и пароль (после неё сессия
	 * ребёнка уже недействительна). Чужие ответы и сообщения других модулей сохраняются.
	 *
	 * @param mixed $response      Ответ AJAX
	 * @param mixed $applicationId Номер заявки
	 *
	 * @return array<string, mixed>
	 */
	public function filterApplyResponse( mixed $response, mixed $applicationId = 0 ): array {
		$response = is_array( $response ) ? $response : array();
		if ( ! isset( $this->bound[ (int) $applicationId ] ) ) {
			return $response;
		}

		$existing           = trim( (string) ( $response['notice'] ?? '' ) );
		$response['notice'] = '' === $existing ? self::CREDENTIALS_NOTICE : $existing . ' ' . self::CREDENTIALS_NOTICE;

		return $response;
	}

	public function onStudentEnrolled( int $recordId, int $studentPersonId ): void {
		try {
			$this->conversion->markEnrolled( $studentPersonId );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassEnrollment', $e, array( 'hook' => 'fs_lms_student_enrolled' ), true );
		}
	}

	/**
	 * Ш5: предзаполняет форму, только если её ещё никто не заполнил (ссылка гостя экзамена главнее) и посетитель вошёл.
	 *
	 * @param mixed $prefill Поля формы, собранные раньше
	 *
	 * @return array<string, mixed>
	 */
	public function filterApplyPrefill( mixed $prefill ): array {
		$prefill = is_array( $prefill ) ? $prefill : array();
		if ( array() !== $prefill || ! is_user_logged_in() ) {
			return $prefill;
		}

		try {
			return $this->conversion->prefill( get_current_user_id() );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassEnrollment', $e, array( 'hook' => 'fs_lms_apply_prefill' ), true );

			return $prefill;
		}
	}
}
