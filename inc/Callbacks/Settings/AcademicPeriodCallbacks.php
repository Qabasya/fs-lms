<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\BaseController;
use Inc\DTO\AcademicPeriodDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\Nonce;
use Inc\Enums\OperationType;
use Inc\Services\Enrollment\AcademicPeriodService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AcademicPeriodCallbacks
 *
 * AJAX-обработчики для управления учебными периодами (годами/семестрами).
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Сохранение периода** — создание или обновление учебного периода с валидацией дат.
 * 2. **Удаление периода** — удаление существующего учебного периода по ID.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику AcademicPeriodService.
 * Отвечает только за авторизацию, валидацию входных данных и отправку ответа.
 */
class AcademicPeriodCallbacks extends BaseController {
	use Authorizer;  // Трейт с методами authorize(), respond(), error()
	use Sanitizer;   // Трейт с методами requireKey(), requireText(), sanitizeBool()

	/**
	 * Конструктор.
	 *
	 * @param AcademicPeriodService $period_service Сервис управления учебными периодами
	 */
	public function __construct(
		private readonly AcademicPeriodService       $period_service,
		private readonly LogEventDispatcherInterface $logEvents,
	) {
		parent::__construct();
	}

	/**
	 * Сохраняет (создаёт или обновляет) учебный период.
	 *
	 * @return void
	 */
	public function ajaxSaveAcademicPeriod(): void {
		$this->authorize( Nonce::Manager );

		// Валидация входных данных
		$id          = $this->requireKey( 'id', error: 'Ключ периода обязателен.' );
		$name        = $this->requireText( 'name', error: 'Название периода обязательно.' );
		$start_date  = $this->requireText( 'start_date', error: 'Дата начала обязательна.' );
		$end_date    = $this->requireText( 'end_date', error: 'Дата окончания обязательна.' );
		$is_current  = $this->sanitizeBool( 'is_current' );
		$action_type = 'edit' === $this->sanitizeText( 'action_type' ) ? 'edit' : 'add';

		// Проверка на дубликат ID при создании
		if ( 'add' === $action_type && null !== $this->period_service->getById( $id ) ) {
			$this->error( 'Период с таким техническим ID уже существует.', array( 'error_code' => 'duplicate_id' ) );
		}

		$isNew    = 'add' === $action_type;
		$oldLabel = $isNew ? null : $this->period_service->getById( $id )?->name;

		$dto   = new AcademicPeriodDTO( $id, $name, $start_date, $end_date, $is_current );
		$saved = $this->period_service->savePeriod( $dto );

		if ( $saved ) {
			$this->logEvents->dispatch(
				$isNew ? LogEvent::PeriodCreated : LogEvent::PeriodUpdated,
				new EntityChangedEvent(
					get_current_user_id(),
					$isNew ? OperationType::Create : OperationType::Update,
					EntityType::Period,
					null,
					$isNew ? null : $oldLabel,
				)
			);
		}

		$this->respond(
			$saved,
			error_msg:   false === $saved ? 'Проверьте хронологию дат: дата начала должна быть раньше даты окончания.' : 'Не удалось сохранить данные в базу данных.',
			success_msg: 'Период успешно сохранён.'
		);
	}

	/**
	 * Удаляет учебный период по ID.
	 *
	 * @return void
	 */
	public function ajaxDeleteAcademicPeriod(): void {
		$this->authorize( Nonce::Manager );

		$id = $this->requireKey( 'id', error: 'Не указан идентификатор периода.' );

		// Проверка существования периода перед удалением
		if ( null === $this->period_service->getById( $id ) ) {
			$this->error( 'Удаляемый период не найден в системе.' );
		}

		$oldLabel = $this->period_service->getById( $id )?->name;
		$deleted  = $this->period_service->deletePeriod( $id );

		if ( $deleted ) {
			$this->logEvents->dispatch(
				LogEvent::PeriodDeleted,
				new EntityChangedEvent( get_current_user_id(), OperationType::Delete, EntityType::Period, null, $oldLabel )
			);
		}

		$this->respond(
			$deleted,
			error_msg:   'Не удалось удалить период из базы данных.',
			success_msg: 'Период успешно удалён.'
		);
	}
}
