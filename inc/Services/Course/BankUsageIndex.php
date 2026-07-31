<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\StepType;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class BankUsageIndex
 *
 * Обратный индекс «потребитель → на что ссылается» для банков контента:
 * курс → уроки, урок → работы, урок → контрольные (в рамках предмета),
 * работа → задачи и курс → задачи (кросс-предметно, банк задач общий).
 *
 * @package Inc\Services\Course
 *
 * ### Зачем отдельно от ContentUsageService
 *
 * {@see ContentUsageService} отвечает на вопрос «где используется ВОТ ЭТОТ пост»
 * (usageCount/usageList для гардов удаления). Здесь — обратная задача: один
 * проход по всему банку потребителей, чтобы построить фильтр списка
 * («показать уроки курса X», «показать неиспользуемые»).
 *
 * Формат индекса — список троек `[consumer_id, consumer_title, ref_ids[]]`.
 */
readonly class BankUsageIndex {

	/** Статусы потребителей: черновики и архив тоже «используют» контент. */
	private const CONSUMER_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' );

	/**
	 * @param PostManager       $posts    Доступ к записям банков
	 * @param SubjectRepository $subjects Предметы — для кросс-предметных индексов банка задач
	 */
	public function __construct(
		private PostManager       $posts,
		private SubjectRepository $subjects,
	) {}

	/**
	 * Уроки → курсы: индекс курс → lesson_ids.
	 *
	 * @param string $subject Ключ предмета
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	public function coursesByLesson( string $subject ): array {
		return $this->build(
			PostTypeResolver::courses( $subject ),
			static function ( array $meta ): array {
				$ids = array();
				foreach ( ModuleDTO::fromList( is_array( $meta['modules'] ?? null ) ? $meta['modules'] : array() ) as $module ) {
					foreach ( $module->lessonIds as $lid ) {
						$ids[] = $lid;
					}
				}

				return $ids;
			}
		);
	}

	/**
	 * Работы → уроки: индекс урок → work_ids из шагов.
	 *
	 * @param string $subject Ключ предмета
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	public function lessonsByWork( string $subject ): array {
		return $this->build(
			PostTypeResolver::lessons( $subject ),
			static fn( array $meta ): array => self::stepRefIds( $meta, StepType::Work )
		);
	}

	/**
	 * Контрольные → уроки: индекс урок → assessment_ids из шагов.
	 *
	 * @param string $subject Ключ предмета
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	public function lessonsByAssessment( string $subject ): array {
		return $this->build(
			PostTypeResolver::lessons( $subject ),
			static fn( array $meta ): array => self::stepRefIds( $meta, StepType::Assessment )
		);
	}

	/**
	 * Работы → задачи: кросс-предметный индекс работа → item_ids.
	 *
	 * Банк задач (`fs_lms_problems`) общий для всех предметов, поэтому обход
	 * идёт по всем предметам сразу — в отличие от индексов уроков/курсов.
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	public function worksByProblem(): array {
		$result = array();

		foreach ( $this->subjects->readAll() as $subject ) {
			foreach ( $this->build( PostTypeResolver::works( $subject->key ), static fn( array $meta ): array => self::itemIds( $meta ) ) as $row ) {
				$result[] = $row;
			}
		}

		return $result;
	}

	/**
	 * Курсы → задачи: кросс-предметный индекс курс → problem_ids (через уроки и работы).
	 *
	 * Курсы без задач в индекс не попадают — фильтру они не нужны.
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	public function coursesByProblem(): array {
		$result = array();

		foreach ( $this->subjects->readAll() as $subject ) {
			$courses = $this->posts->search( PostTypeResolver::courses( $subject->key ), array( 'status' => self::CONSUMER_STATUSES ) );

			foreach ( $courses as $course ) {
				$problemIds = $this->problemIdsOfCourse( $course->ID );
				if ( ! empty( $problemIds ) ) {
					$result[] = array( $course->ID, $course->post_title, $problemIds );
				}
			}
		}

		return $result;
	}

	/**
	 * Задачи курса: модули → уроки → шаги-работы → item_ids работы.
	 *
	 * @param int $courseId ID курса
	 *
	 * @return int[]
	 */
	private function problemIdsOfCourse( int $courseId ): array {
		$meta       = $this->meta( $courseId );
		$problemIds = array();

		foreach ( ModuleDTO::fromList( is_array( $meta['modules'] ?? null ) ? $meta['modules'] : array() ) as $module ) {
			foreach ( $module->lessonIds as $lessonId ) {
				$lessonMeta = $this->meta( (int) $lessonId );
				$steps      = StepDTO::fromList( is_array( $lessonMeta['steps'] ?? null ) ? $lessonMeta['steps'] : array() );

				foreach ( $steps as $step ) {
					if ( StepType::Work !== $step->type ) {
						continue;
					}
					$workId = (int) ( $step->payload['ref'] ?? 0 );
					if ( $workId > 0 ) {
						$problemIds = array_merge( $problemIds, self::itemIds( $this->meta( $workId ) ) );
					}
				}
			}
		}

		return array_values( array_unique( $problemIds ) );
	}

	/**
	 * Мета записи как массив.
	 *
	 * @param int $postId ID записи
	 */
	private function meta( int $postId ): array {
		$meta = $this->posts->getMeta( $postId, PostMetaName::Meta->value );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Состав работы (`item_ids`) как список int.
	 *
	 * @param array $meta Мета работы
	 *
	 * @return int[]
	 */
	private static function itemIds( array $meta ): array {
		return array_values( array_filter( array_map( 'intval', is_array( $meta['item_ids'] ?? null ) ? $meta['item_ids'] : array() ) ) );
	}

	/**
	 * Все ID, на которые ссылается хоть один потребитель.
	 *
	 * @param array<int, array{int, string, int[]}> $index Индекс
	 *
	 * @return int[]
	 */
	public function usedIds( array $index ): array {
		$ids = array();
		foreach ( $index as [ , , $refIds ] ) {
			foreach ( $refIds as $rid ) {
				$ids[] = $rid;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * ID, на которые ссылается конкретный потребитель.
	 *
	 * @param array<int, array{int, string, int[]}> $index      Индекс
	 * @param int                                   $consumerId ID потребителя
	 *
	 * @return int[]
	 */
	public function idsFor( array $index, int $consumerId ): array {
		foreach ( $index as [ $id, , $refIds ] ) {
			if ( $id === $consumerId ) {
				return array_values( array_unique( $refIds ) );
			}
		}

		return array();
	}

	/**
	 * Потребители, которые реально что-то используют — для выпадающего фильтра.
	 *
	 * @param array<int, array{int, string, int[]}> $index Индекс
	 *
	 * @return array<int, string> id => заголовок
	 */
	public function consumerOptions( array $index ): array {
		$options = array();
		foreach ( $index as [ $id, $title, $refIds ] ) {
			if ( ! empty( $refIds ) ) {
				$options[ $id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Общий проход по банку потребителей.
	 *
	 * @param string                 $consumerCpt CPT потребителя (курсы/уроки)
	 * @param callable(array): int[] $extract     Извлечение ref-ID из меты потребителя
	 *
	 * @return array<int, array{int, string, int[]}>
	 */
	private function build( string $consumerCpt, callable $extract ): array {
		$consumers = $this->posts->search( $consumerCpt, array(
			'status'  => self::CONSUMER_STATUSES,
			'orderby' => 'title',
		) );

		$result = array();
		foreach ( $consumers as $consumer ) {
			$meta     = $this->posts->getMeta( $consumer->ID, PostMetaName::Meta->value );
			$result[] = array( $consumer->ID, $consumer->post_title, $extract( is_array( $meta ) ? $meta : array() ) );
		}

		return $result;
	}

	/**
	 * Ref-идентификаторы шагов указанного типа из меты урока.
	 *
	 * @param array     $meta Мета урока
	 * @param StepType  $type Тип шага
	 *
	 * @return int[]
	 */
	private static function stepRefIds( array $meta, StepType $type ): array {
		$steps = is_array( $meta['steps'] ?? null ) ? $meta['steps'] : array();
		$ids   = array();

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || ( $step['type'] ?? '' ) !== $type->value ) {
				continue;
			}
			$ref = (int) ( $step['payload']['ref'] ?? 0 );
			if ( $ref > 0 ) {
				$ids[] = $ref;
			}
		}

		return $ids;
	}
}
