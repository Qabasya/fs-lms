<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;

/**
 * Неизменяемость варианта экзамена (этап 13.2, SPEC §12): после первого старта (или начала сеанса по времени) состав, веса, длительность
 * и шкала варианта не меняются никаким путём — админка, правка в модалке, импорт, удаление файлов, удаление предмета.
 *
 * Сервис только отвечает «заморожено ли»; хуки живут в `ExamVariantGuardController`. Замороженное вычисляется один раз на запрос
 * (свойства объекта, не transient): правило «изменяющая операция отклоняется, чтение не затрагивается». Копия варианта создаётся новым
 * постом и свободна. Черновик проведения не замораживает ничего.
 */
class ExamVariantGuard {

	/** Ключи меты задания и работы, которые определяют состав и оценивание: меняются только до заморозки. */
	public const GUARDED_META = array(
		'fs_lms_meta',
		'fs_lms_template_type',
		'fs_lms_assessment_kind',
		'fs_lms_task_bundle_parent_id',
		'fs_lms_task_bundle_child_ids',
		'fs_lms_bank_task_subject',
		'fs_lms_bank_task_number',
		'fs_lms_bank_task_source',
	);

	/** @var array<int, array{event_title: string, subject_key: string}>|null assessmentId => проведение */
	private ?array $frozen = null;

	/** @var array<int, int>|null taskId => assessmentId */
	private ?array $tasks = null;

	public function __construct(
		private readonly ExamSessionRepository $sessions,
		private readonly ExamEventRepository $events,
		private readonly AssessmentManager $assessments,
		private readonly PostManager $posts,
		private readonly ExamTime $time,
	) {}

	/**
	 * Замороженные варианты.
	 *
	 * @return list<int>
	 */
	public function frozenAssessmentIds(): array {
		return array_keys( $this->frozenMap() );
	}

	public function isAssessmentFrozen( int $assessmentId ): bool {
		return isset( $this->frozenMap()[ $assessmentId ] );
	}

	/** Задание входит в состав замороженного варианта (по снимку проведения), в том числе дочерние задания связки. */
	public function isTaskFrozen( int $taskId ): bool {
		return isset( $this->taskMap()[ $taskId ] );
	}

	/** Любой пост работы или задания: вариант либо задание замороженного варианта. */
	public function isPostFrozen( int $postId ): bool {
		return $this->isAssessmentFrozen( $postId ) || $this->isTaskFrozen( $postId );
	}

	/** Вложение используется заданием замороженного варианта: прикреплено к нему или упоминается в его данных. */
	public function isAttachmentFrozen( int $attachmentId ): bool {
		$parent = (int) ( $this->posts->get( $attachmentId )?->post_parent ?? 0 );
		if ( $parent > 0 && $this->isPostFrozen( $parent ) ) {
			return true;
		}

		foreach ( array_keys( $this->taskMap() ) as $taskId ) {
			if ( $this->containsId( $this->posts->getMeta( $taskId, PostMetaName::Meta->value ), $attachmentId ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Названия проведений, замораживающих предмет: предмет с замороженным вариантом удалить нельзя.
	 *
	 * @return list<string>
	 */
	public function eventsFreezingSubject( string $subjectKey ): array {
		$titles = array();
		foreach ( $this->frozenMap() as $info ) {
			if ( $info['subject_key'] === $subjectKey ) {
				$titles[ $info['event_title'] ] = $info['event_title'];
			}
		}

		return array_values( $titles );
	}

	/** Текст отказа; для задания — проведение, которое его использует. */
	public function reason( int $postId ): string {
		$assessmentId = $this->isAssessmentFrozen( $postId ) ? $postId : ( $this->taskMap()[ $postId ] ?? 0 );
		$title        = $this->frozenMap()[ $assessmentId ]['event_title'] ?? '';

		return sprintf( 'Вариант используется в экзамене «%s»: изменение недоступно. Создайте копию варианта для следующего проведения.', $title );
	}

	/** @return array<int, array{event_title: string, subject_key: string}> */
	private function frozenMap(): array {
		if ( null === $this->frozen ) {
			$this->frozen = array();
			foreach ( $this->sessions->listFrozenVariants( $this->time->nowUtc() ) as $row ) {
				$this->frozen[ $row['assessment_id'] ] ??= array( 'event_title' => $row['event_title'], 'subject_key' => $row['subject_key'] );
			}
		}

		return $this->frozen;
	}

	/** @return array<int, int> */
	private function taskMap(): array {
		if ( null === $this->tasks ) {
			$this->tasks = array();
			foreach ( $this->sessions->listFrozenVariants( $this->time->nowUtc() ) as $row ) {
				foreach ( $this->taskIdsOf( $row['event_id'], $row['assessment_id'] ) as $taskId ) {
					$this->tasks[ $taskId ] ??= $row['assessment_id'];
					foreach ( $this->childrenOf( $taskId ) as $childId ) {
						$this->tasks[ $childId ] ??= $row['assessment_id'];
					}
				}
			}
		}

		return $this->tasks;
	}

	/**
	 * Состав — по снимку проведения (он определяет попытки); нет снимка — по текущему составу варианта.
	 *
	 * @return list<int>
	 */
	private function taskIdsOf( int $eventId, int $assessmentId ): array {
		$event = $this->events->find( $eventId );
		$ids   = $event?->snapshotFor( $assessmentId )['task_ids'] ?? null;
		if ( ! is_array( $ids ) ) {
			$ids = $this->assessments->get( $assessmentId )?->taskIds ?? array();
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/** @return list<int> */
	private function childrenOf( int $taskId ): array {
		$children = $this->posts->getMeta( $taskId, PostMetaName::TaskBundleChildIds->value );

		return is_array( $children ) ? array_values( array_filter( array_map( 'intval', $children ) ) ) : array();
	}

	/** Есть ли число среди скаляров произвольной структуры (поля задания хранят ID вложений числами или строками). */
	private function containsId( mixed $value, int $id ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->containsId( $item, $id ) ) {
					return true;
				}
			}

			return false;
		}

		return is_scalar( $value ) && ctype_digit( (string) $value ) && (int) $value === $id;
	}
}
