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
	 * @param int[]    $itemIds
	 * @param float[]  $taskPoints  taskId => points
	 * @param string[] $taskNumbers taskId => номер задания (задача 8: fallback-номер для
	 *                              банковских fs_lms_problems-задач без таксономического терма).
	 */
	public function setItemIds( int $assessmentId, array $itemIds, array $taskPoints = [], array $taskNumbers = [] ): bool {
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
		$meta['task_numbers'] = $taskNumbers;

		$this->posts->updateMeta( $assessmentId, PostMetaName::Meta->value, $meta );
		return true;
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
