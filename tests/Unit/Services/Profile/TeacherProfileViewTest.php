<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Profile\TeacherProfileView;
use PHPUnit\Framework\TestCase;

class TeacherProfileViewTest extends TestCase {

	private TeacherProfileView $view;
	private ExamAccessGuard $guard;
	private SubjectRepository $subjects;

	protected function setUp(): void {
		parent::setUp();
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findAll' )->willReturn( array() );
		$groups->method( 'findByTeacherId' )->willReturn( array() );

		$this->guard    = $this->createStub( ExamAccessGuard::class );
		$this->subjects = $this->createStub( SubjectRepository::class );
		$this->subjects->method( 'readActive' )->willReturn( array( new SubjectDTO( 'inf_ege', 'Информатика ЕГЭ' ), new SubjectDTO( 'python', 'Python' ) ) );
		$this->subjects->method( 'getByKey' )->willReturnCallback( static fn ( string $key ): ?SubjectDTO => new SubjectDTO( $key, strtoupper( $key ) ) );
		$this->guard->method( 'manageableSubjectKeys' )->willReturn( array( 'inf_ege' ) );

		$this->view = new TeacherProfileView( $groups, $this->createMock( CourseManager::class ), $this->subjects, $this->guard );
		$GLOBALS['_test_user_can'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_user_can'] );
		parent::tearDown();
	}

	private function grant( int $userId, Capability ...$caps ): void {
		foreach ( $caps as $cap ) {
			$GLOBALS['_test_user_can'][ $userId ][ $cap->value ] = true;
		}
	}

	/** T12.7: пункт «Группы» убран из меню, но экран остаётся в screens (маршрут жив). */
	public function test_teacher_nav_excludes_groups_but_screens_keeps_it(): void {
		$ctx  = new ProfileContext( 1, null, UserRole::FSTeacher, null, false );
		$built = $this->view->build( $ctx );

		$navKeys = array_column( $built['nav'], 'key' );
		self::assertNotContains( 'groups', $navKeys );
		self::assertContains( 'groups', $built['screens'] );
	}

	public function test_office_gets_substitutions_screen_groups_still_hidden_from_nav(): void {
		$ctx  = new ProfileContext( 1, null, UserRole::FSOffice, null, false );
		$built = $this->view->build( $ctx );

		$navKeys = array_column( $built['nav'], 'key' );
		self::assertNotContains( 'groups', $navKeys );
		self::assertContains( 'substitutions', $navKeys );
		self::assertContains( 'substitutions', $built['screens'] );
	}

	public function test_teacher_does_not_get_substitutions(): void {
		$ctx  = new ProfileContext( 1, null, UserRole::FSTeacher, null, false );
		$built = $this->view->build( $ctx );

		self::assertNotContains( 'substitutions', array_column( $built['nav'], 'key' ) );
		self::assertNotContains( 'substitutions', $built['screens'] );
	}
	public function test_exam_nav_present_for_user_with_manage_exams(): void {
		$this->grant( 1, Capability::ManageExams );

		$built = $this->view->build( new ProfileContext( 1, null, UserRole::FSTeacher, null, false ) );

		self::assertSame(
			array( 'exam-conduct', 'exam-stats', 'exam-plan', 'exam-results' ),
			array_column( $built['examNav'], 'key' )
		);
		self::assertSame( array( 'Проведение экзамена', 'Статистика', 'Назначить экзамен', 'Результаты' ), array_column( $built['examNav'], 'label' ) );
		foreach ( array( 'exam-conduct', 'exam-stats', 'exam-plan', 'exam-results' ) as $screen ) {
			self::assertContains( $screen, $built['screens'] );
		}
		self::assertNotContains( 'exam-payments', $built['screens'] );
	}

	public function test_exam_nav_absent_without_capability(): void {
		$built = $this->view->build( new ProfileContext( 1, null, UserRole::FSTeacher, null, false ) );

		self::assertSame( array(), $built['examNav'] );
		self::assertArrayNotHasKey( 'exams', $built, 'Блока конфига экзаменов без права нет.' );
		self::assertNotContains( 'exam-plan', $built['screens'] );
	}

	public function test_office_sees_only_payments_item(): void {
		$this->grant( 1, Capability::ResolveExamPayments );

		$built = $this->view->build( new ProfileContext( 1, null, UserRole::FSOffice, null, false ) );

		self::assertSame( array( 'exam-payments' ), array_column( $built['examNav'], 'key' ) );
		self::assertContains( 'exam-payments', $built['screens'] );
		self::assertNotContains( 'exam-plan', $built['screens'] );
		self::assertArrayHasKey( 'exams', $built );
	}

	public function test_admin_without_lms_role_sees_exam_nav(): void {
		// Администратор WP без LMS-роли получает витрину офиса: меню — по праву, а не по роли.
		$this->grant( 1, Capability::ManageExams, Capability::ResolveExamPayments );

		$built = $this->view->build( new ProfileContext( 1, null, UserRole::FSOffice, null, false ) );

		self::assertSame( 5, count( $built['examNav'] ) );
		self::assertSame( 'exam-payments', $built['examNav'][4]['key'] );
	}

	public function test_exam_screens_not_in_main_nav(): void {
		$this->grant( 1, Capability::ManageExams, Capability::ResolveExamPayments );

		$navKeys = array_column( $this->view->build( new ProfileContext( 1, null, UserRole::FSTeacher, null, false ) )['nav'], 'key' );

		foreach ( array( 'exam-conduct', 'exam-stats', 'exam-plan', 'exam-results', 'exam-payments' ) as $screen ) {
			self::assertNotContains( $screen, $navKeys );
		}
	}

	public function test_exams_config_carries_nonce_actions_and_manageable_subjects_only(): void {
		$this->grant( 1, Capability::ManageExams );

		$exams = $this->view->build( new ProfileContext( 1, null, UserRole::FSTeacher, null, false ) )['exams'];

		self::assertNotSame( '', $exams['nonce'] );
		self::assertSame( 'get_exam_plan', $exams['actions']['getPlan'] );
		self::assertSame( 'save_exam_session', $exams['actions']['saveSession'] );
		self::assertSame( array( array( 'key' => 'inf_ege', 'name' => 'INF_EGE' ) ), $exams['subjects' ], 'Только предметы, которыми он вправе управлять (по guard).' );
		self::assertFalse( $exams['guestSignupReady'], 'Гостевая запись появится на этапе 11a.' );
	}
}
