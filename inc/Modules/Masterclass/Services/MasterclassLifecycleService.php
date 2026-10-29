<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassLogRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassLifecycleService
 *
 * Жизненный цикл после занятия: тик раз в 15 минут пишет заморозку в журнал, удаляет неиспользованные и просроченные
 * аккаунты порциями, сносит группу, когда удалять больше некого, подчищает зависшие номера и старый журнал. Те же
 * правила применяют два синхронных входа: удаление группы любым путём (Ш3) и удаление пользователя WordPress.
 *
 * ### Идемпотентность
 *
 * Каждый шаг выбирает только необработанное (`frozen_at`, `unused_purged_at`, `purged_at`, `deleted_at`), поэтому
 * повторный или параллельный тик ничего не удваивает; параллельность дополнительно закрывает замок крона. Общий бюджет
 * `ACCOUNTS_PER_TICK` ограничивает число удалений за тик (шаги 2–3): удаление аккаунта — это ядро (учётка, физлицо,
 * записи, аудит), и пачка в 500 штук не должна держать один запрос крона.
 *
 * ### Аккаунт с заявкой
 *
 * Явное «Удалить» его отвязывает (снимается только временная запись, учётка и физлицо остаются за заявкой), а вышедший
 * срок без «Удалить» оставляет как есть: группа живёт, пока такой аккаунт держит запись, и снесётся, когда заявка
 * разрешится. Пропущенные аккаунты обходятся курсором по номеру, чтобы не занимать окно выборки следующего тика.
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassLifecycleService {

	/** Сколько аккаунтов тик удаляет или отвязывает за один запуск (общий бюджет шагов 2–3). */
	public const ACCOUNTS_PER_TICK = 60;

	/** Размер одной выборки аккаунтов из БД. */
	private const FETCH_SIZE = 25;

	/** Сколько мастер-классов за шаг. */
	private const MASTERCLASSES_PER_STEP = 20;

	/** Номер без учётки «зависает» (сбой на полпути) и после этого срока подчищается. */
	private const STALE_AFTER_SECONDS = 3600;

	public function __construct(
		private readonly MasterclassRepository $masterclasses,
		private readonly MasterclassAccountRepository $accounts,
		private readonly MasterclassAccountService $accountService,
		private readonly MasterclassLogRepository $logRepository,
		private readonly MasterclassConfig $config,
		private readonly DeletionEventDispatcher $deletion,
		private readonly MasterclassLogWriter $log,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Один тик: заморозка → неиспользованные → просроченные → группы → зависшие → журнал. Сбой шага пишется в журнал
	 * ошибок и не останавливает остальные.
	 *
	 * @return array{frozen: int, unused: int, purged: int, groups: int, stale: int, log: int}
	 */
	public function tick(): array {
		$budget = self::ACCOUNTS_PER_TICK;

		return array(
			'frozen' => $this->step( 'freeze', fn (): int => $this->logFreezes() ),
			'unused' => $this->step( 'unused', function () use ( &$budget ): int {
				return $this->purgeUnused( $budget );
			} ),
			'purged' => $this->step( 'expired', function () use ( &$budget ): int {
				return $this->purgeExpired( $budget );
			} ),
			'groups' => $this->step( 'groups', fn (): int => $this->dropGroups() ),
			'stale'  => $this->step( 'stale', fn (): int => $this->sweepStale() ),
			'log'    => $this->step( 'log', fn (): int => $this->purgeLog() ),
		);
	}

	/**
	 * Пишет в журнал заморозку: один раз на мастер-класс (отметку ставит условный `UPDATE`, выиграл один процесс).
	 * Сама заморозка от этого не зависит — она выводится из времени.
	 *
	 * @return int Сколько заморозок записано.
	 */
	public function logFreezes( int $limit = 100 ): int {
		$now   = $this->nowUtc();
		$count = 0;

		foreach ( $this->masterclasses->listDueForFreeze( $now, $limit ) as $masterclass ) {
			if ( ! $this->masterclasses->markFrozen( $masterclass->id, $now ) ) {
				continue;
			}

			$this->log->write(
				MasterclassLogAction::Frozen,
				array(
					'masterclass_id' => $masterclass->id,
					'actor_type'     => 'system',
				)
			);
			++$count;
		}

		return $count;
	}

	/**
	 * Удаляет аккаунты, ни разу не входившие, у мастер-классов, где вышел срок `unused_purge_at`. Мастер-класс помечается
	 * обработанным, только когда выборка опустела: если бюджет кончился раньше, продолжит следующий тик.
	 *
	 * @param int $budget Остаток бюджета тика; уменьшается на число удалений.
	 *
	 * @return int Сколько аккаунтов удалено.
	 */
	public function purgeUnused( int &$budget ): int {
		if ( $budget <= 0 ) {
			return 0;
		}

		$now     = $this->nowUtc();
		$deleted = 0;

		foreach ( $this->masterclasses->listDueForUnusedPurge( $now, self::MASTERCLASSES_PER_STEP ) as $masterclass ) {
			while ( $budget > 0 ) {
				$batch = $this->accounts->nextUnused( $masterclass->id, min( $budget, self::FETCH_SIZE ) );
				if ( array() === $batch ) {
					$this->masterclasses->markUnusedPurged( $masterclass->id, $now );
					break;
				}

				foreach ( $batch as $account ) {
					if ( $budget <= 0 ) {
						break;
					}

					$this->accountService->deleteAccount( $account, AccountDeleteReason::Unused, 0 );
					--$budget;
					++$deleted;
				}
			}

			if ( $budget <= 0 ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * Вычищает аккаунты мастер-классов, у которых вышел срок или нажато «Удалить». Аккаунт с живой заявкой при явном
	 * удалении отвязывается, при простом сроке остаётся (см. описание класса).
	 *
	 * @param int $budget Остаток бюджета тика; уменьшается на число удалений и отвязок.
	 *
	 * @return int Сколько аккаунтов удалено или отвязано.
	 */
	public function purgeExpired( int &$budget ): int {
		if ( $budget <= 0 ) {
			return 0;
		}

		$done = 0;

		foreach ( $this->masterclasses->listDueForPurge( $this->nowUtc(), self::MASTERCLASSES_PER_STEP ) as $masterclass ) {
			$done += $this->purgeAccountsOf( $masterclass, $budget, 0 );

			if ( $budget <= 0 ) {
				break;
			}
		}

		return $done;
	}

	/**
	 * Сносит группу мастер-классов, у которых не осталось аккаунтов с записью в ней. Удаление идёт обычным событием
	 * ядра; Ш3 при этом сам дочищает остатки и ставит отметку, а здесь она добивается, если подписчик не успел.
	 *
	 * @return int Сколько мастер-классов вычищено.
	 */
	public function dropGroups( int $limit = self::MASTERCLASSES_PER_STEP ): int {
		$count = 0;

		foreach ( $this->masterclasses->listDueForPurge( $this->nowUtc(), $limit ) as $masterclass ) {
			if ( $this->accounts->countAttached( $masterclass->id ) > 0 ) {
				continue;
			}

			if ( null !== $masterclass->groupId ) {
				$this->deletion->dispatch( new DeleteGroupEvent( $masterclass->groupId, 0 ) );
			}

			$this->finish( $masterclass->id, 0 );
			++$count;
		}

		return $count;
	}

	/**
	 * Номера, которые заняли давно, но учётка так и не появилась (сбой на полпути), получают причину `create_failed`.
	 *
	 * @return int Сколько строк помечено.
	 */
	public function sweepStale( int $limit = 100 ): int {
		$now    = $this->nowUtc();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) - self::STALE_AFTER_SECONDS );
		$count  = 0;

		foreach ( $this->accounts->listStalePending( $cutoff, $limit ) as $account ) {
			if ( $this->accounts->markDeleted( $account->id, AccountDeleteReason::CreateFailed, $now ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Удаляет записи журнала старше срока хранения (в контексте есть IP).
	 *
	 * @return int Сколько записей удалено.
	 */
	public function purgeLog(): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $this->nowUtc() . ' UTC' ) - $this->config->logRetentionDays() * DAY_IN_SECONDS );

		return $this->logRepository->purgeOlderThan( $cutoff );
	}

	/**
	 * Шов Ш3 `fs_lms_group_deleting`: группу удаляют любым путём (страница «Группы», снос предмета, сам модуль) —
	 * аккаунты убираются до того, как ядро начнёт сносить записи (временных оно не собирает). Синхронно, без бюджета.
	 */
	public function onGroupDeleting( int $groupId, int $actorUserId ): void {
		$masterclass = $this->masterclasses->findByGroupId( $groupId );
		if ( null === $masterclass ) {
			return;
		}

		$budget = PHP_INT_MAX;
		$this->purgeAccountsOf( $masterclass, $budget, $actorUserId, true );

		if ( ! $masterclass->isPurged() ) {
			$this->finish( $masterclass->id, $actorUserId );
		}
	}

	/**
	 * Хук `deleted_user`: пользователя WordPress удалили вручную (или заявка истекла и ядро убрало учётку) — аккаунт
	 * помечается удалённым. Аккаунт, который удалил сам модуль, уже помечен: отметка не ставится и журнал молчит.
	 */
	public function onUserDeleted( int $userId ): void {
		$id = $this->accounts->markDeletedByUserId( $userId, AccountDeleteReason::UserDeleted, $this->nowUtc() );
		if ( null === $id ) {
			return;
		}

		$this->log->write(
			MasterclassLogAction::AccountDeleted,
			array(
				'masterclass_id' => $this->accounts->find( $id )?->masterclassId,
				'account_id'     => $id,
				'context'        => array( 'reason' => AccountDeleteReason::UserDeleted->value ),
			)
		);
	}

	/**
	 * Обходит живые аккаунты мастер-класса порциями: с живой заявкой — отвязывает (при явном удалении или удалении
	 * группы), остальные удаляет.
	 *
	 * @param int  $budget        Остаток бюджета; уменьшается на каждое действие.
	 * @param int  $actorUserId   Кто удаляет (0 — тик).
	 * @param bool $forceDetach   Отвязывать аккаунты с заявкой независимо от явного «Удалить» (удаление группы).
	 *
	 * @return int Сколько аккаунтов удалено или отвязано.
	 */
	private function purgeAccountsOf( MasterclassDTO $masterclass, int &$budget, int $actorUserId, bool $forceDetach = false ): int {
		$detach = $forceDetach || $masterclass->isDeleteRequested();
		$reason = match ( true ) {
			$forceDetach                    => AccountDeleteReason::GroupDeleted,
			$masterclass->isDeleteRequested() => AccountDeleteReason::Requested,
			default                         => AccountDeleteReason::Expired,
		};

		$done   = 0;
		$cursor = 0;

		while ( $budget > 0 ) {
			$batch = $this->accounts->nextPurgeable( $masterclass->id, min( $budget, self::FETCH_SIZE ), $cursor );
			if ( array() === $batch ) {
				break;
			}

			foreach ( $batch as $account ) {
				if ( $budget <= 0 ) {
					break;
				}

				$cursor = $account->id;

				if ( $this->accountService->hasLiveApplication( $account ) ) {
					if ( $detach ) {
						$this->accountService->detachAccount( $account, $actorUserId );
						--$budget;
						++$done;
					}
					continue;
				}

				$this->accountService->deleteAccount( $account, $reason, $actorUserId );
				--$budget;
				++$done;
			}
		}

		return $done;
	}

	/** Отметка «вычищен» и запись в журнал — один раз: Ш3 мог успеть раньше (тогда мастер-класс уже помечен). */
	private function finish( int $masterclassId, int $actorUserId ): void {
		$fresh = $this->masterclasses->find( $masterclassId );
		if ( null !== $fresh && $fresh->isPurged() ) {
			return;
		}

		$this->masterclasses->markPurged( $masterclassId, $this->nowUtc() );
		$this->log->write(
			MasterclassLogAction::MasterclassPurged,
			array(
				'masterclass_id' => $masterclassId,
				'actor'          => $actorUserId,
				'actor_type'     => 0 === $actorUserId ? 'system' : 'user',
			)
		);
	}

	/**
	 * Шаг тика: сбой пишется в журнал ошибок и не останавливает остальные.
	 *
	 * @param callable(): int $operation
	 */
	private function step( string $name, callable $operation ): int {
		try {
			return $operation();
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassTick', $e, array( 'step' => $name ), true );

			return 0;
		}
	}

	private function nowUtc(): string {
		return $this->clock->now( 'mysql', true );
	}
}
