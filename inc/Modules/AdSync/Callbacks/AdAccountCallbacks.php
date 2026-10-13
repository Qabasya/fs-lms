<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdAccountCallbacks
 *
 * Доменная учётка из окна заявки: состояние (для показа кнопки) и создание вручную.
 * Вручную учётку создают заявкам, поданным не из доверенной сети, — таким она при подаче
 * не ставится ({@see \Inc\Modules\AdSync\Controllers\AdSyncController::onApplicationCreated()}).
 *
 * Право — то же, что у временного доступа: обе кнопки стоят в одном окне и решают одну
 * задачу — пустить ученика до зачисления.
 *
 * @package Inc\Modules\AdSync\Callbacks
 */
class AdAccountCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/** Сколько заданий отправить вместе с созданной учёткой — как после события заявки. */
	private const int FLUSH_LIMIT = 5;

	private const array LABELS = array(
		'creatable' => 'Создать учётку',
		'pending'   => 'Учётка в очереди',
		'done'      => 'Учётка создана',
		'failed'    => 'Учётка не создана',
	);

	private const array HINTS = array(
		'creatable' => 'Создать учётную запись в домене по логину и паролю из заявки.',
		'pending'   => 'Задание отправится в офис при следующей доставке.',
		'done'      => 'Учётная запись в домене уже создана.',
		'failed'    => 'Офис отклонил задание. Причина и повтор — «Конфигурация» → «Синхронизация с доменом».',
	);

	public function __construct(
		private readonly AdProvisioningService $service,
		private readonly AdDeliveryService     $delivery,
	) {
		parent::__construct();
	}

	/** AJAX: состояние доменной учётки по заявке. Params: application_id. */
	public function ajaxState(): void {
		$this->authorize( Nonce::Enroll, Capability::EnrollStudent );

		$applicationId = $this->requireInt( 'application_id', error: 'Не указан ID заявки.' );

		$this->success( $this->describe( $this->service->accountStateForApplication( $applicationId ) ) );
	}

	/** AJAX: создать доменную учётку по заявке. Params: application_id. */
	public function ajaxProvision(): void {
		$this->authorize( Nonce::Enroll, Capability::EnrollStudent );

		$applicationId = $this->requireInt( 'application_id', error: 'Не указан ID заявки.' );

		if ( 'creatable' !== $this->service->accountStateForApplication( $applicationId ) ) {
			$this->error( 'Учётку по этой заявке создать нельзя: она уже создана или заявке не положена.' );
			return;
		}

		$this->service->enqueueProvision( $applicationId );
		// Сотрудник ждёт результат у экрана — отправляем сразу, а не после ответа.
		$this->delivery->deliverPending( self::FLUSH_LIMIT );

		$this->success( $this->describe( $this->service->accountStateForApplication( $applicationId ) ) );
	}

	/**
	 * @return array{state: string, label: string, hint: string}
	 */
	private function describe( string $state ): array {
		return array(
			'state' => $state,
			'label' => self::LABELS[ $state ] ?? '',
			'hint'  => self::HINTS[ $state ] ?? '',
		);
	}
}
