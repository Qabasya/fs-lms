<?php
/**
 * Неизменяемость варианта экзамена (этап 13.2, SPEC §12): после первого старта (или начала сеанса по времени) состав, веса, длительность
 *
 * @package FS_LMS
 */

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

	/**
	 * Снимок замороженных вариантов для повторных проверок в текущем запросе.
	 *
	 * @var array<int, array{event_title: string, subject_key: string}>|null assessmentId => проведение
	 */
	private ?array $frozen = null;

	/**
	 * Снимок принадлежности заданий замороженным вариантам в текущем запросе.
	 *
	 * @var array<int, int>|null taskId => assessmentId
	 */
	private ?array $tasks = null;

	/** Читает замороженные проведения и записи через менеджеры; восстановление полей остаётся в политике. */
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

	/**
	 * Проверяет присутствие варианта в карте действующих замороженных проведений.
	 */
	public function isAssessmentFrozen( int $assessmentId ): bool {
		return isset( $this->frozenMap()[ $assessmentId ] );
	}

	/** Задание входит в состав замороженного варианта (по снимку проведения), в том числе дочерние задания связки. */
	public function isTaskFrozen( int $taskId ): bool {
		return isset( $this->taskMap()[ $taskId ] );
	}

	/**
	 * Правка только текста условия замороженного задания: поля `*_condition` меняются, всё остальное
	 * (ответ, баллы, шаблон, состав) остаётся как было. Опечатку в условии исправить можно — на
	 * результат и оценивание она не влияет. Только для заданий; сам вариант (контрольная) этим не открывается.
	 *
	 * @param mixed $newMeta Новое значение меты `fs_lms_meta` задания
	 */
	public function isConditionOnlyEdit( int $taskId, mixed $newMeta ): bool {
		if ( ! is_array( $newMeta ) || ! $this->isTaskFrozen( $taskId ) || $this->isAssessmentFrozen( $taskId ) ) {
			return false;
		}

		$current = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );

		return is_array( $current ) && $this->withoutConditions( $current ) === $this->withoutConditions( $newMeta );
	}

	/** Значение меты не меняется (запись того же): такая запись безвредна и отказа не вызывает. */
	public function isMetaUnchanged( int $postId, string $metaKey, mixed $newValue ): bool {
		$current = $this->posts->getMeta( $postId, $metaKey );

		return is_array( $current ) && is_array( $newValue )
			? $this->normalized( $current ) === $this->normalized( $newValue )
			: (string) $current === (string) $newValue;
	}

	/**
	 * @param array<mixed> $meta
	 *
	 * @return array<mixed>
	 */
	private function normalized( array $meta ): array {
		$out = array();
		foreach ( $meta as $key => $value ) {
			$out[ $key ] = is_array( $value ) ? $this->normalized( $value ) : (string) $value;
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Мета без полей условия, значения приведены к строкам: «2» и 2 не считаются правкой.
	 *
	 * @param array<mixed> $meta
	 *
	 * @return array<mixed>
	 */
	private function withoutConditions( array $meta ): array {
		$kept = array();
		foreach ( $meta as $key => $value ) {
			if ( is_string( $key ) && str_ends_with( $key, '_condition' ) ) {
				continue;
			}
			$kept[ $key ] = is_array( $value ) ? $this->withoutConditions( $value ) : (string) $value;
		}
		ksort( $kept );

		return $kept;
	}

	/** Любой пост работы или задания: вариант либо задание замороженного варианта. */
	public function isPostFrozen( int $postId ): bool {
		return $this->isAssessmentFrozen( $postId ) || $this->isTaskFrozen( $postId );
	}

	/**
	 * Сохраняет прежние поля замороженной записи при обновлении через редактор или импорт.
	 *
	 * @param int                  $postId ID обновляемого поста, 0 для новой записи.
	 * @param array<string, mixed> $data   Поля, подготовленные WordPress к сохранению.
	 * @return array<string, mixed>
	 */
	public function preserveFrozenPostData( int $postId, array $data ): array {
		if ( $postId <= 0 || ! $this->isPostFrozen( $postId ) ) {
			return $data;
		}

		$current = $this->posts->get( $postId );
		if ( null === $current ) {
			return $data;
		}

		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' ) as $field ) {
			if ( isset( $data[ $field ] ) && (string) $data[ $field ] !== (string) $current->{$field} ) {
				$data[ $field ] = $current->{$field};
			}
		}

		return $data;
	}

	/** Вложение используется заданием замороженного варианта: прикреплено к нему или упоминается в его данных. */
	public function isAttachmentFrozen( int $attachmentId ): bool {
		$parent = (int) ( $this->posts->get( $attachmentId )->post_parent ?? 0 );
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

	/**
	 * Кеширует соответствие вариантов опубликованным проведениям в пределах экземпляра.
	 *
	 * @return array<int, array{event_title: string, subject_key: string}>
	 */
	private function frozenMap(): array {
		if ( null === $this->frozen ) {
			$this->frozen = array();
			foreach ( $this->sessions->listFrozenVariants( $this->time->nowUtc() ) as $row ) {
				$this->frozen[ $row['assessment_id'] ] ??= array(
					'event_title' => $row['event_title'],
					'subject_key' => $row['subject_key'],
				);
			}
		}

		return $this->frozen;
	}

	/**
	 * Кеширует задания замороженных вариантов и их дочерние записи.
	 *
	 * @return array<int, int>
	 */
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
			$ids = $this->assessments->get( $assessmentId )->taskIds ?? array();
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Читает и нормализует идентификаторы дочерних заданий комплекта.
	 *
	 * @return list<int>
	 */
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
