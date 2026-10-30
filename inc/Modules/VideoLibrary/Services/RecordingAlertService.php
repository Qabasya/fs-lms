<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Enums\Course\LessonKind;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/**
 * Class RecordingAlertService
 *
 * Алёрт «запись занятия не привязалась» (З3): занятие проведено или уже закончилось по расписанию,
 * а `recording_url` пуст — авто-матч V6 не нашёл кандидата или запись не приехала.
 * Собирает список для админской секции модуля: занятие + группа + тема урока +
 * непривязанные записи группы и личной папки преподавателя (индивидуальные занятия) (кандидаты привязки в один клик).
 *
 * Живёт в модуле: ядро о записях занятий не знает, само поле `recording_url` для
 * него — просто строка-указатель.
 *
 * @package Inc\Modules\VideoLibrary\Services
 */
class RecordingAlertService {

	public function __construct(
		private readonly GroupLessonRepository $groupLessons,
		private readonly GroupsRepository $groups,
		private readonly LessonManager $lessons,
		private readonly VideoRecordingRepository $recordings,
	) {}

	/** Ключ и TTL кэша счётчика для admin-нотиса (COUNT идёт по всем занятиям). */
	private const COUNT_TRANSIENT = 'fs_lms_video_pending_count';
	private const COUNT_TTL       = 5 * MINUTE_IN_SECONDS;

	/** Сколько проведённых занятий осталось без записи (бейдж в секции настроек). */
	public function countPending(): int {
		$count = $this->groupLessons->countPastWithoutRecording( current_time( 'mysql' ) );
		set_transient( self::COUNT_TRANSIENT, $count, self::COUNT_TTL );

		return $count;
	}

	/**
	 * То же число для admin-нотиса, но из кэша: нотис рисуется на каждой странице
	 * плагина, а COUNT идёт по всей таблице занятий — гонять его на каждый рендер
	 * не за чем. Точное значение всегда показывает секция настроек модуля.
	 */
	public function countPendingCached(): int {
		$cached = get_transient( self::COUNT_TRANSIENT );

		return false !== $cached ? (int) $cached : $this->countPending();
	}

	/**
	 * Занятия без записи + кандидаты привязки. Кандидаты и названия групп читаются
	 * по одному разу на группу (занятий одной группы в списке обычно несколько).
	 *
	 * @return array<int, array{
	 *     id:int, group_id:int, group_name:string, topic:string, scheduled_at:string,
	 *     candidates:array<int, array{id:int, s3_key:string, recorded_at:string, group_name:string}>
	 * }>
	 */
	public function pending( int $limit = 30 ): array {
		$groupNames = array();
		$candidates = null;
		$out        = array();
		$seenExtra  = array();

		foreach ( $this->groupLessons->listPastWithoutRecording( current_time( 'mysql' ), $limit ) as $row ) {
			$groupId = (int) $row->groupId;

			// Дополнительное занятие для части группы — по строке на ученика, а запись у него одна.
			if ( null !== $row->extraKey ) {
				$extraId = $groupId . ':' . $row->extraKey;
				if ( isset( $seenExtra[ $extraId ] ) ) {
					continue;
				}
				$seenExtra[ $extraId ] = true;
			}

			if ( ! isset( $groupNames[ $groupId ] ) ) {
				$groupNames[ $groupId ] = (string) ( $this->groups->findById( $groupId )->name ?? '' );
			}
			// Администратор выбирает из всех непривязанных записей хранилища: читается один раз на весь список.
			$candidates ??= $this->allUnmatched( $groupNames );

			$out[] = array(
				'id'           => (int) $row->id,
				'group_id'     => $groupId,
				'group_name'   => $groupNames[ $groupId ],
				'topic'        => $this->topicOf( $row->lessonId, $row->label ),
				'scheduled_at' => (string) ( $row->scheduledAt ?? '' ),
				'candidates'   => $candidates,
			);
		}

		return $out;
	}

	/**
	 * Все непривязанные записи хранилища для выбора администратором; название группы — чтобы отличать записи в меню.
	 *
	 * @param array<int, string> $groupNames Уже прочитанные названия групп (дополняется)
	 *
	 * @return array<int, array{id:int, s3_key:string, recorded_at:string, group_name:string}>
	 */
	private function allUnmatched( array &$groupNames ): array {
		$out = array();
		foreach ( $this->recordings->listAllUnmatched() as $recording ) {
			$groupId = (int) $recording->groupId;
			if ( $groupId > 0 && ! isset( $groupNames[ $groupId ] ) ) {
				$groupNames[ $groupId ] = (string) ( $this->groups->findById( $groupId )->name ?? '' );
			}
			$out[] = array(
				'id'          => $recording->id,
				's3_key'      => $recording->s3Key,
				'recorded_at' => (string) $recording->recordedAt,
				'group_name'  => $groupId > 0 ? $groupNames[ $groupId ] : '',
			);
		}

		return $out;
	}

	/**
	 * Непривязанные записи, подходящие занятию: группы, а для индивидуального занятия ещё и личной папки преподавателя.
	 * Один источник правды для админского алёрта, шага «Трансляция» преподавателя и проверки при привязке.
	 *
	 * @return VideoRecordingDTO[]
	 */
	public function candidatesFor( GroupLessonDTO $lesson ): array {
		$teacherId = null;
		if ( LessonKind::Individual === $lesson->kind ) {
			$group     = $this->groups->findById( $lesson->groupId );
			$teacherId = $lesson->teacherUserId ?? (int) ( $group->teacher_id ?? 0 );
		}

		return $this->recordings->listCandidates( $lesson->groupId, $teacherId );
	}

	/** Подпись занятия: тема урока, иначе собственная метка занятия. */
	private function topicOf( ?int $lessonId, ?string $label ): string {
		$topic = $lessonId ? ( $this->lessons->get( $lessonId )?->topic ?? '' ) : '';

		return '' !== $topic ? $topic : (string) ( $label ?? '' );
	}
}
