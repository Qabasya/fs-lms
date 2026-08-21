<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\DTO\Subject\SubjectDTO;
use Inc\DTO\Task\TaskBundleReferenceChangeDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskBundleMigrationPlanner
 *
 * Пакетная миграция существующего банка связок 19-21 на модель parent+children
 * (см. .docs/Tasks.md, §4). Две независимые фазы, план отделён от применения
 * (тот же паттерн, что {@see \Inc\Services\Subject\ArticleSlugPlanner}):
 *
 *  1. Материализация — {@see findBundleParents()} + {@see materialize()}: находит
 *     все `triple_task`-посты (предметные задания + банк) и досоздаёт/синхронизирует
 *     их children через {@see TaskBundleService::syncChildren()}. Идемпотентно,
 *     безопасно гонять повторно даже без `--dry-run`.
 *  2. Переезд ссылок — {@see planReferenceUpdates()} + {@see applyReferenceUpdates()}:
 *     находит Work/Assessment, чьи itemIds/taskIds указывают на parent-id связки,
 *     и заменяет один id на 3 id children. Необратимо переписывает уже
 *     опубликованные работы/экзамены — только по явному запросу без `--dry-run`.
 *     Исторические строки `task_attempts`/`assessment_answers` по старому
 *     parent-`task_id` не трогает — они остаются архивом прошлых сдач.
 *
 * @package Inc\Services\Task
 */
