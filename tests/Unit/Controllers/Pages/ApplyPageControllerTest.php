<?php

declare( strict_types=1 );

namespace Unit\Controllers\Pages;

use Inc\Controllers\Pages\ApplyPageController;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Exam\ApplyPrefillLinkService;
use Inc\Services\Security\FormGuardService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Форма заявки: предзаполнение приходит от ссылки гостя экзамена и от модулей через шов `fs_lms_apply_prefill`. */
class ApplyPageControllerTest extends TestCase {

	private SubjectRepository&MockObject $subjects;
	private ApplyPrefillLinkService&MockObject $links;
	private FormGuardService&MockObject $guard;
	private ApplyPageController $controller;

	protected function setUp(): void {
		parent::setUp();
		$_GET = array();
		unset( $GLOBALS['_fs_test_filter_returns'], $GLOBALS['_fs_test_filter_calls'] );
		$GLOBALS['_fs_test_record_filters'] = true;

		$this->subjects = $this->createMock( SubjectRepository::class );
		$this->subjects->method( 'readActive' )->willReturn( array( (object) array( 'key' => 'inf', 'name' => 'Информатика' ), (object) array( 'key' => 'mat', 'name' => 'Математика' ) ) );
		$this->links = $this->createMock( ApplyPrefillLinkService::class );
		$this->guard = $this->createMock( FormGuardService::class );
		$this->guard->method( 'honeypotField' )->willReturn( '<input type="text" name="fs_trap" value="">' );

		$this->controller = new ApplyPageController( $this->subjects, $this->links, $this->guard );
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['_fs_test_filter_returns'], $GLOBALS['_fs_test_filter_calls'], $GLOBALS['_fs_test_record_filters'] );
		parent::tearDown();
	}

	/** @return list<array{hook: string, value: mixed, args: array}> */
	private function prefillCalls(): array {
		return array_values( array_filter( $GLOBALS['_fs_test_filter_calls'] ?? array(), static fn( array $c ): bool => 'fs_lms_apply_prefill' === $c['hook'] ) );
	}

	public function test_without_subscribers_the_form_is_empty_and_the_seam_gets_an_empty_prefill(): void {
		$html = $this->controller->renderApplyForm();

		self::assertSame( array( array() ), array_column( $this->prefillCalls(), 'value' ) );
		self::assertDoesNotMatchRegularExpression( '/<option value="(?:inf|mat)"[^>]*selected/', $html, 'предмет не выбран' );
		self::assertStringNotContainsString( 'autofocus', $html );
	}

	public function test_a_subscriber_prefills_subject_and_school(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_apply_prefill'] = array( 'subject_key' => 'inf', 'school' => 'Школа № 5' );

		$html = $this->controller->renderApplyForm();

		self::assertMatchesRegularExpression( '/<option value="inf"[^>]*selected/', $html );
		self::assertDoesNotMatchRegularExpression( '/<option value="mat"[^>]*selected/', $html );
		self::assertStringContainsString( 'value="Школа № 5"', $html );
		self::assertStringNotContainsString( 'autofocus', $html, 'имя не заполнено — курсор остаётся на первом поле, а не прыгает на почту' );
	}

	public function test_the_cursor_jumps_to_the_email_only_when_the_name_is_already_filled(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_apply_prefill'] = array( 'subject_key' => 'inf', 'last_name' => 'Гостев', 'first_name' => 'Иван' );

		self::assertStringContainsString( 'autofocus', $this->controller->renderApplyForm() );
	}

	public function test_a_non_array_answer_is_treated_as_an_empty_prefill(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_apply_prefill'] = 'junk';

		$html = $this->controller->renderApplyForm();

		self::assertDoesNotMatchRegularExpression( '/<option value="(?:inf|mat)"[^>]*selected/', $html );
	}

	public function test_guest_link_prefill_is_passed_to_the_seam_and_keeps_the_guest_token(): void {
		$_GET[ ApplyPrefillLinkService::QUERY_ARG ] = 'tok123';
		$this->links->method( 'resolve' )->with( 'tok123' )->willReturn( array( 'subject_key' => 'mat', 'last_name' => 'Гостев' ) );

		$html = $this->controller->renderApplyForm();

		self::assertSame( array( array( 'subject_key' => 'mat', 'last_name' => 'Гостев' ) ), array_column( $this->prefillCalls(), 'value' ), 'шов получает разобранную ссылку гостя' );
		self::assertMatchesRegularExpression( '/id="fs_guest_token" value="tok123"/', $html, 'токен гостя возвращается формой' );
	}

	public function test_a_module_prefill_alone_does_not_invent_a_guest_token(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_apply_prefill'] = array( 'subject_key' => 'inf' );

		$html = $this->controller->renderApplyForm();

		self::assertStringNotContainsString( 'fs_guest_token', $html, 'токена гостя нет: ссылки не было' );
	}
}
