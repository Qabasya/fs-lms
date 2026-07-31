<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Import;

use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Subject\TaskNumberTermGuard;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\ScopedFilter;

/**
 * Class ImportRollbackService
 *
 * Удаляет всё, что успел создать упавший импорт ({@see ImportedEntitiesCollector}).
 *
 * @package Inc\Services\Subject\Import
 *
 * ### Зачем отдельный сервис
 *
 * `wp_insert_post()` / `wp_insert_term()` / запись в `wp_options` не откатываются
 * транзакцией InnoDB, поэтому обёртка `TransactionRunner` спасает только часть
 * записей. Компенсирующее удаление по журналу — единственный способ не оставить
 * предмет в наполовину импортированном состоянии.
 *
 * ### Порядок удаления
 *
 * Обратный порядку создания: зачисления → группы → лица → учётки → записи →
 * термины → вложения → option-структуры предмета. Зачисления удаляются раньше
 * групп и лиц (иначе останутся строки с несуществующими FK), записи — раньше
 * терминов (иначе остаются «висящие» term_relationships).
 *
 * ### Устойчивость
 *
 * Откат идёт по принципу best effort: падение на одной сущности не должно
 * оставить остальные неудалёнными, поэтому каждый шаг изолирован и любая
 * ошибка только пишется в лог.
 */
class ImportRollbackService {

	use ScopedFilter;

	/**
	 * Конструктор сервиса.
	 *
	 * @param PostManager           $posts        Менеджер записей
	 * @param TermManager           $terms        Менеджер терминов
	 * @param MediaManager          $media        Менеджер медиабиблиотеки
	 * @param SubjectRepository     $subjects     Репозиторий предметов
	 * @param TaxonomyRepository    $taxonomies   Репозиторий таксономий
	 * @param MetaBoxRepository         $metaboxes       Репозиторий привязок шаблонов
	 * @param BoilerplateRepository     $boilerplates    Репозиторий типовых условий
	 * @param GroupsRepository          $groups          Репозиторий групп
	 * @param StudentRecordRepository   $studentRecords  Репозиторий записей об обучении
	 * @param PersonRepository          $persons         Репозиторий лиц
	 * @param PersonDocumentsRepository $personDocuments Репозиторий документов лиц
	 */
	public function __construct(
		private readonly PostManager               $posts,
		private readonly TermManager               $terms,
		private readonly MediaManager              $media,
		private readonly SubjectRepository         $subjects,
		private readonly TaxonomyRepository        $taxonomies,
		private readonly MetaBoxRepository         $metaboxes,
		private readonly BoilerplateRepository     $boilerplates,
		private readonly GroupsRepository          $groups,
		private readonly StudentRecordRepository   $studentRecords,
		private readonly PersonRepository          $persons,
		private readonly PersonDocumentsRepository $personDocuments,
	) {}

	/**
	 * Удаляет все сущности из журнала.
	 *
	 * @param ImportedEntitiesCollector $created Журнал созданного
	 *
	 * @return void
	 */
	public function undo( ImportedEntitiesCollector $created ): void {
		if ( $created->isEmpty() ) {
			return;
		}

		PluginLogger::warning(
			'SUBJECT_IMPORT',
			'Откат импорта: удаляем частично созданные сущности',
			$created->counts()
		);

		// Группа удаляется вместе со своими зачислениями: строки student_records
		// ссылаются на группу, и осиротевшие записи хуже, чем их отсутствие.
		foreach ( array_reverse( $created->groupIds() ) as $groupId ) {
			$this->attempt( fn() => $this->studentRecords->deleteAllByGroupAndCollect( $groupId ), "records of group #{$groupId}" );
			$this->attempt( fn() => $this->groups->hardDelete( $groupId ), "group #{$groupId}" );
		}

		foreach ( array_reverse( $created->personIds() ) as $personId ) {
			$this->attempt( fn() => $this->personDocuments->hardDeleteByPersonId( $personId ), "person docs #{$personId}" );
			$this->attempt( fn() => $this->persons->hardDelete( $personId ), "person #{$personId}" );
		}

		foreach ( array_reverse( $created->userIds() ) as $userId ) {
			$this->attempt( fn() => $this->deleteUser( $userId ), "wp user #{$userId}" );
		}

		foreach ( array_reverse( $created->postIds() ) as $postId ) {
			$this->attempt( fn() => $this->posts->delete( $postId ), "post #{$postId}" );
		}

		// Номера заданий, созданные этим импортом, уже могли обрасти его же
		// записями — гард занятых номеров откату не помеха.
		$this->withFilter(
			TaskNumberTermGuard::BYPASS_FILTER,
			'__return_true',
			function () use ( $created ): void {
				foreach ( array_reverse( $created->terms() ) as $term ) {
					$this->attempt(
						fn() => $this->terms->delete( $term['term_id'], $term['taxonomy'] ),
						"term #{$term['term_id']} ({$term['taxonomy']})"
					);
				}
			}
		);

		foreach ( array_reverse( $created->attachmentIds() ) as $attachmentId ) {
			$this->attempt( fn() => $this->media->delete( $attachmentId ), "attachment #{$attachmentId}" );
		}

		foreach ( array_reverse( $created->subjectKeys() ) as $key ) {
			$this->attempt( fn() => $this->undoSubject( $key ), "subject «{$key}»" );
		}
	}

	/**
	 * Удаляет учётную запись, созданную импортом.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return void
	 */
	private function deleteUser( int $userId ): void {
		// wp_delete_user() живёт в админском файле, в AJAX-контексте не загружен.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_delete_user( $userId );
	}

	/**
	 * Удаляет предмет вместе со всеми его option-структурами.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function undoSubject( string $key ): void {
		$this->boilerplates->removeBySubject( $key );
		$this->metaboxes->removeBySubject( $key );
		$this->taxonomies->removeBySubject( $key );
		$this->subjects->remove( $key );
	}

	/**
	 * Выполняет один шаг отката, не позволяя ему сорвать остальные.
	 *
	 * @param callable $step  Шаг удаления
	 * @param string   $label Описание сущности для лога
	 *
	 * @return void
	 */
	private function attempt( callable $step, string $label ): void {
		try {
			$step();
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'SUBJECT_IMPORT', $e, array( 'rollback_step' => $label ), true );
		}
	}
}
