<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Modules\VideoLibrary\Repositories\VideoAccessLogRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class VideoAccessLogService
 *
 * Журнал доступа к записям в S3: кто, какое видео, сколько раз запрашивал
 * ссылку и сколько фактически смотрел. Пишется в двух точках:
 *
 *  - выдача presigned-ссылки ({@see self::logPresign()}) — прокси «открыли запись»;
 *  - heartbeat плеера ({@see self::logWatch()}) — реальные секунды просмотра.
 *
 * Оба потока агрегируются репозиторием в сессионные строки (окно 3 часа — под
 * длину записи занятия), поэтому долгий просмотр — одна строка с накопленными
 * счётчиками, а не поток записей.
 *
 * @package Inc\Modules\VideoLibrary\Services
 */
class VideoAccessLogService {

	use RequestContextProvider;

	public function __construct(
		private readonly VideoAccessLogRepository $log,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly VideoWatchAccessService $access,
	) {}

	/**
	 * Выдача presigned-ссылки конкретному пользователю.
	 */
	public function logPresign( string $bucket, string $key, ?int $groupLessonId ): void {
		$userId = get_current_user_id();
		if ( $userId <= 0 ) {
			return;
		}

		$this->log->touch(
			$userId,
			$this->primaryRole(),
			$groupLessonId,
			$bucket,
			$key,
			$this->requestContext()->ip,
			requests: 1
		);
	}

	/**
	 * Просмотр записи (heartbeat плеера шлёт накопленные секунды порциями).
	 * Ключ записи берём из самого занятия — клиент его не присылает и подменить не может.
	 */
	public function logWatch( int $groupLessonId, int $seconds ): void {
		$userId = get_current_user_id();
		if ( $userId <= 0 || $seconds <= 0 ) {
			return;
		}

		$lesson = $this->groupLessons->find( $groupLessonId );
		if ( null === $lesson || ! $this->access->canWatch( $userId, $lesson ) ) {
			return;
		}
		$url    = null !== $lesson ? (string) ( $lesson->recordingUrl ?? '' ) : '';
		if ( ! preg_match( '#^s3://([^/]+)/(.+)$#', $url, $m ) ) {
			return;
		}

		// Одна порция не больше часа — защита от мусорных значений; накопление без потолка.
		$this->log->touch(
			$userId,
			$this->primaryRole(),
			$groupLessonId,
			$m[1],
			$m[2],
			$this->requestContext()->ip,
			watchSeconds: min( $seconds, 3600 )
		);
	}

	private function primaryRole(): string {
		$user  = wp_get_current_user();
		$roles = $user ? (array) $user->roles : array();

		return (string) ( $roles[0] ?? '' );
	}
}
