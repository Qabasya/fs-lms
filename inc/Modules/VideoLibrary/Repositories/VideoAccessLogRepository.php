<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Repositories;

use Inc\Modules\VideoLibrary\Schema\VideoSchema;

/**
 * Class VideoAccessLogRepository
 *
 * Доступ к таблице `fs_lms_video_access_log` — журнал выдач presigned-ссылок
 * и фактического просмотра записей. Строка агрегирует сессию: пока события
 * того же пользователя по той же записи идут подряд (окно AGGREGATE_WINDOW),
 * счётчики докручиваются в неё, а не плодят новые строки — долгий просмотр
 * не превращается в сотню записей журнала.
 *
 * @package Inc\Modules\VideoLibrary\Repositories
 */
class VideoAccessLogRepository {

	/** Окно агрегации сессии в секундах (3 часа: запись занятия идёт ~2 часа и смотрят с паузами). */
	public const AGGREGATE_WINDOW = 10800;

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = VideoSchema::accessTable();
	}

	/**
	 * Фиксирует событие доступа: выдачу ссылки (`requests`) и/или просмотр (`watchSeconds`).
	 * Открытая строка сессии (тот же пользователь и ключ, last_at в пределах окна) докручивается.
	 *
	 * @return int ID строки журнала.
	 */
	public function touch(
		int $wpUserId,
		string $userRole,
		?int $groupLessonId,
		string $bucket,
		string $key,
		string $ip,
		int $requests = 0,
		int $watchSeconds = 0,
	): int {
		// Connection-scoped mutex covers the first SELECT/INSERT race as well as increments.
		// Include bucket and table: identical object keys in different buckets/sites are independent.
		$lock = hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . "\0" . $this->table . "\0" . $wpUserId . "\0" . $bucket . "\0" . $key );
		if ( 1 !== (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) {
			throw new \RuntimeException( 'Не удалось получить блокировку сессии просмотра.' );
		}
		try {
			$now    = current_time( 'mysql' );
			$since  = wp_date( 'Y-m-d H:i:s', time() - self::AGGREGATE_WINDOW, wp_timezone() );
			$openId = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->table}
					 WHERE wp_user_id = %d AND s3_bucket = %s AND s3_key = %s AND last_at >= %s
					 ORDER BY id DESC LIMIT 1",
					$wpUserId,
					$bucket,
					$key,
					$since
				)
			);

			if ( null !== $openId ) {
				// group_lesson_id NULL в БД — «ещё не известно»; %d его бы превратил в 0.
				$setLesson = null !== $groupLessonId ? ', group_lesson_id = ' . (int) $groupLessonId : '';
				$updated = $this->wpdb->query(
					$this->wpdb->prepare(
						"UPDATE {$this->table}
						 SET requests_count = requests_count + %d,
						     watch_seconds   = watch_seconds + %d,
						     last_at         = %s,
						     ip              = %s
						     {$setLesson}
						 WHERE id = %d",
						$requests,
						$watchSeconds,
						$now,
						$ip,
						(int) $openId
					)
				);
				if ( false === $updated ) {
					throw new \RuntimeException( 'Не удалось обновить сессию просмотра.' );
				}
				return (int) $openId;
			}

			$inserted = $this->wpdb->insert(
				$this->table,
				array(
					'wp_user_id'      => $wpUserId,
					'user_role'       => $userRole,
					'group_lesson_id' => $groupLessonId,
					's3_bucket'       => $bucket,
					's3_key'          => $key,
					'requests_count'  => $requests,
					'watch_seconds'   => $watchSeconds,
					'ip'              => $ip,
					'first_at'        => $now,
					'last_at'         => $now,
				)
			);

			if ( false === $inserted ) {
				throw new \RuntimeException( 'Не удалось сохранить сессию просмотра.' );
			}
			return (int) $this->wpdb->insert_id;
		} finally {
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Последние строки журнала (новые первыми) для блока в настройках модуля.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Топ по просмотрам за период — для поиска источника аномального трафика.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function topByWatch( string $since, int $limit = 20 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT wp_user_id, user_role, s3_key,
				        SUM(requests_count) AS requests, SUM(watch_seconds) AS watch_seconds,
				        COUNT(*) AS sessions, MAX(last_at) AS last_at
				 FROM {$this->table}
				 WHERE last_at >= %s
				 GROUP BY wp_user_id, s3_key
				 ORDER BY watch_seconds DESC, requests DESC
				 LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
	/** Filtered, bounded log page; all values are prepared. */
	public function page( array $filters, int $page, int $perPage, string $orderby, string $order ): array {
		[ $where, $args ] = $this->filteredWhere( $filters );
		$total            = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $args ) );
		$orderby          = in_array( $orderby, array( 'id', 'last_at', 'watch_seconds', 'requests_count' ), true ) ? $orderby : 'id';
		$order            = 'ASC' === $order ? 'ASC' : 'DESC';
		$perPage          = max( 1, min( 100, $perPage ) );
		$page             = max( 1, min( $page, max( 1, (int) ceil( $total / $perPage ) ) ) );
		$rows             = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE $where ORDER BY $orderby $order, id $order LIMIT %d OFFSET %d",
				array_merge( $args, array( $perPage, ( $page - 1 ) * $perPage ) )
			),
			ARRAY_A
		);
		return array(
			'rows'     => is_array( $rows ) ? $rows : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $perPage,
		);
	}


	/** Aggregate the same filtered slice without loading its sessions into PHP. */
	public function summary( array $filters ): array {
		[ $where, $args ] = $this->filteredWhere( $filters );
		$row              = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT COUNT(DISTINCT wp_user_id) AS users, SUM(requests_count) AS requests, SUM(watch_seconds) AS seconds FROM %i WHERE $where",
				$args
			),
			ARRAY_A
		);
		return array(
			'users'    => (int) ( $row['users'] ?? 0 ),
			'requests' => (int) ( $row['requests'] ?? 0 ),
			'seconds'  => (int) ( $row['seconds'] ?? 0 ),
		);
	}

	/** Shared prepared filter predicates for the page and summary. */
	private function filteredWhere( array $filters ): array {
		$args  = array( $this->table );
		$where = '1=1';
		foreach ( array( 'wp_user_id', 'group_lesson_id' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where .= " AND $field = %d";
				$args[] = (int) $filters[ $field ];
			}
		}
		foreach ( array(
			'date_from' => '>=',
			'date_to'   => '<=',
		) as $field => $operator ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where .= " AND last_at $operator %s";
				$args[] = $filters[ $field ] . ( 'date_from' === $field ? ' 00:00:00' : ' 23:59:59' );
			}
		}
		if ( ! empty( $filters['recording'] ) ) {
			$where .= ' AND s3_key LIKE %s';
			$args[] = '%' . $this->wpdb->esc_like( $filters['recording'] ) . '%';
		}
		return array( $where, $args );
	}
}
