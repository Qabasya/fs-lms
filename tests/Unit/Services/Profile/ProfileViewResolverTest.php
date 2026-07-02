<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Enums\Access\UserRole;
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
			new TeacherProfileView(),
			new LearnerProfileView(),
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
}
