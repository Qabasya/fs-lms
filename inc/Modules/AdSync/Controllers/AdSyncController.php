<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Controllers;

use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Callbacks\AdSyncStatusCallbacks;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Modules\AdSync\Services\AdStatusTokenService;

/**
 * Class AdSyncController
 *
 * Рантайм-хуки модуля (только при включённом флаге). Подписан на generic-сеймы ядра:
 * при создании заявки ставит задание провижна в очередь, отправляет его в офис сразу после
 * ответа пользователю (см. {@see flushAfterResponse()}) и вписывает в ответ apply generic-поля
 * `notice` + `poll` (фронт покажет спиннер и опросит статус). Статус отдаёт nopriv-AJAX
 * `fs_lms_ad_status` (обработчик — AdSyncStatusCallbacks, адресация — токеном,
 * а не сырым ID заявки). Ядро о модуле не знает.
 *
 * @package Inc\Modules\AdSync\Controllers
 */
class AdSyncController {

	/** Собственное имя nopriv-AJAX статуса провижна (вне core AjaxHook — изоляция). */
	public const STATUS_ACTION = 'fs_lms_ad_status';

	/** Сколько заданий отправить сразу после события (остальное — cron раз в минуту). */
	private const int FLUSH_LIMIT = 5;

	private bool $flushScheduled = false;

	public function __construct(
		private readonly AdProvisioningService $service,
		private readonly AdDeliveryService     $delivery,
		private readonly AdStatusTokenService  $tokens,
		private readonly AdSyncStatusCallbacks $statusCallbacks,
	) {}

	public function register(): void {
		// Provision + статус для фронта.
		add_action( 'fs_lms_application_created', array( $this, 'onApplicationCreated' ) );
		add_filter( 'fs_lms_apply_response', array( $this, 'filterApplyResponse' ), 10, 2 );
		add_action( 'wp_ajax_nopriv_' . self::STATUS_ACTION, array( $this->statusCallbacks, 'ajaxStatus' ) );
		add_action( 'wp_ajax_' . self::STATUS_ACTION, array( $this->statusCallbacks, 'ajaxStatus' ) );

		// Этап 3: deprovision — заявка истекла/в корзину (до зачисления) либо ученик отчислен (после).
		add_action( 'fs_lms_application_expired', array( $this, 'onApplicationExpired' ) );
		add_action( 'fs_lms_application_trashed', array( $this, 'onApplicationTrashed' ) );
		add_action( 'fs_lms_application_student_name_changed', array( $this, 'onStudentNameChanged' ) );
		add_action( 'fs_lms_application_permanently_deleted', array( $this, 'onApplicationDeleted' ), 10, 2 );
		add_action( 'fs_lms_student_expelled', array( $this, 'onStudentExpelled' ), 10, 2 );

		// Повторное зачисление — вернуть учётку из «Отчисленных» в OU нового направления;
		// смена пароля администратором — тот же пароль в AD.
		add_action( 'fs_lms_student_enrolled', array( $this, 'onStudentEnrolled' ), 10, 2 );
		add_action( 'fs_lms_user_password_changed', array( $this, 'onPasswordChanged' ) );
	}

	public function onApplicationCreated( int $applicationId ): void {
		$this->service->enqueueProvision( $applicationId );
		$this->scheduleFlush();
	}

	public function onApplicationExpired( int $applicationId ): void {
		$this->service->enqueueDeprovisionByApplication( $applicationId );
		$this->scheduleFlush();
	}

	public function onApplicationTrashed( int $applicationId ): void {
		$this->service->enqueueDeprovisionByApplication( $applicationId );
		$this->scheduleFlush();
	}

	public function onStudentNameChanged( int $applicationId ): void {
		$this->service->enqueueNameUpdate( $applicationId );
		$this->scheduleFlush();
	}

	public function onApplicationDeleted( int $applicationId, ?int $personId ): void {
		$this->service->enqueueDeleteByApplication( $applicationId, $personId );
		$this->scheduleFlush();
	}

	public function onStudentExpelled( int $recordId, int $personId ): void {
		$this->service->enqueueDeprovisionByPerson( $personId );
		$this->scheduleFlush();
	}

	public function onStudentEnrolled( int $recordId, int $personId ): void {
		$this->service->enqueueReactivation( $recordId, $personId );
		$this->scheduleFlush();
	}

	public function onPasswordChanged( int $userId ): void {
		$this->service->enqueuePasswordChange( $userId );
		$this->scheduleFlush();
	}

	/** Отправка в офис — один раз за запрос, в самом конце. */
	private function scheduleFlush(): void {
		if ( $this->flushScheduled ) {
			return;
		}
		$this->flushScheduled = true;
		add_action( 'shutdown', array( $this, 'flushAfterResponse' ), 100 );
	}

	/**
	 * Сначала отдаём ответ пользователю (форма заявки не ждёт офис), потом шлём
	 * задания. Без FastCGI/LiteSpeed ответ уйдёт после отправки — не дольше
	 * таймаута одного задания; при простое офиса доставка на паузе и не ждёт вовсе.
	 */
	public function flushAfterResponse(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		$this->delivery->deliverPending( self::FLUSH_LIMIT );
	}

	/**
	 * Добавляет в ответ apply generic-поля: `notice` (сообщение) и `poll` (инструкция опроса статуса).
	 * Ядро (apply-form.js) покажет notice + спиннер и будет опрашивать poll.action до терминального статуса.
	 * В `ref` уходит непредсказуемый токен (2S2), сырой ID заявки наружу не публикуется.
	 */
	public function filterApplyResponse( array $response, int $applicationId ): array {
		// Провижн не ставился (направление вне provision_subjects) — спиннер/поллинг не нужны.
		// Порядок гарантирован: fs_lms_application_created срабатывает до этого фильтра в том же запросе.
		if ( 'none' === $this->service->statusForApplication( $applicationId ) ) {
			return $response;
		}

		$response['notice'] = 'Создаём учётную запись в домене…';
		$response['poll']   = array(
			'action'   => self::STATUS_ACTION,
			'nonce'    => Nonce::Apply->create(),
			'ref'      => $this->tokens->issue( $applicationId ),
			'interval' => 2500, // мс между опросами
			'max'      => 40,   // максимум опросов (~100с), затем стоп
		);
		return $response;
	}
}
