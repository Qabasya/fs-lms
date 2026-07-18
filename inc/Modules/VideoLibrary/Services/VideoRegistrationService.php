<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Enums\Course\LessonStatus;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingInputDTO;
use Inc\Modules\VideoLibrary\Enums\VideoRecordingStatus;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Shared\PluginLogger;

/**
 * Class VideoRegistrationService
 *
 * Регистрация записи занятия (V6): upsert реестра по `s3_key` → резолв занятия →
 * привязка (`group_lesson_id` + `recording_url` занятия + `status='held'`).
 *
 * Идемпотентность: повторная отправка обновляет только метаданные; существующую
 * привязку (в т.ч. ручную) НЕ перерезолвливает — пере-резолв только для строк `unmatched`.
 * «Занятие не найдено» — не ошибка (matched:false), запись ждёт ручной привязки (V9).
 *
 * @package Inc\Modules\VideoLibrary\Services
 */
class VideoRegistrationService {

	private const LOG_CONTEXT = 'VideoLibrary';

	public function __construct(
		private readonly VideoRecordingRepository $recordings,
		private readonly VideoLessonResolver      $resolver,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly GroupsRepository         $groups,
	) {}

	/**
	 * Полный цикл регистрации (REST `POST /videos`).
	 *
	 * @throws \InvalidArgumentException Непарсибельный `recorded_at` (контроллер отвечает 400).
	 *
	 * @return array{recording_id:int, matched:bool, group_lesson_id:int|null}
	 */
	public function register( VideoRecordingInputDTO $input ): array {
		$recordedAt    = $this->normalizeRecordedAt( $input->recordedAt );
		$teacherUserId = $this->resolveTeacherUserId( $input );
		$this->crossCheckGroup( $input );

		$upsert = $this->recordings->upsertByS3Key(
			$input,
			$recordedAt->format( 'Y-m-d H:i:s' ),
			$teacherUserId
		);

		$recordingId = $upsert['id'];
		$existing    = $upsert['existing'];

		// Существующая привязка (в т.ч. ручная) неприкосновенна — обновлены только метаданные.
		if ( null !== $existing
			&& VideoRecordingStatus::Matched->value === $existing->status
			&& null !== $existing->groupLessonId
		) {
			do_action( 'fs_lms_video_registered', $recordingId, $existing->groupLessonId );
			return array(
				'recording_id'    => $recordingId,
				'matched'         => true,
				'group_lesson_id' => $existing->groupLessonId,
			);
		}

		$resolved      = $this->resolver->resolve( $recordedAt, $input->groupId, $teacherUserId );
		$groupLessonId = $resolved['group_lesson_id'];

		if ( null !== $groupLessonId ) {
			$this->bindToLesson( $recordingId, $groupLessonId, $input->s3Bucket, $input->s3Key );
		} else {
			PluginLogger::warning( self::LOG_CONTEXT, 'Запись не привязана к занятию — оставлена на ручную привязку', array(
				's3_key' => $input->s3Key,
				'reason' => $resolved['reason'],
			) );
		}

		do_action( 'fs_lms_video_registered', $recordingId, $groupLessonId );

		return array(
			'recording_id'    => $recordingId,
			'matched'         => null !== $groupLessonId,
			'group_lesson_id' => $groupLessonId,
		);
	}

	/**
	 * Ручная привязка записи к занятию (V9) — тот же путь, что и авто-матч:
	 * `attach()` + `recording_url` + `held`.
	 */
	public function attachManually( int $recordingId, int $groupLessonId ): bool {
		$recording = $this->recordings->find( $recordingId );
		if ( null === $recording || null === $this->groupLessons->find( $groupLessonId ) ) {
			return false;
		}

		$this->bindToLesson( $recordingId, $groupLessonId, $recording->s3Bucket, $recording->s3Key );
		return true;
	}

	/**
	 * Ручная отвязка (V9): запись → unmatched; указатель снимается с занятия,
	 * только если указывает на эту запись. Статус занятия не откатывается.
	 */
	public function detachManually( int $recordingId ): bool {
		$recording = $this->recordings->find( $recordingId );
		if ( null === $recording ) {
			return false;
		}

		if ( null !== $recording->groupLessonId ) {
			$lesson  = $this->groupLessons->find( $recording->groupLessonId );
			$pointer = $this->s3Pointer( $recording->s3Bucket, $recording->s3Key );
			if ( null !== $lesson && $lesson->recordingUrl === $pointer ) {
				$this->groupLessons->setRecordingUrl( $recording->groupLessonId, null );
			}
		}

		return $this->recordings->detach( $recordingId );
	}

