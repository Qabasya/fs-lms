<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Managers\Course\LessonManager;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/**
 * Class RecordingAlertService
 *
 * Алёрт «запись занятия не привязалась» (З3): занятие проведено (`status = held`),
 * а `recording_url` пуст — авто-матч V6 не нашёл кандидата или запись не приехала.
 * Собирает список для админской секции модуля: занятие + группа + тема урока +
 * непривязанные записи ТОЙ ЖЕ группы (кандидаты привязки в один клик).
 *
 * Живёт в модуле: ядро о записях занятий не знает, само поле `recording_url` для
 * него — просто строка-указатель.
 *
 * @package Inc\Modules\VideoLibrary\Services
 */
class RecordingAlertService {

	public function __construct(
		private readonly GroupLessonRepository    $groupLessons,
		private readonly GroupsRepository         $groups,
		private readonly LessonManager            $lessons,
		private readonly VideoRecordingRepository $recordings,
	) {}

	/** Ключ и TTL кэша счётчика для admin-нотиса (COUNT идёт по всем занятиям). */
	private const COUNT_TRANSIENT = 'fs_lms_video_pending_count';
	private const COUNT_TTL       = 5 * MINUTE_IN_SECONDS;

	/** Сколько проведённых занятий осталось без записи (бейдж в секции настроек). */
	public function countPending(): int {
		$count = $this->groupLessons->countHeldWithoutRecording();
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
	 *     candidates:array<int, array{id:int, s3_key:string, recorded_at:string}>
	 * }>
	 */
	public function pending( int $limit = 30 ): array {
		$groupNames = array();
		$candidates = array();
		$out        = array();

		foreach ( $this->groupLessons->listHeldWithoutRecording( $limit ) as $row ) {
			$groupId = (int) $row->groupId;

			if ( ! isset( $groupNames[ $groupId ] ) ) {
				$groupNames[ $groupId ] = (string) ( $this->groups->findById( $groupId )->name ?? '' );
				$candidates[ $groupId ] = array_map(
					static fn( VideoRecordingDTO $r ): array => array(
						'id'          => $r->id,
						's3_key'      => $r->s3Key,
						'recorded_at' => (string) $r->recordedAt,
					),
					$this->recordings->listByGroup( $groupId )
				);
			}

			$out[] = array(
				'id'           => (int) $row->id,
				'group_id'     => $groupId,
				'group_name'   => $groupNames[ $groupId ],
				'topic'        => $this->topicOf( $row->lessonId, $row->label ),
				'scheduled_at' => (string) ( $row->scheduledAt ?? '' ),
				'candidates'   => $candidates[ $groupId ],
			);
		}

		return $out;
	}

	/** Подпись занятия: тема урока, иначе собственная метка занятия. */
	private function topicOf( ?int $lessonId, ?string $label ): string {
		$topic = $lessonId ? ( $this->lessons->get( $lessonId )?->topic ?? '' ) : '';

		return '' !== $topic ? $topic : (string) ( $label ?? '' );
	}
}
