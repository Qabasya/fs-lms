<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Repositories;

use Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingInputDTO;
use Inc\Modules\VideoLibrary\Enums\VideoRecordingStatus;
use Inc\Modules\VideoLibrary\Schema\VideoSchema;

/**
 * Class VideoRecordingRepository
 *
 * Доступ к таблице `fs_lms_video_recordings` (реестр записей занятий в S3).
 * Идемпотентность регистрации — upsert по `s3_key`; привязка к занятию
 * (`group_lesson_id` + `status`) меняется только методами attach()/detach(),
 * повторный upsert обновляет лишь метаданные.
 *
 * @package Inc\Modules\VideoLibrary\Repositories
 */
class VideoRecordingRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = VideoSchema::table();
	}

	public function find( int $id ): ?VideoRecordingDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id )
		);
		return $row ? VideoRecordingDTO::fromRow( $row ) : null;
	}

	public function findByS3Key( string $s3Key ): ?VideoRecordingDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE s3_key = %s LIMIT 1", $s3Key )
		);
		return $row ? VideoRecordingDTO::fromRow( $row ) : null;
	}

	/**
	 * Upsert по `s3_key`: select → insert/update. Повторная отправка обновляет только
	 * метаданные — привязку (`group_lesson_id`/`status`) не трогает.
	 *
	 * @param VideoRecordingInputDTO $input           Валидированный payload регистрации.
	 * @param string                 $recordedAtLocal `recorded_at`, нормализованный к TZ сайта ('Y-m-d H:i:s').
	 * @param int|null               $teacherUserId   Резолвнутый WP-пользователь из `teacher_username` (индив. ветка).
	 *
	 * @return array{id:int, isNew:bool, existing:?VideoRecordingDTO}
	 */
	public function upsertByS3Key( VideoRecordingInputDTO $input, string $recordedAtLocal, ?int $teacherUserId ): array {
		$data = array(
			's3_bucket'       => $input->s3Bucket,
			'manifest_key'    => $input->manifestKey,
			'group_slug'      => $input->groupSlug,
			'group_id'        => $input->groupId,
			'teacher_user_id' => $teacherUserId,
			'recorded_at'     => $recordedAtLocal,
			'size_bytes'      => $input->sizeBytes,
			'sha256'          => $input->sha256,
			'duration_sec'    => $input->durationSec,
			'payload'         => $input->payload,
		);

		$existing = $this->findByS3Key( $input->s3Key );
		if ( null !== $existing ) {
			$this->wpdb->update( $this->table, $data, array( 'id' => $existing->id ) );
			return array(
				'id'       => $existing->id,
				'isNew'    => false,
				'existing' => $existing,
			);
		}

		$this->wpdb->insert(
			$this->table,
			$data + array(
				's3_key' => $input->s3Key,
				'status' => VideoRecordingStatus::Unmatched->value,
			)
		);

		return array(
			'id'       => (int) $this->wpdb->insert_id,
			'isNew'    => true,
			'existing' => null,
		);
	}

	/** Привязывает запись к занятию (в т.ч. вручную из админки). */
	public function attach( int $id, int $groupLessonId ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array(
				'group_lesson_id' => $groupLessonId,
				'status'          => VideoRecordingStatus::Matched->value,
			),
			array( 'id' => $id )
		);
	}

	/** Снимает привязку: запись возвращается в unmatched (ручная отвязка). */
	public function detach( int $id ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			array(
				'group_lesson_id' => null,
				'status'          => VideoRecordingStatus::Unmatched->value,
			),
			array( 'id' => $id )
		);
	}

	/** @return VideoRecordingDTO[] Непривязанные записи этой группы (кандидаты ручной привязки в алёрте З3), новые сверху. */
	public function listByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE group_id = %d AND status = %s ORDER BY recorded_at DESC",
				$groupId,
				VideoRecordingStatus::Unmatched->value
			)
		);
		return array_map( static fn( $r ) => VideoRecordingDTO::fromRow( $r ), $rows ?: array() );
	}

}
