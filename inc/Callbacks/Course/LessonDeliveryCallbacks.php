<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\ProgramAccess;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonDeliveryCallbacks
 *
 * AJAX «доставки» занятия: набор работ и их дедлайны, ссылка на запись.
 *
 * @package Inc\Callbacks\Course
 *
 * Это НЕ структура программы: публикация КТП такие правки не блокирует
 * (T12.3, D13) — состав и даты живут в {@see ProgramCallbacks} и
 * {@see LessonScheduleCallbacks}.
 */
class LessonDeliveryCallbacks extends BaseController {

	use Authorizer;
	use ProgramAccess;
	use Sanitizer;

	/**
	 * @param EffectiveWorksResolver    $worksResolver Эффективный набор работ занятия
	 * @param GroupLessonRepository     $groupLessons  Строки программы
	 * @param ProgramCompositionService $program       Доступ к строке программы
	 * @param GroupAccessGuard          $guard         Доступ к группе
	 */
	public function __construct(
		private readonly EffectiveWorksResolver    $worksResolver,
		private readonly GroupLessonRepository     $groupLessons,
		private readonly ProgramCompositionService $program,
		private readonly GroupAccessGuard          $guard,
	) {
		parent::__construct();
	}

	protected function accessGuard(): GroupAccessGuard {
		return $this->guard;
	}

	protected function programService(): ProgramCompositionService {
		return $this->program;
	}

	public function ajaxSetLessonExtraWorks(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$workIds       = $this->sanitizeIntList( 'work_ids' );
		$userId        = get_current_user_id();

		$this->worksResolver->setExtraWorks( $groupLessonId, $workIds, $userId );
		$this->success();
	}

	/**
	 * Дедлайны работ занятия для поповера в КТП (T12.3, D13): эффективный набор
	 * работ занятия + текущий per-work дедлайн (только явный override — legacy
	 * `homeworkDueAt`-фолбэк здесь НЕ показываем, редактируется только per-work).
	 * Params: group_lesson_id.
	 */
	public function ajaxGetWorkDeadlines(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );

		$row = $this->requireProgramRow( $groupLessonId );

		$works = array();
		foreach ( $this->worksResolver->resolve( $row ) as $work ) {
			$works[] = array(
				'id'       => $work->id,
				'title'    => $work->title,
				'deadline' => $row->workDeadlines[ $work->id ] ?? null,
			);
		}

		$this->success( array( 'works' => $works ) );
	}

	/**
	 * Сохраняет per-work дедлайны занятия (T12.3, D13). Delivery, не структура —
	 * НЕ блокируется публикацией КТП (см. T1.8 `denyIfProgramLocked`).
	 * Params: group_lesson_id, deadlines (JSON {work_id:'Y-m-d H:i:s'|''}) — пустая
	 * строка снимает per-work override (эффективный дедлайн падает на legacy-фолбэк).
	 */
	public function ajaxSaveWorkDeadlines(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$rawDeadlines  = $this->sanitizeText( 'deadlines' );

		$this->requireProgramRow( $groupLessonId );

		$decoded = json_decode( $rawDeadlines, true );
		if ( ! is_array( $decoded ) ) {
			$this->error( 'Неверный формат данных.' );
			return;
		}

		$sanitized = array();
		foreach ( $decoded as $workId => $deadline ) {
			if ( is_string( $deadline ) && '' !== $deadline ) {
				$sanitized[ (int) $workId ] = $deadline;
			}
		}

		$this->groupLessons->setWorkDeadlines( $groupLessonId, $sanitized );
		$this->success( array( 'saved' => true ) );
	}

	/**
	 * Ручная правка ссылки на запись занятия (попап камеры в КТП, З3). Нужна, когда
	 * авто-матч VideoLibrary не привязал запись: методист/офис (а для своей группы —
	 * преподаватель) вставляет ссылку руками. Ядро о модуле не знает — просто хранит
	 * и отдаёт строку-указатель (`https://…` или `s3://bucket/key`).
	 * Params: group_lesson_id, recording_url (пусто — снять ссылку)
	 */
	public function ajaxSetRecordingUrl(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$url           = $this->sanitizeText( 'recording_url' );

		$this->requireProgramRow( $groupLessonId );

		$this->groupLessons->setRecordingUrl( $groupLessonId, '' !== $url ? $url : null );
		if ( '' !== $url ) {
			do_action( 'fs_lms_recording_attached', $groupLessonId );
		}

		$this->success( array( 'saved' => true, 'recording_url' => '' !== $url ? $url : null ) );
	}
}