	/** Привязка: реестр + указатель на занятии + held (только поверх scheduled). */
	private function bindToLesson( int $recordingId, int $groupLessonId, string $bucket, string $key ): void {
		$this->recordings->attach( $recordingId, $groupLessonId );
		$this->groupLessons->setRecordingUrl( $groupLessonId, $this->s3Pointer( $bucket, $key ) );

		// «Запись есть → занятие состоялось»: held фиксирует дату от reflow.
		// cancelled/moved не перетираем — статус выставлен осознанно.
		$lesson = $this->groupLessons->find( $groupLessonId );
		if ( null !== $lesson && LessonStatus::Scheduled->value === $lesson->status ) {
			$this->groupLessons->setStatus( $groupLessonId, LessonStatus::Held );
		}
	}

	private function s3Pointer( string $bucket, string $key ): string {
		return "s3://{$bucket}/{$key}";
	}

	/**
	 * ISO-8601 с offset → wall-clock таймзоны сайта (scheduled_at хранится локальным).
	 *
	 * @throws \InvalidArgumentException
	 */
	private function normalizeRecordedAt( string $recordedAt ): \DateTimeImmutable {
		try {
			$parsed = new \DateTimeImmutable( $recordedAt );
		} catch ( \Exception $e ) {
			throw new \InvalidArgumentException( "bad recorded_at: {$recordedAt}", 0, $e );
		}

		return $parsed->setTimezone( wp_timezone() );
	}

	/**
	 * `lms.teacher_id` — готовый WP user ID, приоритетный путь (groups.yaml с V1.5.15
	 * экспортирует именно его). `teacher_username` — переходный fallback для конфигов,
	 * ещё не смигрировавших на teacher_id; не найден → unmatched-путь + WARNING.
	 */
	private function resolveTeacherUserId( VideoRecordingInputDTO $input ): ?int {
		if ( null !== $input->teacherId && $input->teacherId > 0 ) {
			return $input->teacherId;
		}

		if ( null === $input->teacherUsername || '' === $input->teacherUsername ) {
			return null;
		}

		$user = get_user_by( 'login', $input->teacherUsername );
		if ( false === $user ) {
			PluginLogger::warning( self::LOG_CONTEXT, 'teacher_username не найден среди WP-пользователей', array(
				'teacher_username' => $input->teacherUsername,
				's3_key'           => $input->s3Key,
			) );
			return null;
		}

		return (int) $user->ID;
	}

	/** Кросс-чек группового lms-блока против fs_lms_groups: расхождение — WARNING, не отказ. */
	private function crossCheckGroup( VideoRecordingInputDTO $input ): void {
		if ( null === $input->groupId || $input->groupId <= 0 ) {
			return;
		}

		$group = $this->groups->findById( $input->groupId );
		if ( null === $group ) {
			PluginLogger::warning( self::LOG_CONTEXT, 'lms.group_id не найден в fs_lms_groups', array(
				'group_id' => $input->groupId,
				's3_key'   => $input->s3Key,
			) );
			return;
		}

		$mismatch = array();
		if ( null !== $input->courseId && (int) ( $group->course_id ?? 0 ) !== $input->courseId ) {
			$mismatch['course_id'] = array( 'lms' => $input->courseId, 'group' => (int) ( $group->course_id ?? 0 ) );
		}
		if ( null !== $input->teacherId && (int) ( $group->teacher_id ?? 0 ) !== $input->teacherId ) {
			$mismatch['teacher_id'] = array( 'lms' => $input->teacherId, 'group' => (int) ( $group->teacher_id ?? 0 ) );
		}

		if ( array() !== $mismatch ) {
			PluginLogger::warning( self::LOG_CONTEXT, 'lms-блок расходится с fs_lms_groups (groups.yaml устарел?)', array(
				'group_id' => $input->groupId,
				's3_key'   => $input->s3Key,
				'mismatch' => $mismatch,
			) );
		}
	}
}
