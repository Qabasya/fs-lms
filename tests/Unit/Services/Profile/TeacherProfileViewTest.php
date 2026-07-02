<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Services\Profile\TeacherProfileView;
use PHPUnit\Framework\TestCase;

class TeacherProfileViewTest extends TestCase {

	private TeacherProfileView $view;

	protected function setUp(): void {
		parent::setUp();
		$this->view = new TeacherProfileView();
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
}
