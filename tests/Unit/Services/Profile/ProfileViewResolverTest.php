<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Enums\Access\UserRole;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Profile\LearnerProfileView;
use Inc\Services\Profile\ProfileViewResolver;
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
			),
			new LearnerProfileView(),
			$this->createMock( SubjectRepository::class ),
		);
	}

	public function test_teacher_and_office_get_teacher_view(): void {
		self::assertInstanceOf( TeacherProfileView::class, $this->resolver->viewFor( UserRole::FSTeacher ) );
		self::assertInstanceOf( TeacherProfileView::class, $this->resolver->viewFor( UserRole::FSOffice ) );
	}

	public function test_learner_roles_get_learner_view(): void {
		self::assertInstanceOf( LearnerProfileView::class, $this->resolver->viewFor( UserRole::FSStudent ) );
		self::assertInstanceOf( LearnerProfileView::class, $this->resolver->viewFor( UserRole::FSParent ) );
		self::assertInstanceOf( LearnerProfileView::class, $this->resolver->viewFor( UserRole::Student ) );
	}

	public function test_back_office_roles_have_no_front_cabinet(): void {
		self::assertNull( $this->resolver->viewFor( UserRole::FSMethodist ) );
		self::assertNull( $this->resolver->viewFor( UserRole::FSMarket ) );
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
			),
			new LearnerProfileView(),
			$this->createMock( SubjectRepository::class ),
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
