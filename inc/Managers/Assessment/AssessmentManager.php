<?php

declare( strict_types=1 );

namespace Inc\Managers\Assessment;

use Inc\Managers\Wp\PostManager;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class AssessmentManager
 *
 * Read-only доступ к CPT {key}_assessments: получение DTO по ID и банк по предмету.
 *
 * @package Inc\Managers
 */
class AssessmentManager {

	/**
	 * WP filter: настройки станции-экзамена (время/попытки/проходной балл/шкала)
	 * поверх собранного из меты `AssessmentDTO`. Ядро не знает про
	 * `Inc\Modules\EgeComputer` — модуль сам решает, для каких `kind` подменять
	 * значения (см. `EgeComputerModule::applyStationSettings()`), тем же приёмом,
	 * что и `AssessmentPageController::RENDERER_FILTER`.
	 *
	 *   apply_filters( self::STATION_SETTINGS_FILTER, $dto )
	 */
	public const STATION_SETTINGS_FILTER = 'fs_lms_assessment_station_settings';

	public function __construct(
		private readonly PostManager $posts,
	) {}

	public function get( int $assessmentId ): ?AssessmentDTO {
		$post = get_post( $assessmentId );
		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
			return null;
		}

		$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
		return apply_filters(
			self::STATION_SETTINGS_FILTER,
			AssessmentDTO::fromPost( $post, is_array( $meta ) ? $meta : [] )
		);
	}

	/**
	 * Сохраняет упорядоченный список task_ids степ-листа контрольной (мерж мета).
	 *
	 * @param int   $assessmentId
	 * @param int[] $itemIds
	 */
	/**
	 * @param int[]      $itemIds
	 * @param float[]    $taskPoints  taskId => points
	 * @param string[]|null $taskNumbers taskId => номер, явное значение (используется только
	 *                                   переносом ссылок связки при смене ID, {@see \Inc\Services\Task\TaskBundleMigrationPlanner});
	 *                                   null (по умолчанию) — вычисляется из меты банковских
	 *                                   задач ({@see deriveTaskNumbers()}), обычный путь сохранения из конструктора.
	 */
	public function setItemIds( int $assessmentId, array $itemIds, array $taskPoints = [], ?array $taskNumbers = null ): bool {
		$post = get_post( $assessmentId );
		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
			return false;
		}

		// Гард дублей: одна задача не может стоять в двух слотах (задача 6).
		$ids = array_values( array_map( 'intval', $itemIds ) );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return false;
		}

		$meta                 = $this->posts->getMeta( $assessmentId, PostMetaName::Meta->value, true );
		$meta                 = is_array( $meta ) ? $meta : array();
		$meta['task_ids']     = $ids;
		$meta['task_points']  = $taskPoints;
		$meta['task_numbers'] = $taskNumbers ?? $this->deriveTaskNumbers( $ids, PostTypeResolver::subjectFromAssessmentPostType( $post->post_type ) );

		$this->posts->updateMeta( $assessmentId, PostMetaName::Meta->value, $meta );
		return true;
	}

	/**
	 * Номер позиции банковских задач (fs_lms_problems) — больше не вводится вручную
	 * в конструкторе, а вычисляется из собственной меты задачи (метабокс «Предмет и
	 * номер задания», {@see \Inc\Controllers\Problems\ProblemsController}). Задача
	 * учитывается только если помечена ТЕМ ЖЕ предметом, что и контрольная — иначе
	 * {@see \Inc\Services\Assessment\EgeCompletenessChecker} увидит её «сиротой».
	 *
	 * @param int[] $itemIds
	 *
	 * @return array<int, string> taskId => номер
	 */
	private function deriveTaskNumbers( array $itemIds, string $subjectKey ): array {
		$numbers = array();
		foreach ( $itemIds as $taskId ) {
			$taskSubject = (string) $this->posts->getMeta( $taskId, PostMetaName::BankTaskSubject->value );
			if ( '' === $subjectKey || $taskSubject !== $subjectKey ) {
				continue;
			}

			$number = trim( (string) $this->posts->getMeta( $taskId, PostMetaName::BankTaskNumber->value ) );
			if ( '' !== $number ) {
				$numbers[ $taskId ] = $number;
			}
		}

		return $numbers;
	}

	/**
	 * @param string $subjectKey
	 * @param array  $args Дополнительные аргументы get_posts().
	 * @return AssessmentDTO[]
	 */
	public function getBankBySubject( string $subjectKey, array $args = [] ): array {
		$posts = get_posts( array_merge( [
			'post_type'   => PostTypeResolver::assessments( $subjectKey ),
			'post_status' => [ 'publish', 'draft' ],
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		], $args ) );

		return array_map( static function ( \WP_Post $post ): AssessmentDTO {
			$meta = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
			return apply_filters(
				self::STATION_SETTINGS_FILTER,
				AssessmentDTO::fromPost( $post, is_array( $meta ) ? $meta : [] )
			);
		}, $posts );
	}
}
