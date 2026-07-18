<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Shared\Traits\Authorizer;

/**
 * Class VideoLibrarySettingsCallbacks
 *
 * AJAX-обработчики настроек модуля VideoLibrary.
 * Включение/выключение модуля — на Dashboard (`fs_lms_module_toggle_video_library`).
 * HMAC-секрет и S3-реквизиты — константы wp-config (в БД не хранятся), поэтому
 * сохраняемых полей нет.
 *
 * @package Inc\Modules\VideoLibrary\Callbacks
 */
class VideoLibrarySettingsCallbacks extends BaseController {

	use Authorizer;

	public function __construct(
		private readonly GroupsRepository $groups,
	) {
		parent::__construct();
	}

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );
		$this->success( array( 'message' => 'Настройки сохранены.' ) );
	}

	/**
	 * Экспортирует групповую часть конфига `groups.yaml` сервиса fs-video-uploader:
	 * маппинг «папка группы» → `lms: {group_id, course_id, teacher_id}`.
	 * Только групповые занятия (личные папки преподавателей для индивидуальных
	 * занятий конфиг не знает — добавляются в файл вручную).
	 */
	public function ajaxExportGroups(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$rows    = array();
		$skipped = 0;

		foreach ( $this->groups->findAll() as $group ) {
			$courseId  = (int) ( $group->course_id ?? 0 );
			$teacherId = (int) ( $group->teacher_id ?? 0 );

			if ( ! $courseId || ! $teacherId ) {
				$skipped++;
				continue;
			}

			$rows[] = array(
				'name'       => (string) $group->name,
				'group_id'   => (int) $group->id,
				'course_id'  => $courseId,
				'teacher_id' => $teacherId,
			);
		}

		$this->success( array(
			'yaml'    => $this->buildGroupsYaml( $rows ),
			'count'   => count( $rows ),
			'skipped' => $skipped,
		) );
	}

	/**
	 * @param array<int, array{name: string, group_id: int, course_id: int, teacher_id: int}> $rows
	 */
	private function buildGroupsYaml( array $rows ): string {
		if ( ! $rows ) {
			return "groups: {}\n";
		}

		$lines = array( 'groups:' );
		foreach ( $rows as $row ) {
			$name    = str_replace( '"', '\\"', $row['name'] );
			$lines[] = "  \"{$name}\":";
			$lines[] = '    lms:';
			$lines[] = "      group_id: {$row['group_id']}";
			$lines[] = "      course_id: {$row['course_id']}";
			$lines[] = "      teacher_id: {$row['teacher_id']}";
		}

		return implode( "\n", $lines ) . "\n";
	}
}
