<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Repositories\AcademicPeriodRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class AcademicPeriodCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AcademicPeriodRepository $period_repository
	) {
		parent::__construct();
	}

	public function ajaxSaveAcademicPeriod(): void {
		$this->authorize( Nonce::Manager );

		$id          = $this->requireKey( 'id', error: 'Ключ периода обязателен.' );
		$name        = $this->requireText( 'name', error: 'Название периода обязательно.' );
		$start_date  = $this->requireText( 'start_date', error: 'Дата начала обязательна.' );
		$end_date    = $this->requireText( 'end_date', error: 'Дата окончания обязательна.' );
		$is_current  = $this->sanitizeBool( 'is_current' );
		$action_type = $this->sanitizeText( 'action_type' ) === 'edit' ? 'edit' : 'add';

		if ( 'add' === $action_type && null !== $this->period_repository->getById( $id ) ) {
			$this->error( 'Период с таким техническим ID уже существует.' );
		}

		$saved = $this->period_repository->update( array(
			'id'         => $id,
			'name'       => $name,
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'is_current' => $is_current,
		) );

		$this->respond(
			$saved,
			error_msg:   'Не удалось сохранить данные в базу данных.',
			success_msg: 'Период успешно сохранён.'
		);
	}

	public function ajaxDeleteAcademicPeriod(): void {
		$this->authorize( Nonce::Manager );

		$id = $this->requireKey( 'id', error: 'Не указан идентификатор периода.' );

		if ( null === $this->period_repository->getById( $id ) ) {
			$this->error( 'Удаляемый период не найден в системе.' );
		}

		$deleted = $this->period_repository->delete( array( 'id' => $id ) );

		$this->respond(
			$deleted,
			error_msg:   'Не удалось удалить период из базы данных.',
			success_msg: 'Период успешно удалён.'
		);
	}
}
