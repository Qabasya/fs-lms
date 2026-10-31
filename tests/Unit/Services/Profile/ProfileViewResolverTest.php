<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\DTO\Person\PersonDTO;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Shared\AvatarService;
use Inc\Services\Profile\LearnerProfileView;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Profile\TeacherProfileView;
use PHPUnit\Framework\TestCase;

/**
 * Приёмка per-role доступа (Эпик 8, T8.3): роль → витрина.
 * Препод/офис → инструменты препода; ученик/родитель → витрина учащегося;
 * методист/маркетолог → null (нет фронт-кабинета → редирект в админку).
 */
class ProfileViewResolverTest extends TestCase {

	private ProfileViewResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ProfileViewResolver(
			$this->createMock( PersonRepository::class ),
			$this->createMock( StudentRecordRepository::class ),
			$this->createMock( GroupsRepository::class ),
			new TeacherProfileView(
				$this->createMock( GroupsRepository::class ),
				$this->createMock( CourseManager::class ),
				$this->createMock( SubjectRepository::class ),
				$this->createMock( ExamAccessGuard::class ),
				$this->createStub( \Inc\Services\Profile\RankingAccessService::class ),
				$this->createStub( \Inc\Services\Profile\Learner\LearnerRankingSection::class ),
			),
			new LearnerProfileView(),
			$this->createMock( SubjectRepository::class ),
			$this->createMock( \Inc\Services\Course\LiveLessonService::class ),
			$this->createMock( AvatarService::class ),
			$this->createStub( \Inc\Services\Profile\HomeLayoutService::class ),
			$this->createStub( \Inc\Services\Profile\RankingAccessService::class ),
			$this->createStub( \Inc\Services\Profile\Learner\LearnerRankingSection::class ),
		);
	}

	public function test_teacher_and_office_get_teacher_view(): void {
		self::assertInstanceOf( TeacherProfileView::class, $this->resolver->viewFor( UserRole::FSTeacher ) );
		self::assertInstanceOf( TeacherProfileView::class, $this->resolver->viewFor( UserRole::FSOffice ) );
	}

	public function test_learner_roles_get_learner_view(): void {
		self::assertInstanceOf( LearnerProfileView::class, $this->resolver->viewFor( UserRole::FSStudent ) );
		self::assertInstanceOf( LearnerProfileView::class, $this->resolver->viewFor( UserRole::FSParent ) );
	}

	/** Резолвер с заранее известным контекстом пользователя (роль и «только чтение»). */
	private function resolverFor( UserRole $role, ?PersonDTO $person = null ): ProfileViewResolver {
		$user             = new \WP_User();
		$user->ID         = 21;
		$user->roles      = array( $role->value );
		$user->user_login = 'u21';
		$GLOBALS['_fs_test_userdata'] = array( 21 => $user );

		$people = $this->createMock( PersonRepository::class );
		$people->method( 'findByWpUserId' )->willReturn( $person );

		return new ProfileViewResolver(
			$people,
			$this->createMock( StudentRecordRepository::class ),
			$this->createMock( GroupsRepository::class ),
			new TeacherProfileView(
				$this->createMock( GroupsRepository::class ),
				$this->createMock( CourseManager::class ),
				$this->createMock( SubjectRepository::class ),
				$this->createMock( ExamAccessGuard::class ),
				$this->createStub( \Inc\Services\Profile\RankingAccessService::class ),
				$this->createStub( \Inc\Services\Profile\Learner\LearnerRankingSection::class ),
			),
			new LearnerProfileView(),
			$this->createMock( SubjectRepository::class ),
			$this->createMock( \Inc\Services\Course\LiveLessonService::class ),
			$this->createMock( AvatarService::class ),
			$this->createStub( \Inc\Services\Profile\HomeLayoutService::class ),
			$this->createStub( \Inc\Services\Profile\RankingAccessService::class ),
			$this->createStub( \Inc\Services\Profile\Learner\LearnerRankingSection::class ),
		);
	}

	public function test_parent_config_has_no_mutating_exam_actions(): void {
		$config = $this->resolverFor( UserRole::FSParent )->jsConfig( 21 );
		unset( $GLOBALS['_fs_test_userdata'] );

		self::assertArrayHasKey( 'getExams', $config['exams']['actions'] );
		self::assertArrayHasKey( 'getReview', $config['exams']['actions'] );
		foreach ( array( 'register', 'change', 'cancel' ) as $action ) {
			self::assertArrayNotHasKey( $action, $config['exams']['actions'], "У родителя нет действия {$action}." );
		}
		self::assertNotSame( '', $config['exams']['nonce'] );
	}

	public function test_student_config_has_register_change_cancel(): void {
		$config = $this->resolverFor( UserRole::FSStudent )->jsConfig( 21 );
		unset( $GLOBALS['_fs_test_userdata'] );

		foreach ( array( 'getExams', 'getReview', 'register', 'change', 'cancel' ) as $action ) {
			self::assertArrayHasKey( $action, $config['exams']['actions'], "У ученика есть действие {$action}." );
		}
		self::assertSame( 'register_for_exam', $config['exams']['actions']['register'] );
	}

	public function test_teacher_config_has_no_learner_exam_block(): void {
		$config = $this->resolverFor( UserRole::FSTeacher )->jsConfig( 21 );
		unset( $GLOBALS['_fs_test_userdata'] );

		self::assertArrayNotHasKey( 'learner', $config );
		self::assertArrayNotHasKey( 'exams', $config, 'Блок «exams» ученика преподавателю не выдаётся (у него свой — в витрине).' );
	}

	public function test_student_config_has_ranking_block_but_parent_does_not(): void {
		$person = new PersonDTO( 15, 21, 'Иванов', 'Иван', null, null, true, null, null, null, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );

		$student = $this->resolverFor( UserRole::FSStudent, $person )->jsConfig( 21 );
		$parent  = $this->resolverFor( UserRole::FSParent, $person )->jsConfig( 21 );
		unset( $GLOBALS['_fs_test_userdata'] );

		self::assertSame( 'get_subject_ranking', $student['ranking']['actions']['getRanking'] ?? null );
		self::assertArrayHasKey( 'subjects', $student['ranking'] );
		self::assertArrayHasKey( 'periods', $student['ranking'] );
		self::assertArrayNotHasKey( 'ranking', $parent, 'Родителю блок рейтинга не выдаётся.' );
	}

	public function test_back_office_roles_have_no_front_cabinet(): void {
		self::assertNull( $this->resolver->viewFor( UserRole::FSMethodist ) );
	}

	// Флаг дня рождения сравнивается с current_time() — в WP-окружении он настоящий, даты считаем от «сегодня».

	public function test_user_block_carries_greeting_name_and_birthday_flag(): void {
		$today  = current_time( 'Y-m-d' );
		$person = new PersonDTO( 15, 21, 'Иванов', 'Иван', null, $today, true, null, null, null, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );

		$config = $this->resolverFor( UserRole::FSStudent, $person )->jsConfig( 21 );
		unset( $GLOBALS['_fs_test_userdata'] );

		self::assertSame( 'Иван', $config['user']['firstName'] );
		self::assertSame( '', $config['user']['middleName'] );
		self::assertTrue( $config['user']['birthdayToday'], 'у ученика с днём рождения сегодня — поздравление' );
	}

	public function test_user_block_birthday_flag_false_on_other_day_and_parent_gets_middle_name(): void {
		$today = current_time( 'Y-m-d' );
		$other = '06-15' === substr( $today, 5 ) ? '1985-06-16' : '1985-06-15';
		$person = new PersonDTO( 16, 21, 'Петрова', 'Мария', 'Сергеевна', $other, false, null, null, null, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );

		$config = $this->resolverFor( UserRole::FSParent, $person )->jsConfig( 21 );
		unset( $GLOBALS['_fs_test_userdata'] );

		self::assertSame( 'Мария', $config['user']['firstName'] );
		self::assertSame( 'Сергеевна', $config['user']['middleName'] );
		self::assertFalse( $config['user']['birthdayToday'], "{$other} — не сегодня" );
	}

	public function test_user_block_without_person_has_empty_greeting_fields(): void {
		$config = $this->resolverFor( UserRole::FSStudent )->jsConfig( 21 );
		unset( $GLOBALS['_fs_test_userdata'] );

		self::assertSame( '', $config['user']['firstName'] );
		self::assertSame( '', $config['user']['middleName'] );
		self::assertFalse( $config['user']['birthdayToday'], 'без привязанного Person поздравления нет' );
	}

	/**
	 * Витрина отдаёт не только nav/screens, но и блоки конфига своих экранов
	 * (`groups`, `dashboard`, `journal`, …). Резолвер обязан перенести их в
	 * `window.fsProfile` целиком: без них SPA рисует «Главная недоступна» и
	 * «Нет групп» — экраны остаются без nonce и адресов AJAX.
	 */
	public function test_js_config_keeps_view_screen_blocks(): void {
		$user             = new \WP_User();
		$user->ID         = 5;
		$user->roles      = array( UserRole::FSTeacher->value );
		$user->user_login = 'teacher';

		$GLOBALS['_fs_test_userdata'] = array( 5 => $user );

		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findByTeacherId' )->willReturn(
			array( (object) array( 'id' => 7, 'name' => 'МЕГЕ-1', 'subject_key' => 'inf', 'access_mode' => '' ) )
		);

		$resolver = new ProfileViewResolver(
			$this->createMock( PersonRepository::class ),
			$this->createMock( StudentRecordRepository::class ),
			$this->createMock( GroupsRepository::class ),
			new TeacherProfileView(
				$groups,
				$this->createMock( CourseManager::class ),
				$this->createMock( SubjectRepository::class ),
				$this->createMock( ExamAccessGuard::class ),
				$this->createStub( \Inc\Services\Profile\RankingAccessService::class ),
				$this->createStub( \Inc\Services\Profile\Learner\LearnerRankingSection::class ),
			),
			new LearnerProfileView(),
			$this->createMock( SubjectRepository::class ),
			$this->createMock( \Inc\Services\Course\LiveLessonService::class ),
			$this->createMock( AvatarService::class ),
			$this->createStub( \Inc\Services\Profile\HomeLayoutService::class ),
			$this->createStub( \Inc\Services\Profile\RankingAccessService::class ),
			$this->createStub( \Inc\Services\Profile\Learner\LearnerRankingSection::class ),
		);

		$config = $resolver->jsConfig( 5 );

		unset( $GLOBALS['_fs_test_userdata'] );

		self::assertSame( 'МЕГЕ-1', $config['groups'][0]['name'] ?? null );
		self::assertArrayHasKey( 'dashboard', $config );
		self::assertArrayHasKey( 'journal', $config );
		self::assertContains( 'dashboard', $config['screens'] );
		self::assertArrayHasKey( 'notifications', $config );
	}
}
