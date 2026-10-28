<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Repositories;

use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;
use Inc\Repositories\WPDBRepositories\AbstractExamRepository;

/**
 * Class MasterclassRepository
 *
 * Мастер-классы (`fs_lms_masterclasses`). Все времена — UTC. Ошибка БД — исключение
 * ({@see AbstractExamRepository}), а не «строки нет».
 *
 * Выборки тика (`listDueFor*`) берут только необработанное: отметка «сделано» ставится условным
 * `UPDATE … IS NULL`, поэтому два параллельных тика не обработают один мастер-класс дважды
 * (`markFrozen()` возвращает `true` ровно одному из них).
 *
 * @package Inc\Modules\Masterclass\Repositories
 */
class MasterclassRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = MasterclassSchema::masterclasses();
	}

	public function find( int $id ): ?MasterclassDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );

		return null !== $row ? MasterclassDTO::fromArray( $row ) : null;
	}

	public function findByGroupId( int $groupId ): ?MasterclassDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE group_id = %d', $this->table, $groupId ) );

		return null !== $row ? MasterclassDTO::fromArray( $row ) : null;
	}

	/**
	 * Страница списка, новые сверху.
	 *
	 * @return array{rows: list<MasterclassDTO>, total: int}
	 */
	public function page( int $page, int $perPage ): array {
		$perPage = max( 1, $perPage );
		$offset  = ( max( 1, $page ) - 1 ) * $perPage;

		$rows = $this->readRows(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY starts_at DESC, id DESC LIMIT %d OFFSET %d', $this->table, $perPage, $offset )
		);

		return array(
			'rows'  => $this->hydrateAll( $rows ),
			'total' => $this->readInt( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table ) ),
		);
	}

	/**
	 * @param array<string, mixed> $data Колонки таблицы (времена — UTC).
	 *
	 * @return int Номер созданного мастер-класса.
	 */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	/**
	 * @param array<string, mixed> $data Колонки таблицы; `null` пишется как `NULL`.
	 */
	public function update( int $id, array $data ): void {
		$this->updateRow( $this->table, $data, array( 'id' => $id ) );
	}

	/**
	 * Группы, за которыми числится мастер-класс — служебные группы (шов Ш4). Читается таблица, а не флаг
	 * модуля: при выключенном модуле группа остаётся служебной. Кеш на запрос — на стороне вызывающего.
	 *
	 * @return list<int>
	 */
	public function activeGroupIds(): array {
		return array_values(
			$this->readInts( $this->wpdb->prepare( 'SELECT group_id FROM %i WHERE group_id IS NOT NULL ORDER BY group_id ASC', $this->table ) )
		);
	}

	/**
	 * Невычищенные мастер-классы, чьё окно идёт или начнётся не позже чем через `$leadMinutes` (шов Ш6:
	 * вход по флаеру проверяют заранее, до начала).
	 *
	 * @return list<MasterclassDTO>
	 */
	public function runningFor( string $nowUtc, int $leadMinutes ): array {
		$rows = $this->readRows(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE purged_at IS NULL AND ends_at >= %s AND DATE_SUB( starts_at, INTERVAL %d MINUTE ) <= %s ORDER BY starts_at ASC, id ASC',
				$this->table,
				$nowUtc,
				max( 0, $leadMinutes ),
				$nowUtc
			)
		);

		return $this->hydrateAll( $rows );
	}

	/**
	 * Пора записать заморозку.
	 *
	 * @return list<MasterclassDTO>
	 */
	public function listDueForFreeze( string $nowUtc, int $limit ): array {
		return $this->hydrateAll(
			$this->readRows(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE freeze_at <= %s AND frozen_at IS NULL AND purged_at IS NULL ORDER BY freeze_at ASC, id ASC LIMIT %d',
					$this->table,
					$nowUtc,
					max( 1, $limit )
				)
			)
		);
	}

	/**
	 * Пора удалять аккаунты, ни разу не входившие.
	 *
	 * @return list<MasterclassDTO>
	 */
	public function listDueForUnusedPurge( string $nowUtc, int $limit ): array {
		return $this->hydrateAll(
			$this->readRows(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE unused_purge_at <= %s AND unused_purged_at IS NULL AND purged_at IS NULL ORDER BY unused_purge_at ASC, id ASC LIMIT %d',
					$this->table,
					$nowUtc,
					max( 1, $limit )
				)
			)
		);
	}

	/**
	 * Пора вычищать целиком: срок вышел или администратор нажал «Удалить». Запрошенное удаление идёт
	 * по времени запроса, остальные — по сроку.
	 *
	 * @return list<MasterclassDTO>
	 */
	public function listDueForPurge( string $nowUtc, int $limit ): array {
		return $this->hydrateAll(
			$this->readRows(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE ( purge_at <= %s OR delete_requested_at IS NOT NULL ) AND purged_at IS NULL ORDER BY COALESCE( delete_requested_at, purge_at ) ASC, id ASC LIMIT %d',
					$this->table,
					$nowUtc,
					max( 1, $limit )
				)
			)
		);
	}

	/**
	 * Записывает заморозку один раз.
	 *
	 * @return bool `true` — отметку поставил этот вызов; `false` — уже стояла или мастер-класс вычищен.
	 */
	public function markFrozen( int $id, string $nowUtc ): bool {
		return 1 === $this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET frozen_at = %s, updated_at = %s WHERE id = %d AND frozen_at IS NULL AND purged_at IS NULL',
				$this->table,
				$nowUtc,
				$nowUtc,
				$id
			)
		);
	}

	/** Неиспользованные аккаунты удалены (тик больше не выбирает). */
	public function markUnusedPurged( int $id, string $nowUtc ): void {
		$this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET unused_purged_at = %s, updated_at = %s WHERE id = %d AND unused_purged_at IS NULL',
				$this->table,
				$nowUtc,
				$nowUtc,
				$id
			)
		);
	}

	/** Администратор нажал «Удалить»; повторное нажатие время не двигает (по нему идёт очередь тика). */
	public function markDeleteRequested( int $id, string $nowUtc ): void {
		$this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET delete_requested_at = %s, updated_at = %s WHERE id = %d AND delete_requested_at IS NULL AND purged_at IS NULL',
				$this->table,
				$nowUtc,
				$nowUtc,
				$id
			)
		);
	}

	/** Аккаунты и группа вычищены: ссылки на группу и занятие сбрасываются (их id больше не существуют). */
	public function markPurged( int $id, string $nowUtc ): void {
		$this->write(
			$this->wpdb->prepare(
				'UPDATE %i SET purged_at = %s, group_id = NULL, group_lesson_id = NULL, updated_at = %s WHERE id = %d AND purged_at IS NULL',
				$this->table,
				$nowUtc,
				$nowUtc,
				$id
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<MasterclassDTO>
	 */
	private function hydrateAll( array $rows ): array {
		return array_map( array( MasterclassDTO::class, 'fromArray' ), $rows );
	}
}
