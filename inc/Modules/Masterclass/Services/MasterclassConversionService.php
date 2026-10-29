<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassContactKind;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;

/**
 * Class MasterclassConversionService
 *
 * Воронка «вошёл → вернулся домой → нажал «Связаться» → подал заявку → зачислен». Здесь пока только
 * {@see self::logContact()} (этап 3); привязка заявки, зачисление и предзаполнение формы — этап 5, конструктор
 * тогда получит остальные зависимости (`CONTRACTS.md` §8).
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassConversionService {

	public function __construct(
		private readonly MasterclassAccountRepository $accounts,
		private readonly MasterclassLogWriter $log,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Клик в окне «Связаться». Любой вид клика ставит одноразовую отметку «связался»; каждый клик попадает в журнал.
	 * Не аккаунт мастер-класса (или удалён/зачислен) — молча ничего: по ответу запроса это не должно быть видно.
	 */
	public function logContact( int $userId, MasterclassContactKind $kind ): void {
		$account = $this->accounts->findByUserId( $userId );
		if ( null === $account || $account->isDeleted() || MasterclassAccountState::Enrolled === $account->state ) {
			return;
		}

		$this->accounts->markContact( $account->id, $this->clock->now( 'mysql', true ) );

		$this->log->write(
			MasterclassLogAction::fromContact( $kind ),
			array(
				'account_id'     => $account->id,
				'masterclass_id' => $account->masterclassId,
				'actor'          => $userId,
			)
		);
	}
}
