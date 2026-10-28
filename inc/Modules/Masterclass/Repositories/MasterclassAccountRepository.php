<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Repositories;

use Inc\Modules\Masterclass\DTO\MasterclassAccountDTO;
use Inc\Modules\Masterclass\DTO\MasterclassFunnelDTO;
use Inc\Modules\Masterclass\Enums\AccountDeleteReason;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use Inc\Repositories\WPDBRepositories\AbstractExamRepository;

/**
 * Class MasterclassAccountRepository
 *
 * Временные аккаунты мастер-классов (`fs_lms_masterclass_accounts`). Строка НЕ удаляется никогда
 * (мягкое удаление `deleted_at`), поэтому `id` — устойчивый номер N: он не повторяется, а воронка
 * считает и удалённые.
 *
 * Одноразовые отметки (`first_login_at`, `home_at`, `contact_at`) и удаление — условные
 * `UPDATE … WHERE col IS NULL`: при гонке двух запросов отметку ставит ровно один, второму вернётся `false`.
 * Поиск (`find*`) отдаёт и удалённые аккаунты — вызывающий проверяет `isDeleted()`.
 *
 * @package Inc\Modules\Masterclass\Repositories
 */
class MasterclassAccountRepository extends AbstractExamRepository {

	private const MARK_FIRST_LOGIN = 'first_login_at';
	private const MARK_HOME        = 'home_at';
	private const MARK_CONTACT     = 'contact_at';

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = MasterclassSchema::accounts();
	}

	public function find( int $id ): ?MasterclassAccountDTO {
		return $this->one( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
	}

	public function findByUserId( int $userId ): ?MasterclassAccountDTO {
		return $this->one( $this->wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $this->table, $userId ) );
	}

	public function findByPersonId( int $personId ): ?MasterclassAccountDTO {
		return $this->one( $this->wpdb->prepare( 'SELECT * FROM %i WHERE person_id = %d ORDER BY id DESC LIMIT 1', $this->table, $personId ) );
	}

	/**
	 * Все аккаунты мастер-класса по возрастанию номера, удалённые тоже.
	 *
	 * @return list<MasterclassAccountDTO>
	 */
	public function listByMasterclass( int $masterclassId ): array {
		return $this->many(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE masterclass_id = %d ORDER BY id ASC', $this->table, $masterclassId )
		);
	}

	/**
	 * WP-пользователи живых аккаунтов мастер-класса (для снятия блокировок входа).
	 *
	 * @return list<int>
	 */
	public function liveUserIds( int $masterclassId ): array {
		return array_values(
			$this->readInts(
				$this->wpdb->prepare(
					'SELECT user_id FROM %i WHERE masterclass_id = %d AND user_id IS NOT NULL AND deleted_at IS NULL ORDER BY id ASC',
					$this->table,
					$masterclassId
				)
			)
		);
	}

	/**
	 * Страница списка аккаунтов по возрастанию номера.
	 *
	 * @param bool $withDeleted `false` — только не удалённые.
	 *
	 * @return array{rows: list<MasterclassAccountDTO>, total: int}
	 */
	public function pageByMasterclass( int $masterclassId, int $page, int $perPage, bool $withDeleted ): array {
		$perPage = max( 1, $perPage );
		$offset  = ( max( 1, $page ) - 1 ) * $perPage;
		$where   = $withDeleted ? '' : ' AND deleted_at IS NULL';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where — константа из двух вариантов выше.
		$rows  = $this->many(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE masterclass_id = %d{$where} ORDER BY id ASC LIMIT %d OFFSET %d",
				$this->table,
				$masterclassId,
				$perPage,
				$offset
			)
		);
		$total = $this->readInt(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE masterclass_id = %d{$where}", $this->table, $masterclassId )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Занимает номер: строка без учётки. `id` — номер N, он не повторится даже после удаления.
	 *
	 * @return int Номер N.
	 */
	public function insertPending( int $masterclassId, string $nowUtc ): int {
		return $this->insertRow(
			$this->table,
			array(
				'masterclass_id' => $masterclassId,
				'state'          => MasterclassAccountState::Active->value,
				'created_at'     => $nowUtc,
				'updated_at'     => $nowUtc,
			)
		);
	}

	/** Привязывает созданную учётку, физлицо и запись об обучении к занятому номеру. */
	public function attach( int $id, int $userId, int $personId, int $studentRecordId ): void {
		$this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET user_id = %d, person_id = %d, student_record_id = %d, updated_at = %s WHERE id = %d',
				$this->table,
				$userId,
				$personId,
				$studentRecordId,
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);
	}

	/** Меняет состояние; номер заявки (если дан) пишется вместе с ним, иначе прежний остаётся. */
	public function setState( int $id, MasterclassAccountState $state, ?int $applicationId = null ): void {
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( null === $applicationId ) {
			$this->write(
				$this->wpdb->prepare( 'UPDATE %i SET state = %s, updated_at = %s WHERE id = %d', $this->table, $state->value, $now, $id )
			);
			return;
		}

		$this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET state = %s, application_id = %d, updated_at = %s WHERE id = %d',
				$this->table,
				$state->value,
				$applicationId,
				$now,
				$id
			)
		);
	}

	/** @return bool `true` — первый вход записал этот вызов. */
	public function markFirstLogin( int $id, string $nowUtc ): bool {
		return $this->markOnce( self::MARK_FIRST_LOGIN, $id, $nowUtc );
	}

	/** @return bool `true` — «дома» записал этот вызов. */
	public function markHome( int $id, string $nowUtc ): bool {
		return $this->markOnce( self::MARK_HOME, $id, $nowUtc );
	}

	/** @return bool `true` — первый контакт записал этот вызов. */
	public function markContact( int $id, string $nowUtc ): bool {
		return $this->markOnce( self::MARK_CONTACT, $id, $nowUtc );
	}

	/**
	 * Мягкое удаление. Строка и `user_id` остаются (для журнала и воронки).
	 *
	 * @return bool `true` — удалил этот вызов; `false` — уже был удалён.
	 */
	public function markDeleted( int $id, AccountDeleteReason $reason, string $nowUtc ): bool {
		return 1 === $this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET deleted_at = %s, delete_reason = %s, updated_at = %s WHERE id = %d AND deleted_at IS NULL',
				$this->table,
				$nowUtc,
				$reason->value,
				$nowUtc,
				$id
			)
		);
	}

	/**
	 * Мягко удаляет живой аккаунт пользователя (пользователя WP удалили вручную).
	 *
	 * @return int|null Номер аккаунта или `null`, если у пользователя нет живого аккаунта модуля.
	 */
	public function markDeletedByUserId( int $userId, AccountDeleteReason $reason, string $nowUtc ): ?int {
		$id = $this->readInt(
			$this->wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND deleted_at IS NULL', $this->table, $userId )
		);

		return $id > 0 && $this->markDeleted( $id, $reason, $nowUtc ) ? $id : null;
	}

	/** Отвязывает запись об обучении (она остаётся у настоящего ученика, а не у аккаунта). */
	public function clearStudentRecord( int $id ): void {
		$this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET student_record_id = NULL, updated_at = %s WHERE id = %d',
				$this->table,
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);
	}

	/**
	 * Очередная порция аккаунтов, ни разу не входивших.
	 *
	 * @return list<MasterclassAccountDTO>
	 */
	public function nextUnused( int $masterclassId, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE masterclass_id = %d AND deleted_at IS NULL AND first_login_at IS NULL AND state = %s ORDER BY id ASC LIMIT %d',
				$this->table,
				$masterclassId,
				MasterclassAccountState::Active->value,
				max( 1, $limit )
			)
		);
	}

	/**
	 * Очередная порция аккаунтов, которые вычищаются вместе с мастер-классом (зачисленные не трогаются).
	 *
	 * @return list<MasterclassAccountDTO>
	 */
	public function nextPurgeable( int $masterclassId, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE masterclass_id = %d AND deleted_at IS NULL AND state <> %s ORDER BY id ASC LIMIT %d',
				$this->table,
				$masterclassId,
				MasterclassAccountState::Enrolled->value,
				max( 1, $limit )
			)
		);
	}

	/** Сколько аккаунтов ещё предстоит вычистить. */
	public function countLive( int $masterclassId ): int {
		return $this->readInt(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE masterclass_id = %d AND deleted_at IS NULL AND state <> %s',
				$this->table,
				$masterclassId,
				MasterclassAccountState::Enrolled->value
			)
		);
	}

	/** Всего строк мастер-класса, удалённые тоже (занятые номера). */
	public function countByMasterclass( int $masterclassId ): int {
		return $this->readInt(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE masterclass_id = %d', $this->table, $masterclassId )
		);
	}

	/**
	 * Номера, занятые давно, но так и не получившие учётку (сбой на полпути).
	 *
	 * @return list<MasterclassAccountDTO>
	 */
	public function listStalePending( string $olderThanUtc, int $limit ): array {
		return $this->many(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE user_id IS NULL AND deleted_at IS NULL AND created_at < %s ORDER BY id ASC LIMIT %d',
				$this->table,
				$olderThanUtc,
				max( 1, $limit )
			)
		);
	}

	/**
	 * Воронка одним запросом. Считает и мягко удалённые аккаунты; номера, на которых учётка не создалась
	 * (`create_failed`), в воронку не входят — это не выданные аккаунты.
	 */
	public function funnel( int $masterclassId ): MasterclassFunnelDTO {
		$row = $this->readRow(
			$this->wpdb->prepare(
				'SELECT COUNT(*) AS created,
					COALESCE( SUM( first_login_at IS NOT NULL ), 0 ) AS logged_in,
					COALESCE( SUM( home_at IS NOT NULL ), 0 ) AS at_home,
					COALESCE( SUM( contact_at IS NOT NULL ), 0 ) AS contacted,
					COALESCE( SUM( state IN ( %s, %s ) ), 0 ) AS applied,
					COALESCE( SUM( state = %s ), 0 ) AS enrolled
				FROM %i
				WHERE masterclass_id = %d AND ( delete_reason IS NULL OR delete_reason <> %s )',
				MasterclassAccountState::Applied->value,
				MasterclassAccountState::Enrolled->value,
				MasterclassAccountState::Enrolled->value,
				$this->table,
				$masterclassId,
				AccountDeleteReason::CreateFailed->value
			)
		) ?? array();

		return new MasterclassFunnelDTO(
			created:   (int) ( $row['created'] ?? 0 ),
			loggedIn:  (int) ( $row['logged_in'] ?? 0 ),
			atHome:    (int) ( $row['at_home'] ?? 0 ),
			contacted: (int) ( $row['contacted'] ?? 0 ),
			applied:   (int) ( $row['applied'] ?? 0 ),
			enrolled:  (int) ( $row['enrolled'] ?? 0 ),
		);
	}

	/** Ставит одноразовую отметку; колонка — одна из констант класса, не ввод. */
	private function markOnce( string $column, int $id, string $nowUtc ): bool {
		return 1 === $this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET %i = %s, updated_at = %s WHERE id = %d AND %i IS NULL AND deleted_at IS NULL',
				$this->table,
				$column,
				$nowUtc,
				$nowUtc,
				$id,
				$column
			)
		);
	}

	private function one( string $sql ): ?MasterclassAccountDTO {
		$row = $this->readRow( $sql );

		return null !== $row ? MasterclassAccountDTO::fromArray( $row ) : null;
	}

	/**
	 * @return list<MasterclassAccountDTO>
	 */
	private function many( string $sql ): array {
		return array_map( array( MasterclassAccountDTO::class, 'fromArray' ), $this->readRows( $sql ) );
	}
}
