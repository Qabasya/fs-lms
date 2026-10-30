<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Managers\Course\LessonManager;
use Inc\Modules\VideoLibrary\DTO\VideoRecordingDTO;
use Inc\Modules\VideoLibrary\Repositories\VideoRecordingRepository;
use Inc\Modules\VideoLibrary\Services\RecordingAlertService;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Алёрт «занятие прошло, записи нет» (З3): состав строки списка и то, что группа
 * и её кандидаты-записи читаются по одному разу, а не на каждое занятие.
 */
class RecordingAlertServiceTest extends TestCase {

	private $groupLessons;
	private $groups;
	private $lessons;
	private $recordings;
	private RecordingAlertService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->recordings   = $this->createMock( VideoRecordingRepository::class );

		$this->service = new RecordingAlertService(
			$this->groupLessons,
			$this->groups,
			$this->lessons,
			$this->recordings
		);
	}

	private function lessonRow( int $id, int $groupId, ?int $lessonId, ?string $label = null, LessonKind $kind = LessonKind::Group, ?int $teacherId = null, ?string $extraKey = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: $groupId, lessonId: $lessonId, position: 0, workIdsSnapshot: null,
			extraWorkIds: array(), scheduledAt: '2026-07-20 10:00:00', endsAt: null, isPinned: false,
			teacherUserId: $teacherId, visibility: 'open', openedAt: null, homeworkDueAt: null,
			allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null,
			label: $label, status: 'held', kind: $kind, extraKey: $extraKey,
		);
	}

	private function recording( int $id, string $key, ?int $groupId = 5 ): VideoRecordingDTO {
		return new VideoRecordingDTO(
			id: $id, s3Bucket: 'b', s3Key: $key, manifestKey: null, groupSlug: 'kege-1',
			groupId: $groupId, teacherUserId: null, groupLessonId: null, status: 'unmatched',
			recordedAt: '2026-07-20 10:05:00', sizeBytes: 1, sha256: '', durationSec: null,
			payload: null, createdAt: '', updatedAt: '',
		);
	}

	private function lesson( string $topic ): LessonDTO {
		return new LessonDTO(
			id: 1, subjectKey: 'inf', topic: $topic, steps: array(), authorId: 1, status: 'publish',
		);
	}

	public function test_pending_row_carries_group_topic_and_candidates(): void {
		$this->groupLessons->method( 'listPastWithoutRecording' )
			->willReturn( array( $this->lessonRow( 42, 5, 1 ) ) );
		$this->groups->method( 'findById' )->with( 5 )->willReturn( (object) array( 'name' => 'КЕГЭ-1' ) );
		$this->lessons->method( 'get' )->with( 1 )->willReturn( $this->lesson( 'Циклы' ) );
		$this->recordings->method( 'listAllUnmatched' )
			->willReturn( array( $this->recording( 7, 'videos/kege-1/rec.webm' ) ) );

		$rows = $this->service->pending();

		self::assertCount( 1, $rows );
		self::assertSame( 42, $rows[0]['id'] );
		self::assertSame( 'КЕГЭ-1', $rows[0]['group_name'] );
		self::assertSame( 'Циклы', $rows[0]['topic'] );
		self::assertSame( '2026-07-20 10:00:00', $rows[0]['scheduled_at'] );
		self::assertSame( 7, $rows[0]['candidates'][0]['id'] );
		self::assertSame( 'videos/kege-1/rec.webm', $rows[0]['candidates'][0]['s3_key'] );
	}

	public function test_group_is_read_once_per_group_and_all_unmatched_recordings_once(): void {
		// Три занятия: два в группе 5, одно в группе 6 — два чтения групп; общий список записей читается один раз.
		$this->groupLessons->method( 'listPastWithoutRecording' )->willReturn( array(
			$this->lessonRow( 42, 5, 1 ),
			$this->lessonRow( 43, 5, 1 ),
			$this->lessonRow( 44, 6, 1 ),
		) );
		$this->groups->expects( self::exactly( 2 ) )->method( 'findById' )
			->willReturn( (object) array( 'name' => 'Группа' ) );
		$this->recordings->expects( self::once() )->method( 'listAllUnmatched' )->willReturn( array() );
		$this->lessons->method( 'get' )->willReturn( $this->lesson( 'Тема' ) );

		self::assertCount( 3, $this->service->pending() );
	}

	/** Админ выбирает из ВСЕХ непривязанных записей хранилища — чужой группы и без группы тоже; название подбирает сам. */
	public function test_admin_candidates_include_recordings_of_other_groups_and_without_group(): void {
		$this->groupLessons->method( 'listPastWithoutRecording' )->willReturn( array( $this->lessonRow( 42, 5, 1 ) ) );
		$this->groups->method( 'findById' )->willReturnCallback(
			static fn ( int $id ): object => (object) array( 'name' => 5 === $id ? 'КЕГЭ-1' : 'Другая группа' )
		);
		$this->lessons->method( 'get' )->willReturn( $this->lesson( 'Тема' ) );
		$this->recordings->method( 'listAllUnmatched' )->willReturn( array(
			$this->recording( 7, 'videos/kege-1/a.webm', 5 ),
			$this->recording( 8, 'videos/other/b.webm', 6 ),
			$this->recording( 9, 'videos/unknown/c.webm', null ),
		) );

		$candidates = $this->service->pending()[0]['candidates'];

		self::assertSame( array( 7, 8, 9 ), array_column( $candidates, 'id' ) );
		self::assertSame( array( 'КЕГЭ-1', 'Другая группа', '' ), array_column( $candidates, 'group_name' ) );
	}

	public function test_topic_falls_back_to_lesson_label_without_content(): void {
		// Занятие без урока (lesson_id = null) — подписываем собственной меткой.
		$this->groupLessons->method( 'listPastWithoutRecording' )
			->willReturn( array( $this->lessonRow( 42, 5, null, 'Консультация' ) ) );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'name' => 'КЕГЭ-1' ) );
		$this->recordings->method( 'listAllUnmatched' )->willReturn( array() );
		$this->lessons->expects( self::never() )->method( 'get' );

		self::assertSame( 'Консультация', $this->service->pending()[0]['topic'] );
	}

	public function test_count_pending_delegates_to_repository(): void {
		$this->groupLessons->method( 'countPastWithoutRecording' )->willReturn( 4 );

		self::assertSame( 4, $this->service->countPending() );
	}
	public function test_rows_of_one_extra_lesson_are_listed_once(): void {
		// Дополнительное занятие для трёх учеников — три строки с общим extra_key, а запись у него одна.
		$this->groupLessons->method( 'listPastWithoutRecording' )->willReturn( array(
			$this->lessonRow( 55, 4, 1, null, LessonKind::Individual, null, 'k1' ),
			$this->lessonRow( 56, 4, 1, null, LessonKind::Individual, null, 'k1' ),
			$this->lessonRow( 57, 4, 1, null, LessonKind::Individual, null, 'k1' ),
			$this->lessonRow( 58, 4, 1, null, LessonKind::Individual, null, 'k2' ),
			$this->lessonRow( 59, 4, 1, null, LessonKind::Individual ),
			$this->lessonRow( 60, 4, 1, null, LessonKind::Individual ),
		) );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'name' => 'Г', 'teacher_id' => 7 ) );
		$this->recordings->method( 'listAllUnmatched' )->willReturn( array() );
		$this->lessons->method( 'get' )->willReturn( $this->lesson( 'Тема' ) );

		$ids = array_column( $this->service->pending(), 'id' );

		// Без ключа строки самостоятельны (59, 60); с общим ключом — одна карточка.
		self::assertSame( array( 55, 58, 59, 60 ), $ids );
	}
}