readonly class TaskBundleMigrationPlanner {

	public function __construct(
		private PostManager $posts,
		private SubjectRepository $subjects,
		private TaskBundleService $bundles,
		private WorkManager $works,
		private AssessmentManager $assessments,
	) {}

	/**
	 * Все parent-посты связки (`triple_task`) — предметные задания всех предметов
	 * с банком + глобальный банк (`fs_lms_problems`).
	 *
	 * @return int[]
	 */
	public function findBundleParents(): array {
		$postTypes = array_map(
			static fn( SubjectDTO $subject ): string => PostTypeResolver::tasks( $subject->key ),
			array_filter( $this->subjects->readAll(), static fn( SubjectDTO $subject ): bool => $subject->hasBank )
		);
		$postTypes[] = PostTypeResolver::problems();

		if ( array() === $postTypes ) {
			return array();
		}

		$result = $this->posts->query( 'any', array(
			'post_type'      => $postTypes,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'meta_key'       => PostMetaName::TemplateType->value,
			'meta_value'     => TaskTemplate::Triple->value,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		return array_map( 'intval', $result['posts'] );
	}

	/**
	 * Материализует/пересинхронизирует children для списка parent-постов.
	 *
	 * @param int[] $parentIds
	 *
	 * @return array<int, int[]> parent_id => [id19, id20, id21]
	 */
	public function materialize( array $parentIds ): array {
		$summary = array();
		foreach ( $parentIds as $parentId ) {
			$summary[ $parentId ] = $this->bundles->syncChildren( $parentId );
		}

		return $summary;
	}

	/**
	 * План переезда ссылок Work/Assessment на children — по уже материализованным
	 * связкам (parent_id => [id19, id20, id21] из {@see materialize()}).
	 *
	 * @param array<int, int[]> $parentToChildren
	 *
	 * @return TaskBundleReferenceChangeDTO[]
	 */
	public function planReferenceUpdates( array $parentToChildren ): array {
		if ( array() === $parentToChildren ) {
			return array();
		}

		$changes = array();

		foreach ( $this->subjectKeys() as $subjectKey ) {
			foreach ( $this->works->getBankBySubject( $subjectKey, array( 'limit' => -1 ) ) as $work ) {
				$touched = array_intersect( $work->itemIds, array_keys( $parentToChildren ) );
				if ( array() === $touched ) {
					continue;
				}

				$changes[] = new TaskBundleReferenceChangeDTO(
					post_id:      $work->id,
					kind:         'work',
					title:        $work->title,
					old_item_ids: $work->itemIds,
					new_item_ids: $this->expandIds( $work->itemIds, $parentToChildren ),
				);
			}

			foreach ( $this->assessments->getBankBySubject( $subjectKey ) as $assessment ) {
				$touched = array_intersect( $assessment->taskIds, array_keys( $parentToChildren ) );
				if ( array() === $touched ) {
					continue;
				}

				// Только связки, реально стоящие в ЭТОЙ работе/контрольной — иначе
				// taskNumbers засоряется номерами чужих, никак не связанных задач.
				$relevant = array_intersect_key( $parentToChildren, array_flip( $touched ) );

				$changes[] = new TaskBundleReferenceChangeDTO(
					post_id:          $assessment->id,
					kind:             'assessment',
					title:            $assessment->title,
					old_item_ids:     $assessment->taskIds,
					new_item_ids:     $this->expandIds( $assessment->taskIds, $parentToChildren ),
					new_task_points:  $this->expandPoints( $assessment->taskPoints, $parentToChildren ),
					new_task_numbers: $this->expandNumbers( $assessment->taskNumbers, $relevant ),
				);
			}
		}

		return $changes;
	}

	/**
	 * Применяет план переезда ссылок.
	 *
	 * `WorkManager::setItemIds()`/`AssessmentManager::setItemIds()` молча
	 * отказывают (`false`), если итоговый список содержит дубли — гард от
	 * двойного слота (задача 6). Наш `expandIds()` дублей не создаёт, но не
	 * лечит уже существующие в данных (обнаружено на реальном dev-банке —
	 * работа с повторяющимся item_id ещё ДО миграции), поэтому такие записи
	 * не обновляются молча — их нужно явно разобрать вручную.
	 *
	 * @param TaskBundleReferenceChangeDTO[] $changes
	 *
	 * @return array{applied: TaskBundleReferenceChangeDTO[], failed: TaskBundleReferenceChangeDTO[]}
	 */
	public function applyReferenceUpdates( array $changes ): array {
		$applied = array();
		$failed  = array();

		foreach ( $changes as $change ) {
			$ok = 'work' === $change->kind
				? $this->works->setItemIds( $change->post_id, $change->new_item_ids )
				: $this->assessments->setItemIds(
					$change->post_id,
					$change->new_item_ids,
					$change->new_task_points,
					$change->new_task_numbers
				);

			if ( $ok ) {
				$applied[] = $change;
			} else {
				$failed[] = $change;
			}
		}

		return array( 'applied' => $applied, 'failed' => $failed );
	}

	/**
	 * Заменяет parent-id на его 3 children, сохраняя порядок и позицию.
	 *
	 * @param int[]              $ids
	 * @param array<int, int[]>  $parentToChildren
	 *
	 * @return int[]
	 */
	private function expandIds( array $ids, array $parentToChildren ): array {
		$result = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $parentToChildren[ $id ] ) ) {
				array_push( $result, ...$parentToChildren[ $id ] );
			} else {
				$result[] = $id;
			}
		}

		return $result;
	}

	/**
	 * Вес parent-а (если был) разворачивается в вес 1 на каждого child —
	 * тот же баллаж, что и у обычного одиночного номера (см. .docs/Tasks.md, §3.5).
	 *
	 * @param array<int, float> $points
	 * @param array<int, int[]> $parentToChildren
	 *
	 * @return array<int, float>
	 */
	private function expandPoints( array $points, array $parentToChildren ): array {
		$result = array();
		foreach ( $points as $id => $value ) {
			$id = (int) $id;
			if ( isset( $parentToChildren[ $id ] ) ) {
				foreach ( $parentToChildren[ $id ] as $childId ) {
					$result[ $childId ] = 1.0;
				}
			} else {
				$result[ $id ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Ручной номер (Задача 8, фолбэк для банковских задач без таксономии) —
	 * снимается с parent-а и, только для банковских children (fs_lms_problems,
	 * у них нет `{key}_task_number`), проставляется заново по фиксированному
	 * порядку {@see TaskBundleService::NUMBERS}. Предметные children номер
	 * получают из своего терма автоматически — запись в карте им не нужна.
	 *
	 * @param array<int, string> $numbers
	 * @param array<int, int[]>  $parentToChildren
	 *
	 * @return array<int, string>
	 */
	private function expandNumbers( array $numbers, array $parentToChildren ): array {
		$result = $numbers;

		foreach ( $parentToChildren as $parentId => $childIds ) {
			unset( $result[ $parentId ] );

			foreach ( $childIds as $i => $childId ) {
				$child = $this->posts->get( $childId );
				if ( $child && PostTypeResolver::isProblemPostType( $child->post_type ) ) {
					$result[ $childId ] = (string) ( TaskBundleService::NUMBERS[ $i ] ?? '' );
				}
			}
		}

		return $result;
	}

	/**
	 * Все предметы, включая без банка (`hasBank = false`): Work/Assessment CPT
	 * регистрируются независимо от банка заданий/статей ({@see
	 * \Inc\Registrars\SubjectContentRegistrar::registerAll()} — «Уроки/работы/
	 * курсы/контрольные регистрируются как обычно» даже без tasks/articles CPT).
	 * В отличие от {@see findBundleParents()}, здесь фильтр по `hasBank` НЕ
	 * нужен и был бы багом — связка из банка (`fs_lms_problems`) может стоять
	 * в работе/контрольной предмета без собственного банка.
	 *
	 * @return string[]
	 */
	private function subjectKeys(): array {
		return array_map( static fn( SubjectDTO $subject ): string => $subject->key, $this->subjects->readAll() );
	}
}
