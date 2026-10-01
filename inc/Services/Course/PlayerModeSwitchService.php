<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Course\LessonKind;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/**
 * Class PlayerModeSwitchService
 *
 * Переключение «режим преподавателя ⇄ предпросмотр» по клику на бейдж в шапке плеера.
 *
 * Режим преподавателя (`/lesson/?gid=&gl=`) — урок занятия группы; предпросмотр
 * (`/course-preview/?course=&lesson=`) — тот же урок без группы. В предпросмотр
 * переключиться может только автор курсов ({@see CoursePreviewAccessGuard::canPreview()}),
 * обратно — тот, кто управляет группой, где этот урок заведён. Пустая строка — переключать
 * некуда (бейдж остаётся обычной меткой).
 *
 * @package Inc\Services\Course
 */
class PlayerModeSwitchService {

	public function __construct(
		private readonly CoursePreviewAccessGuard $previewAccess,
		private readonly GroupAccessGuard         $groupAccess,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly GroupsRepository         $groups,
	) {}

	/**
	 * Из режима преподавателя — в предпросмотр того же курса и урока. Занятие уходит в
	 * параметре `gl`, чтобы бейдж предпросмотра вёл обратно в ту же группу.
	 */
	public function previewUrl( int $userId, int $courseId, int $lessonId, int $groupLessonId ): string {
		if ( $courseId <= 0 || $lessonId <= 0 || ! $this->previewAccess->canPreview( $userId, $courseId ) ) {
			return '';
		}

		return (string) add_query_arg(
			array(
				'course' => $courseId,
				'lesson' => $lessonId,
				'gl'     => $groupLessonId,
			),
			PageRoutes::CoursePreview->url()
		);
	}

	/**
	 * Из предпросмотра — в режим преподавателя. Сначала занятие, из которого пришли
	 * (`$requestedGroupLessonId`, проверяется: тот же урок и право управлять группой),
	 * иначе первое групповое занятие этого урока в группах самого преподавателя.
	 */
	public function teacherUrl( int $userId, int $lessonId, int $requestedGroupLessonId ): string {
		if ( $lessonId <= 0 ) {
			return '';
		}

		if ( $requestedGroupLessonId > 0 ) {
			$row = $this->groupLessons->find( $requestedGroupLessonId );
			if (
				null !== $row
				&& $row->lessonId === $lessonId
				&& $this->groupAccess->canManage( $row->groupId, $userId )
			) {
				return PageRoutes::LessonPlayer->lessonUrl( $row->groupId, $row->id );
			}
		}

		foreach ( $this->groups->findByTeacherId( $userId ) as $group ) {
			foreach ( $this->groupLessons->listByGroup( (int) $group->id ) as $row ) {
				if ( $row->lessonId === $lessonId && LessonKind::Group === $row->kind ) {
					return PageRoutes::LessonPlayer->lessonUrl( $row->groupId, $row->id );
				}
			}
		}

		return '';
	}
}
