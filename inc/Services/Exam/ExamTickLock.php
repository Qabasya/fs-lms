<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use Inc\Shared\PluginLogger;

/**
 * Защита минутных тиков от параллельного запуска. Исключение тика логируется и не пробрасывается:
 * cron не должен падать, а следующий тик подберёт необработанное (всё идемпотентно).
 */
class ExamTickLock {

	public function __construct(
		private readonly ExamLockRepository $locks,
	) {}

	/**
	 * @param string   $name Имя тика (часть имени блокировки)
	 * @param callable $fn   Тело тика
	 *
	 * @return bool false — такой тик уже выполняется, тело не вызывалось.
	 */
	public function run( string $name, callable $fn ): bool {
		if ( ! $this->locks->acquire( $name ) ) {
			return false;
		}

		try {
			$fn();
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'ExamTick', $e, array( 'tick' => $name ), true );
		} finally {
			$this->locks->release( $name );
		}

		return true;
	}
}
