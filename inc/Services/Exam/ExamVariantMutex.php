<?php
/** Блокировка операций над экзаменационными вариантами.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamLockRepository;

/** Общая блокировка изменения вариантов, публикации и старта экзамена. */
class ExamVariantMutex {
	private const LOCK = 'exam_variant_snapshot';
	/**
	 * Признак вложенного вызова в текущем запросе.
	 *
	 * @var bool
	 */
	private bool $held = false;

	/** Получает репозиторий именованных блокировок. */
	public function __construct( private readonly ExamLockRepository $locks ) {}

	/** Выполняет операцию под блокировкой и освобождает её при любой ошибке.
	 *
	 * @throws \RuntimeException Блокировку не удалось получить.
	 */
	public function run( callable $operation ): mixed {
		if ( $this->held ) {
			return $operation();
		}
		if ( ! $this->locks->acquire( self::LOCK, 10 ) ) {
			throw new \RuntimeException( 'Экзаменационный вариант сейчас изменяется. Повторите попытку.' );
		}
		$this->held = true;
		try {
			return $operation();
		} finally {
			$this->held = false;
			$this->locks->release( self::LOCK );
		}
	}
}
