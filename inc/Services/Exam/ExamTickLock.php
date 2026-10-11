<?php
/**
 * Защита минутных тиков от параллельного запуска. Исключение тика логируется и не пробрасывается:
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use Inc\Shared\PluginLogger;

/**
 * Защита минутных тиков от параллельного запуска. Исключение тика логируется и не пробрасывается:
 * cron не должен падать, а следующий тик подберёт необработанное (всё идемпотентно).
 */
class ExamTickLock {

	/** Использует репозиторий блокировок для исключения одновременной обработки одного тика. */
	public function __construct(
		private readonly ExamLockRepository $locks,
	) {}

	/**
	 * Выполняет тик только после получения именованной блокировки и освобождает её при любом исходе.
	 *
	 * @param string   $name      Имя тика (часть имени блокировки)
	 * @param callable $operation Тело тика
	 *
	 * @return bool false — такой тик уже выполняется, тело не вызывалось.
	 */
	public function run( string $name, callable $operation ): bool {
		if ( ! $this->locks->acquire( $name ) ) {
			return false;
		}

		try {
			$operation();
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamTick', $e, array( 'tick' => $name ), true );
		} finally {
			$this->locks->release( $name );
		}

		return true;
	}
}
