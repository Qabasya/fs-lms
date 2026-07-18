<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\SlugGenerator;

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
	use SlugGenerator;

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
	 * Экспортирует `groups.yaml` сервиса fs-video-uploader: папки групп
	 * (`lms: {group_id, course_id, teacher_id}`) + личные папки преподавателей
	 * для индивидуальных занятий (`lms: {teacher_id}`), по одной на каждого
	 * пользователя с ролью «Преподаватель» — независимо от того, ведёт ли он
	 * сейчас индивидуальные занятия (проще один раз завести папку заранее).
	 */
	public function ajaxExportGroups(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$lines   = array( 'groups:' );
		$skipped = 0;

		foreach ( $this->groups->findAll() as $group ) {
			$courseId  = (int) ( $group->course_id ?? 0 );
			$teacherId = (int) ( $group->teacher_id ?? 0 );

			if ( ! $courseId || ! $teacherId ) {
				$skipped++;
				continue;
			}

			$this->appendEntry(
				$lines,
				(string) $group->name,
				$this->slugify( (string) $group->name, 'group-' . $group->id ),
				array(
					'group_id'   => (int) $group->id,
					'course_id'  => $courseId,
					'teacher_id' => $teacherId,
				)
			);
		}

		$teachers = get_users( array( 'role' => UserRole::FSTeacher->value, 'fields' => 'all' ) );
		foreach ( $teachers as $user ) {
			$lastName = (string) get_user_meta( $user->ID, 'last_name', true );

			$this->appendEntry(
				$lines,
				'Индивидуальные-' . ( '' !== $lastName ? $lastName : $user->display_name ),
				'ind-' . $this->slugify( $user->user_login, (string) $user->ID ),
				array( 'teacher_id' => (int) $user->ID )
			);
		}

		$this->success( array(
			'yaml'     => implode( "\n", $lines ) . "\n",
			'skipped'  => $skipped,
			'teachers' => count( $teachers ),
		) );
	}

	/**
	 * @param array<int, string> $lines
	 * @param array<string, int> $lms
	 */
	private function appendEntry( array &$lines, string $label, string $slug, array $lms ): void {
		$label     = str_replace( '"', '\\"', $label );
		$lines[]   = "  \"{$label}\":";
		$lines[]   = "    slug: {$slug}";
		$lines[]   = '    lms:';
		foreach ( $lms as $key => $value ) {
			$lines[] = "      {$key}: {$value}";
		}
	}
}
