<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\LoginPlace;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassLoginTracker
 *
 * Куда вошёл ребёнок под временным аккаунтом: до начала (проверка флаера заранее), на месте (в окне мастер-класса)
 * или дома (после окна либо повторно позже `home_timeout_minutes`). От этого зависит воронка («вошёл → вернулся
 * домой») и баннер плеера.
 *
 * Отметки «первый вход» и «дома» — одноразовые условные `UPDATE … IS NULL`: журнал пишет тот вызов, который отметку
 * поставил, повторные входы журнал не засоряют. IP пишется только здесь — для разбора, кто и откуда вошёл.
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassLoginTracker {

	/** Запас до начала окна для обхода капчи: класс проверяет флаеры заранее, у всех один IP. */
	public const CAPTCHA_LEAD_MINUTES = 30;

	public function __construct(
		private readonly MasterclassAccountRepository $accounts,
		private readonly MasterclassRepository $masterclasses,
		private readonly MasterclassConfig $config,
		private readonly MasterclassLogWriter $log,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Вход (`wp_login`). Не аккаунт мастер-класса — `null`, ничего не пишется.
	 */
	public function onLogin( \WP_User $user, string $ip ): ?LoginPlace {
		$account     = $this->liveAccount( (int) $user->ID );
		$masterclass = null === $account ? null : $this->masterclasses->find( $account->masterclassId );
		if ( null === $account || null === $masterclass ) {
			return null;
		}

		$now   = $this->nowUtc();
		$place = $this->classify( $masterclass, $account, $now );

		if ( LoginPlace::BeforeStart === $place ) {
			$this->record( MasterclassLogAction::LoginBeforeStart, $account, $user, $ip );

			return $place;
		}

		$first = $this->accounts->markFirstLogin( $account->id, $now );
		$home  = LoginPlace::AtHome === $place && $this->accounts->markHome( $account->id, $now );

		if ( $home ) {
			$this->record( MasterclassLogAction::LoginAtHome, $account, $user, $ip );
		} elseif ( $first && LoginPlace::OnSite === $place ) {
			$this->record( MasterclassLogAction::LoginOnSite, $account, $user, $ip );
		}

		return $place;
	}

	/**
	 * Открыт плеер: после окна ребёнок «дома», даже если сессия жива с утра (входа не было — `onLogin()` не вызывался).
	 */
	public function onPlayerOpen( int $userId ): void {
		$account     = $this->liveAccount( $userId );
		$masterclass = null === $account ? null : $this->masterclasses->find( $account->masterclassId );
		if ( null === $account || null === $masterclass ) {
			return;
		}

		$now = $this->nowUtc();
		if ( $now <= $masterclass->endsAt || ! $this->accounts->markHome( $account->id, $now ) ) {
			return;
		}

		$this->log->write(
			MasterclassLogAction::LoginAtHome,
			array(
				'account_id'     => $account->id,
				'masterclass_id' => $account->masterclassId,
				'actor'          => $userId,
				'context'        => array( 'source' => 'player' ),
			)
		);
	}

	/**
	 * Где вошёл ребёнок. Чистая функция времени:
	 * до `startsAt` — «до начала»; первый вход — «на месте» в окне и «дома» после него; повторный — «дома», если окно
	 * кончилось или с первого входа прошло не меньше `home_timeout_minutes`.
	 */
	public function classify( MasterclassDTO $masterclass, MasterclassAccountDTO $account, string $nowUtc ): LoginPlace {
		if ( $nowUtc < $masterclass->startsAt ) {
			return LoginPlace::BeforeStart;
		}

		if ( $nowUtc > $masterclass->endsAt ) {
			return LoginPlace::AtHome;
		}

		if ( null === $account->firstLoginAt ) {
			return LoginPlace::OnSite;
		}

		$elapsed = strtotime( $nowUtc . ' UTC' ) - strtotime( $account->firstLoginAt . ' UTC' );

		return $elapsed >= $this->config->homeTimeoutMinutes() * 60 ? LoginPlace::AtHome : LoginPlace::OnSite;
	}

	/**
	 * Шов Ш6: логин `student-N` живого аккаунта, чей мастер-класс идёт (или начнётся в ближайшие полчаса), входит без
	 * лимита смягчённой капчи: целый класс за одним IP упёрся бы в лимит ядра. Любой сбой — отказ.
	 */
	public function allowsCaptchaBypass( string $login ): bool {
		if ( 1 !== preg_match( '/^' . preg_quote( MasterclassAccountService::LOGIN_PREFIX, '/' ) . '([1-9][0-9]*)$/', $login, $m ) ) {
			return false;
		}

		try {
			$account = $this->accounts->find( (int) $m[1] );
			if ( null === $account || ! self::isLive( $account ) ) {
				return false;
			}

			foreach ( $this->masterclasses->runningFor( $this->nowUtc(), self::CAPTCHA_LEAD_MINUTES ) as $running ) {
				if ( $running->id === $account->masterclassId ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassLoginTracker', $e, array( 'seam' => 'fs_lms_captcha_fallback_unlimited' ), true );
		}

		return false;
	}

	private function liveAccount( int $userId ): ?MasterclassAccountDTO {
		$account = $this->accounts->findByUserId( $userId );

		return null !== $account && self::isLive( $account ) ? $account : null;
	}

	private static function isLive( MasterclassAccountDTO $account ): bool {
		return ! $account->isDeleted() && MasterclassAccountState::Enrolled !== $account->state;
	}

	private function record( MasterclassLogAction $action, MasterclassAccountDTO $account, \WP_User $user, string $ip ): void {
		$this->log->write(
			$action,
			array(
				'account_id'     => $account->id,
				'masterclass_id' => $account->masterclassId,
				'actor'          => (int) $user->ID,
				'context'        => array( 'ip' => $ip ),
			)
		);
	}

	private function nowUtc(): string {
		return $this->clock->now( 'mysql', true );
	}
}
