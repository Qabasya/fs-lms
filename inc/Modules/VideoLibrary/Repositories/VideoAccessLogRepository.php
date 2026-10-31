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

	/** Окно агрегации сессии в секундах (30 минут без событий — новая строка). */
	public const AGGREGATE_WINDOW = 1800;

	private \wpdb  $wpdb;
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
		$now    = current_time( 'mysql' );
		$since  = wp_date( 'Y-m-d H:i:s', time() - self::AGGREGATE_WINDOW, wp_timezone() );
		$openId = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table}
				 WHERE wp_user_id = %d AND s3_key = %s AND last_at >= %s
				 ORDER BY id DESC LIMIT 1",
				$wpUserId,
				$key,
				$since
			)
		);

		if ( null !== $openId ) {
			// group_lesson_id NULL в БД — «ещё не известно»; %d его бы превратил в 0.
			$setLesson = null !== $groupLessonId ? ', group_lesson_id = ' . (int) $groupLessonId : '';
			$this->wpdb->query(
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
			return (int) $openId;
		}

		$this->wpdb->insert(
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

		return (int) $this->wpdb->insert_id;
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
}
