<?php
/** Синхронизация меты варианта и снимков проведений.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\TransactionRunner;
use Inc\Shared\PluginLogger;

/** Атомарно сохраняет мету варианта и пересобирает снимки его проведений. */
class ExamSnapshotSyncService {
	use TransactionRunner;

	/**
	 * Защита от повторного перехвата вложенного вызова WordPress.
	 *
	 * @var bool
	 */
	private bool $writing = false;

	/** Получает общую блокировку и зависимости транзакционного сохранения. */
	public function __construct(
		private readonly ExamVariantMutex $mutex,
		private readonly ExamVariantGuard $guard,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamEventService $events,
		private readonly ExamLockRepository $locks,
		private readonly PostManager $posts,
	) {}

	/** Фильтр WordPress: перехватывает только мету варианта, не мету заданий. */
	public function filterUpdate( mixed $check, mixed $objectId, mixed $metaKey, mixed $value, mixed $previous = '' ): mixed {
		return $this->filterWrite( $check, $objectId, $metaKey, fn( int $id ): mixed => $this->posts->updateMetaChecked( $id, (string) $metaKey, $value, $previous ) );
	}

	/** Перехватывает добавление меты варианта. */
	public function filterAdd( mixed $check, mixed $objectId, mixed $metaKey, mixed $value, mixed $unique = false ): mixed {
		return $this->filterWrite( $check, $objectId, $metaKey, fn( int $id ): mixed => $this->posts->addMetaChecked( $id, (string) $metaKey, $value, (bool) $unique ) );
	}

	/** Перехватывает удаление меты варианта. */
	public function filterDelete( mixed $check, mixed $objectId, mixed $metaKey, mixed $value = '', mixed $deleteAll = false ): mixed {
		if ( null === $check && ! $this->writing && (bool) $deleteAll && PostMetaName::Meta->value === (string) $metaKey ) {
			return false;
		}
		return $this->filterWrite( $check, $objectId, $metaKey, fn( int $id ): mixed => $this->posts->deleteMetaChecked( $id, (string) $metaKey, $value ) );
	}

	/** Защищает обновление меты по идентификатору строки. */
	public function filterUpdateById( mixed $check, mixed $metaId, mixed $value, mixed $newKey = false ): mixed {
		if ( null !== $check || $this->writing ) {
			return $check;
		}
		$meta = $this->posts->metaById( (int) $metaId );
		if ( ! $meta ) {
			return $check;
		}
		$oldKey    = (string) $meta->meta_key;
		$targetKey = false === $newKey ? $oldKey : (string) $newKey;
		if ( ( in_array( $oldKey, ExamVariantGuard::GUARDED_META, true ) || in_array( $targetKey, ExamVariantGuard::GUARDED_META, true ) )
			&& $this->guard->isPostFrozen( (int) $meta->post_id ) ) {
			if ( $oldKey !== $targetKey || ( ! $this->guard->isMetaUnchanged( (int) $meta->post_id, $oldKey, $value )
				&& ! ( PostMetaName::Meta->value === $oldKey && $this->guard->isConditionOnlyEdit( (int) $meta->post_id, $value ) ) ) ) {
				return false;
			}
		}
		if ( PostMetaName::Meta->value !== $oldKey && PostMetaName::Meta->value !== $targetKey ) {
			return $check;
		}
		return $this->filterWrite( $check, $meta->post_id, PostMetaName::Meta->value, fn(): bool => $this->posts->updateMetaById( (int) $metaId, $value, $newKey ) );
	}

	/** Защищает удаление меты по идентификатору строки. */
	public function filterDeleteById( mixed $check, mixed $metaId ): mixed {
		if ( null !== $check || $this->writing ) {
			return $check;
		}
		$meta = $this->posts->metaById( (int) $metaId );
		if ( ! $meta ) {
			return $check;
		}
		if ( in_array( (string) $meta->meta_key, ExamVariantGuard::GUARDED_META, true ) && $this->guard->isPostFrozen( (int) $meta->post_id ) ) {
			return false;
		}
		if ( PostMetaName::Meta->value !== $meta->meta_key ) {
			return $check;
		}
		return $this->filterWrite( $check, $meta->post_id, PostMetaName::Meta->value, fn(): bool => $this->posts->deleteMetaById( (int) $metaId ) );
	}

	/** Сохраняет вариант и снимки одной транзакцией, сохраняя результат WordPress. */
	private function filterWrite( mixed $check, mixed $objectId, mixed $metaKey, callable $write ): mixed {
		$id = (int) $objectId;
		if ( null !== $check || $this->writing || PostMetaName::Meta->value !== (string) $metaKey
			|| ! PostTypeResolver::isAssessmentPostType( (string) ( $this->posts->get( $id )?->post_type ?? '' ) ) ) {
			return $check;
		}
		return $this->mutex->run(
			function () use ( $id, $write ): mixed {
				$this->locks->assertNoActiveTransaction();
				$this->guard->clearCache();
				if ( $this->guard->isAssessmentFrozen( $id ) ) {
						throw new \DomainException( 'Экзамен уже начался: изменение варианта отменено.' );
				}
				try {
					$eventIds = array();
					$result   = $this->inTransaction(
						function () use ( $id, $write, &$eventIds ): mixed {
							$this->writing = true;
							try {
									$updated = $write( $id );
							} finally {
								$this->writing = false;
							}
							if ( false === $updated ) {
								return false;
							}
							$eventIds = $this->sessions->publishedEventIdsForAssessment( $id );
							foreach ( $eventIds as $eventId ) {
								$this->events->rebuildSnapshotInTransaction( $eventId, $id );
							}
							$this->guard->clearCache();
							if ( $this->guard->isAssessmentFrozen( $id ) ) {
								throw new \RuntimeException( 'Экзамен уже начался: изменение варианта отменено.' );
							}
							return $updated;
						}
					);
					foreach ( $eventIds as $eventId ) {
						try {
							ExamLogAction::SnapshotRebuilt->record(
								array(
									'event_id' => $eventId,
									'actor'    => get_current_user_id(),
									'context'  => array(
										'assessment_id' => $id,
										'automatic'     => true,
									),
								)
							);
						} catch ( \Throwable $error ) {
							PluginLogger::exception( 'ExamSnapshot', $error, array( 'event_id' => $eventId ), true );
						}
					}
					return $result;
				} catch ( \Throwable $error ) {
					// WordPress may have cached the new meta before SQL rollback.
					$this->posts->clearCache( $id );
					throw $error;
				} finally {
					$this->guard->clearCache();
				}
			}
		);
	}
}
