<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Access\AccessLevel;
use Inc\Enums\Wp\PageRoutes;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\DTO\MasterclassViewDTO;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassAccessGuard
 *
 * Доступ временного ученика к занятию мастер-класса: шов Ш1 (`fs_lms_lesson_access`) закрывает занятие по времени
 * (`freeze_at`), а {@see self::viewFor()} отдаёт плееру вид — баннер, «Связаться», «Выйти», окно заморозки.
 *
 * Заморозка не хранится: она выводится из часов при каждом запросе, поэтому тик крона не нужен для самого закрытия.
 * Шов умеет только понижать (ядро игнорирует повышение), а для чужих записей и групп возвращает уровень как есть.
 * Работает и при выключенном модуле — иначе выключение открыло бы замороженные занятия.
 *
 * Кеш на запрос по физлицу и по пользователю: плеер спрашивает доступ к каждому шагу урока.
 *
 * @package Inc\Modules\Masterclass\Services
 */
class MasterclassAccessGuard {

	/** @var array<int, MasterclassAccountDTO|null> */
	private array $byPerson = array();

	/** @var array<int, MasterclassAccountDTO|null> */
	private array $byUser = array();

	/** @var array<int, MasterclassDTO|null> */
	private array $masterclassCache = array();

	public function __construct(
		private readonly MasterclassAccountRepository $accounts,
		private readonly MasterclassRepository $masterclasses,
		private readonly MasterclassConfig $config,
		private readonly PluginConfig $pluginConfig,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Шов Ш1: понижает доступ до `None`, когда мастер-класс записи заморожен.
	 *
	 * Сбой чтения таблицы модуля не должен ронять доступ ядра: уровень возвращается как пришёл, причина — в журнал.
	 */
	public function filterLessonAccess( AccessLevel $level, StudentRecordDTO $record, GroupLessonDTO $lesson ): AccessLevel {
		if ( ! $record->isTrial || AccessLevel::None === $level ) {
			return $level;
		}

		try {
			$account = $this->byPerson( $record->studentPersonId );
			if ( null === $account ) {
				return $level;
			}

			$masterclass = $this->masterclass( $account->masterclassId );
			if ( null === $masterclass || $masterclass->groupId !== $lesson->groupId ) {
				return $level;
			}

			return $masterclass->isFrozen( $this->nowUtc() ) ? AccessLevel::None : $level;
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassAccessGuard', $e, array( 'seam' => 'fs_lms_lesson_access' ), true );

			return $level;
		}
	}

	/** Вид плеера для живого аккаунта; `null` — это не аккаунт мастер-класса. */
	public function viewFor( int $userId ): ?MasterclassViewDTO {
		$account = $this->byUser( $userId );
		if ( null === $account ) {
			return null;
		}

		$masterclass = $this->masterclass( $account->masterclassId );
		if ( null === $masterclass ) {
			return null;
		}

		$now    = $this->nowUtc();
		$frozen = $masterclass->isFrozen( $now );

		return new MasterclassViewDTO(
			state:        $account->state,
			frozen:       $frozen,
			showBanner:   ! $frozen && ( null !== $account->homeAt || $now > $masterclass->endsAt ),
			bannerText:   $this->config->bannerText(),
			phone:        $this->pluginConfig->centerContactsFallback()['phone'],
			messengerUrl: $this->config->messengerUrl(),
			applyUrl:     PageRoutes::Apply->url(),
			logoutUrl:    wp_logout_url( PageRoutes::SignIn->url() ),
		);
	}

	private function byPerson( int $personId ): ?MasterclassAccountDTO {
		if ( ! array_key_exists( $personId, $this->byPerson ) ) {
			$this->byPerson[ $personId ] = self::live( $this->accounts->findByPersonId( $personId ) );
		}

		return $this->byPerson[ $personId ];
	}

	private function byUser( int $userId ): ?MasterclassAccountDTO {
		if ( ! array_key_exists( $userId, $this->byUser ) ) {
			$this->byUser[ $userId ] = self::live( $this->accounts->findByUserId( $userId ) );
		}

		return $this->byUser[ $userId ];
	}

	private function masterclass( int $id ): ?MasterclassDTO {
		if ( ! array_key_exists( $id, $this->masterclassCache ) ) {
			$this->masterclassCache[ $id ] = $this->masterclasses->find( $id );
		}

		return $this->masterclassCache[ $id ];
	}

	/** Живой аккаунт: не удалён и не зачислен (зачисленный стал настоящим учеником). */
	private static function live( ?MasterclassAccountDTO $account ): ?MasterclassAccountDTO {
		if ( null === $account || $account->isDeleted() || MasterclassAccountState::Enrolled === $account->state ) {
			return null;
		}

		return $account;
	}

	private function nowUtc(): string {
		return $this->clock->now( 'mysql', true );
	}
}
