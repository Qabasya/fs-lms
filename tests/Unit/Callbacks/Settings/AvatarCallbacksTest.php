<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Settings;

use Inc\Callbacks\Settings\AvatarCallbacks;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Settings\AvatarEntity;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Shared\AvatarService;
use PHPUnit\Framework\TestCase;

class AvatarCallbacksTest extends TestCase {

	private $avatars;
	private $subjects;
	private $groups;
	private AvatarCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->avatars  = $this->createMock( AvatarService::class );
		$this->subjects = $this->createMock( SubjectRepository::class );
		$this->groups   = $this->createMock( GroupsRepository::class );
		$this->cb       = new AvatarCallbacks( $this->avatars, $this->subjects, $this->groups );

		$this->subjects->method( 'readAll' )->willReturn( array( 'inf' => new SubjectDTO( 'inf', 'Информатика' ) ) );
	}

	public function test_unknown_entity_is_rejected(): void {
		$this->avatars->expects( $this->never() )->method( 'set' );
		$_POST = array( 'entity' => 'course', 'key' => '1', 'attachment_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAvatar() )->success );
	}

	public function test_unknown_subject_is_rejected(): void {
		$this->avatars->expects( $this->never() )->method( 'set' );
		$_POST = array( 'entity' => 'subject', 'key' => 'nope', 'attachment_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAvatar() )->success );
	}

	public function test_unknown_group_is_rejected(): void {
		$this->groups->method( 'findById' )->with( 99 )->willReturn( null );
		$this->avatars->expects( $this->never() )->method( 'set' );
		$_POST = array( 'entity' => 'group', 'key' => '99', 'attachment_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAvatar() )->success );
	}

	public function test_saves_subject_avatar(): void {
		$this->avatars->expects( $this->once() )->method( 'set' )->with( AvatarEntity::Subject, 'inf', 5 )->willReturn( true );
		$this->avatars->method( 'attachmentUrl' )->with( 5 )->willReturn( 'https://x/a.png' );
		$_POST = array( 'entity' => 'subject', 'key' => 'inf', 'attachment_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveAvatar() );

		self::assertTrue( $r->success );
		self::assertSame( 'https://x/a.png', $r->payload['url'] );
	}

	public function test_saves_group_avatar(): void {
		$this->groups->method( 'findById' )->with( 7 )->willReturn( (object) array( 'id' => 7 ) );
		$this->avatars->expects( $this->once() )->method( 'set' )->with( AvatarEntity::Group, '7', 0 )->willReturn( true );
		$_POST = array( 'entity' => 'group', 'key' => '7', 'attachment_id' => '0' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveAvatar() )->success );
	}

	public function test_brand_needs_no_key(): void {
		$this->avatars->expects( $this->once() )->method( 'set' )->with( AvatarEntity::Brand, '', 8 )->willReturn( true );
		$_POST = array( 'entity' => 'brand', 'attachment_id' => '8' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveAvatar() )->success );
	}

	public function test_non_image_attachment_is_rejected(): void {
		$this->avatars->method( 'set' )->willReturn( false );
		$_POST = array( 'entity' => 'subject', 'key' => 'inf', 'attachment_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAvatar() )->success );
	}
}
