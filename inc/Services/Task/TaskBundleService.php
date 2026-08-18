<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskBundleService
 *
 * Материализует задание-связку (`triple_task`, ThreeInOneTemplate) в три обычных
 * дочерних поста CPT `{key}_tasks` шаблона `standard_task` — по одному на 19/20/21.
 * Parent остаётся единственным источником контента и единственной публичной
 * страницей; children нужны только затем, чтобы Work/Assessment/Lesson-step
 * могли ссылаться на 19/20/21 как на 3 независимых задания (см. .docs/Tasks.md).
 *
 * @package Inc\Services\Task
 */
class TaskBundleService {

	/**
	 * Номера задания в связке, порядок фиксирован — используется и планировщиком
	 * миграции ({@see \Inc\Services\Task\TaskBundleMigrationPlanner}) для
	 * восстановления номера банковских children (нет своей таксономии).
	 */
	public const NUMBERS = array( 19, 20, 21 );

	public function __construct(
		private readonly PostManager $posts,
		private readonly TermManager $terms,
	) {}

	/**
	 * Идемпотентный upsert трёх children по мете parent-поста связки.
	 *
	 * Parent — либо предметное задание (`{key}_tasks`), либо задача глобального
	 * банка (`fs_lms_problems`, без таксономии номеров — см. `PostTypeResolver`).
	 * Children материализуются в том же CPT, что и parent: у банковских связок
	 * номер термом не проставляется (у банка таксономии нет вовсе — номер вводится
	 * вручную в Work/Assessment builder, как у любой другой банковской задачи).
	 *
	 * @param int $parentId ID поста-связки (triple_task)
	 *
	 * @return int[] ID трёх children в порядке 19/20/21; пустой массив, если
	 *               parent не найден или относится к неподдерживаемому CPT.
	 */
	public function syncChildren( int $parentId ): array {
		$parent = $this->posts->get( $parentId );
		if ( ! $parent ) {
			return array();
		}

		$isBank = PostTypeResolver::isProblemPostType( $parent->post_type );
		$subjectKey = $isBank ? '' : PostTypeResolver::subjectFromTaskPostType( $parent->post_type );
		if ( ! $isBank && '' === $subjectKey ) {
			return array();
		}

		$childPostType = $isBank ? PostTypeResolver::problems() : PostTypeResolver::tasks( $subjectKey );
		$taxonomy      = $isBank ? '' : "{$subjectKey}_task_number";

		$meta = $this->posts->taskMeta( $parentId );

		$existingChildIds = $this->posts->getMeta( $parentId, PostMetaName::TaskBundleChildIds->value, true );
		$existingChildIds = is_array( $existingChildIds ) ? array_values( $existingChildIds ) : array();

		$childIds = array();
		foreach ( self::NUMBERS as $i => $number ) {
			$condition = (string) ( $meta[ "task_{$number}_condition" ] ?? '' );
			$answer    = (string) ( $meta[ "task_{$number}_answer" ] ?? '' );
			$existingId = (int) ( $existingChildIds[ $i ] ?? 0 );

			$childIds[] = $this->upsertChild(
				$existingId,
				$parent,
				$childPostType,
				$taxonomy,
				$number,
				$condition,
				$answer
			);
		}

		$this->posts->updateMeta( $parentId, PostMetaName::TaskBundleChildIds->value, $childIds );

		return $childIds;
	}

	/**
	 * Дети связки в виде {id, title, number} — для пикеров Work/Assessment/Lesson-step
	 * (там связка выбирается один раз как parent и разворачивается в 3 слота).
	 * Пусто, если у поста нет `TaskBundleChildIds` (обычное задание, не связка).
	 *
	 * `number` (19/20/21 по фиксированному порядку {@see NUMBERS}) нужен, чтобы
	 * Assessment builder мог сразу заполнить ручной номер (Задача 8, фолбэк для
	 * банковских детей без таксономии) — без этого `EgeCompletenessChecker` видит
	 * такого child «сиротой» (нет ни терма, ни ручного номера в `task_numbers`).
	 * Для предметных children (терм проставлен) значение избыточно, но безвредно:
	 * бэкенд игнорирует ручной номер при наличии терма.
	 *
	 * @param int $parentId
	 *
	 * @return array<int, array{id: int, title: string, number: string}>
	 */
	public function childrenSummary( int $parentId ): array {
		$childIds = $this->posts->getMeta( $parentId, PostMetaName::TaskBundleChildIds->value, true );
		if ( ! is_array( $childIds ) || empty( $childIds ) ) {
			return array();
		}

		$result = array();
		foreach ( array_values( $childIds ) as $i => $childId ) {
			$childId = (int) $childId;
			$child   = $childId > 0 ? $this->posts->get( $childId ) : null;
			if ( $child ) {
				$result[] = array(
					'id'     => $childId,
					'title'  => $child->post_title,
					'number' => (string) ( self::NUMBERS[ $i ] ?? '' ),
				);
			}
		}

		return $result;
	}

	/**
	 * Переносит статус parent-поста на все его children (draft/publish/trash).
	 * Не создаёт children, если их ещё нет — это делает только {@see syncChildren()}.
	 *
	 * @param int    $parentId ID parent-поста
	 * @param string $status   Новый статус (см. {@see \Inc\Managers\Wp\PostManager::updateStatus()})
	 */
	public function cascadeStatus( int $parentId, string $status ): void {
		$childIds = $this->posts->getMeta( $parentId, PostMetaName::TaskBundleChildIds->value, true );
		if ( ! is_array( $childIds ) ) {
			return;
		}

		foreach ( $childIds as $childId ) {
			$childId = (int) $childId;
			if ( $childId > 0 ) {
				$this->posts->updateStatus( $childId, $status );
			}
		}
	}

	/**
	 * Создаёт child, если его ещё нет, иначе синхронизирует его контент/номер/статус.
	 */
	private function upsertChild(
		int $existingId,
		\WP_Post $parent,
		string $childPostType,
		string $taxonomy,
		int $number,
		string $condition,
		string $answer
	): int {
		$title = "№ {$number}. " . $parent->post_title;

		if ( $existingId > 0 && $this->posts->get( $existingId ) ) {
			$this->posts->update( $existingId, array(
				'post_title'  => $title,
				'post_status' => $parent->post_status,
			) );
			$childId = $existingId;
		} else {
			$childId = $this->posts->insert( array(
				'post_title'  => $title,
				'post_type'   => $childPostType,
				'post_status' => $parent->post_status,
			) );
		}

		if ( ! $childId ) {
			return 0;
		}

		$this->posts->updateMeta( $childId, PostMetaName::TemplateType->value, TaskTemplate::Standard->value );
		$this->posts->updateMeta( $childId, PostMetaName::Meta->value, array(
			'task_condition' => $condition,
			'task_answer'    => $answer,
		) );
		$this->posts->updateMeta( $childId, PostMetaName::TaskBundleParentId->value, $parent->ID );

		// Банковские задачи (fs_lms_problems) таксономии номеров не имеют — номер
		// вводится вручную в Work/Assessment builder, как у любой другой банковской задачи.
		if ( '' !== $taxonomy ) {
			$termId = $this->terms->getOrCreateIdByName( (string) $number, $taxonomy );
			if ( $termId > 0 ) {
				$this->terms->setPostTerms( $childId, array( $termId ), $taxonomy );
			}
		}

		return $childId;
	}
}
