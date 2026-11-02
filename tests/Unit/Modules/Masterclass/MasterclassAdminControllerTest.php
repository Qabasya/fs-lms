<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Access\Capability;
use Inc\Managers\Wp\MenuManager;
use Inc\Modules\Masterclass\Callbacks\MasterclassAdminCallbacks;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\Controllers\MasterclassAdminController;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\DTO\MasterclassFunnelDTO;
use Inc\Modules\Masterclass\DTO\MasterclassLogDTO;
use Inc\Modules\Masterclass\Enums\MasterclassLogAction;
use Inc\Modules\Masterclass\Enums\MasterclassStatus;
use Inc\Modules\Masterclass\Repositories\MasterclassLogRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassService;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Страница модуля: меню, скрипт только на своей странице, два вида (список мастер-классов и список аккаунтов) на настоящих шаблонах.
 */
class MasterclassAdminControllerTest extends TestCase {

	private MenuManager&MockObject $menu;
	private MasterclassService&MockObject $service;
	private MasterclassAccountService&MockObject $accounts;
	private SubjectRepository&MockObject $subjects;
	private MasterclassLogRepository&MockObject $log;
	private MasterclassAdminController $controller;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';
		$GLOBALS['_test_options']     = array();
		unset( $GLOBALS['_fs_test_scripts'], $GLOBALS['_fs_test_localized'] );
		$_GET = $_POST = array();

		$this->menu     = $this->createMock( MenuManager::class );
		$this->service  = $this->createMock( MasterclassService::class );
		$this->accounts = $this->createMock( MasterclassAccountService::class );
		$this->subjects = $this->createMock( SubjectRepository::class );
		$this->log      = $this->createMock( MasterclassLogRepository::class );

		$this->subjects->method( 'readAll' )->willReturn( array(
			new SubjectDTO( 'inf', 'Информатика' ),
			new SubjectDTO( 'old', 'Архивный', true ),
			new SubjectDTO( 'bio', 'Биология', false, false ),
		) );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-10 05:00:00' );

		$this->controller = new MasterclassAdminController(
			$this->menu,
			$this->createStub( MasterclassAdminCallbacks::class ),
			$this->service,
			$this->accounts,
			$this->subjects,
			new MasterclassConfig(),
			new ExamTime( $clock ),
			$this->log
		);
	}

	protected function tearDown(): void {
		$_GET = $_POST = array();
		$GLOBALS['_test_options'] = array();
		unset( $GLOBALS['_fs_test_timezone'], $GLOBALS['_fs_test_scripts'], $GLOBALS['_fs_test_localized'] );
		parent::tearDown();
	}

	/** @param array<string, mixed> $over */
	private function masterclass( array $over = array() ): MasterclassDTO {
		return MasterclassDTO::fromArray( array_merge(
			array(
				'id' => 7, 'school_name' => 'Школа №5', 'subject_key' => 'inf', 'lesson_id' => 11, 'group_id' => 31, 'group_lesson_id' => 41,
				'teacher_user_id' => 3, 'starts_at' => '2026-11-12 06:00:00', 'ends_at' => '2026-11-12 11:00:00',
				'freeze_at' => '2026-11-26 11:00:00', 'unused_purge_at' => '2026-11-19 11:00:00', 'purge_at' => '2026-12-26 11:00:00',
				'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
			),
			$over
		) );
	}

	private function render(): string {
		ob_start();
		try {
			$this->controller->renderPage();

			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** @param array<string, mixed> $override */
	private function overviewRow( MasterclassStatus $status, array $override = array() ): array {
		return array(
			'masterclass' => $this->masterclass( $override ),
			'funnel'      => new MasterclassFunnelDTO( 60, 48, 31, 9, 4, 2 ),
			'status'      => $status,
		);
	}

	// ─── регистрация ───────────────────────────────────────────────────────

	public function test_registers_the_submenu_under_the_main_menu_for_the_platform_capability(): void {
		$captured = null;
		$this->menu->expects( self::once() )->method( 'register' )->willReturnCallback( function ( array $pages, array $subpages ) use ( &$captured ): void {
			$captured = array( $pages, $subpages );
		} );

		$this->controller->register();

		self::assertSame( array(), $captured[0] );
		self::assertCount( 1, $captured[1] );
		self::assertSame( MasterclassAdminController::PAGE_SLUG, $captured[1][0]['menu_slug'] );
		self::assertSame( Capability::ManageLmsPlatform->value, $captured[1][0]['capability'] );
		self::assertSame( array( $this->controller, 'renderPage' ), $captured[1][0]['callback'] );
	}

	public function test_action_names_are_distinct_and_namespaced(): void {
		$actions = array(
			MasterclassAdminController::CREATE_ACTION,
			MasterclassAdminController::UPDATE_ACTION,
			MasterclassAdminController::EXTEND_ACTION,
			MasterclassAdminController::DELETE_ACTION,
			MasterclassAdminController::ADD_ACCOUNTS_ACTION,
			MasterclassAdminController::LESSONS_ACTION,
			MasterclassAdminController::UNLOCK_ACTION,
			MasterclassAdminController::FLYERS_ACTION,
		);

		self::assertSame( $actions, array_values( array_unique( $actions ) ) );
		foreach ( $actions as $action ) {
			self::assertStringStartsWith( 'fs_lms_masterclass_', $action );
		}
	}

	// ─── скрипт ────────────────────────────────────────────────────────────

	public function test_the_script_is_loaded_on_the_module_list_page_only(): void {
		$_GET = array( 'page' => 'something_else' );
		$this->controller->enqueueAssets();
		self::assertArrayNotHasKey( 'fs-lms-masterclass-admin', $GLOBALS['_fs_test_scripts'] ?? array() );

		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => '7' );
		$this->controller->enqueueAssets();
		self::assertArrayNotHasKey( 'fs-lms-masterclass-admin', $GLOBALS['_fs_test_scripts'] ?? array(), 'вид аккаунтов серверный — скрипт ему не нужен' );

		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG );
		$this->controller->enqueueAssets();
		self::assertArrayHasKey( 'fs-lms-masterclass-admin', $GLOBALS['_fs_test_scripts'] );
		self::assertStringEndsWith( 'inc/Modules/Masterclass/assets/admin.js', $GLOBALS['_fs_test_scripts']['fs-lms-masterclass-admin']['src'] );
		self::assertContains( 'jquery', $GLOBALS['_fs_test_scripts']['fs-lms-masterclass-admin']['deps'] );
	}

	public function test_the_script_gets_actions_nonce_chunk_limit_and_defaults(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG );

		$this->controller->enqueueAssets();

		[ $object, $data ] = $GLOBALS['_fs_test_localized']['fs-lms-masterclass-admin'];
		self::assertSame( 'fsLmsMasterclass', $object );
		self::assertSame( MasterclassAdminController::CREATE_ACTION, $data['actions']['create'] );
		self::assertSame( MasterclassAdminController::ADD_ACCOUNTS_ACTION, $data['actions']['addAccounts'] );
		self::assertSame( MasterclassAdminController::UNLOCK_ACTION, $data['actions']['unlock'] );
		self::assertSame( 'nonce_' . \Inc\Enums\Wp\Nonce::Config->value, $data['nonce'] );
		self::assertSame( MasterclassAccountService::CHUNK_MAX, $data['chunkMax'] );
		self::assertSame( array( 'batchSize' => 60, 'startTime' => '09:00', 'endTime' => '14:00' ), $data['defaults'] );
	}

	// ─── вид «список мастер-классов» ───────────────────────────────────────

	public function test_the_list_shows_every_masterclass_with_its_funnel_status_and_local_window(): void {
		$this->service->method( 'overview' )->willReturn( array(
			'rows'  => array(
				$this->overviewRow( MasterclassStatus::Waiting ),
				$this->overviewRow( MasterclassStatus::Running, array( 'id' => 8, 'school_name' => 'Лицей №9' ) ),
			),
			'total' => 2,
		) );
		$this->service->method( 'teacherOptions' )->willReturn( array( array( 'id' => 3, 'name' => 'Иванова А.' ) ) );

		$html = $this->render();

		self::assertStringContainsString( 'Школа №5', $html );
		self::assertStringContainsString( 'Лицей №9', $html );
		self::assertStringContainsString( '12.11.2026 09:00 – 14:00', $html, 'окно — местное время (Москва, +3)' );
		self::assertStringContainsString( 'Информатика', $html );
		self::assertStringContainsString( 'Иванова А.', $html );
		self::assertStringContainsString( 'ждёт', $html );
		self::assertStringContainsString( 'идёт', $html );
		self::assertMatchesRegularExpression( '/Создано 60 ·\s+входили 48 ·\s+дома 31 ·\s+связались 9 ·\s+заявок 4 ·\s+зачислено 2/u', $html );
		self::assertStringContainsString( 'data-id="8"', $html );
	}

	public function test_a_live_row_offers_every_action_with_working_links(): void {
		$this->service->method( 'overview' )->willReturn( array( 'rows' => array( $this->overviewRow( MasterclassStatus::OpenAtHome ) ), 'total' => 1 ) );
		$this->service->method( 'teacherOptions' )->willReturn( array() );

		$html = $this->render();

		foreach ( array( 'add', 'unlock', 'extend', 'edit', 'delete' ) as $action ) {
			self::assertStringContainsString( 'data-mk-action="' . $action . '"', $html, "действие {$action}" );
		}
		self::assertStringContainsString( 'Печать флаеров', $html );
		self::assertStringContainsString( 'action=' . MasterclassAdminController::FLYERS_ACTION, $html );
		self::assertStringContainsString( 'security=nonce_', $html, 'ссылка на флаеры несёт nonce' );
		self::assertStringContainsString( 'view=accounts', $html );
		self::assertStringContainsString( 'gid=31', $html, 'ссылка «Открыть в плеере» ведёт на занятие' );
		self::assertStringContainsString( 'target="_blank"', $html );
	}

	public function test_a_deleting_masterclass_offers_no_mutating_actions(): void {
		$this->service->method( 'overview' )->willReturn( array( 'rows' => array( $this->overviewRow( MasterclassStatus::Deleting, array( 'delete_requested_at' => '2026-11-11 00:00:00' ) ) ), 'total' => 1 ) );
		$this->service->method( 'teacherOptions' )->willReturn( array() );

		$html = $this->render();

		self::assertStringContainsString( 'удаляется', $html );
		foreach ( array( 'add', 'unlock', 'extend', 'edit', 'delete' ) as $action ) {
			self::assertStringNotContainsString( 'data-mk-action="' . $action . '"', $html, "действие {$action} недоступно" );
		}
		self::assertStringNotContainsString( 'Печать флаеров', $html );
		self::assertStringContainsString( 'view=accounts', $html, 'список аккаунтов остаётся' );
	}

	public function test_a_purged_masterclass_keeps_only_the_accounts_link_and_no_player(): void {
		$this->service->method( 'overview' )->willReturn( array(
			'rows'  => array( $this->overviewRow( MasterclassStatus::Purged, array( 'purged_at' => '2026-12-30 00:00:00', 'group_id' => null, 'group_lesson_id' => null ) ) ),
			'total' => 1,
		) );
		$this->service->method( 'teacherOptions' )->willReturn( array() );

		$html = $this->render();

		self::assertStringContainsString( 'вычищен', $html );
		self::assertStringNotContainsString( 'data-mk-action', $html );
		self::assertStringNotContainsString( 'Открыть в плеере', $html );
		self::assertStringContainsString( 'view=accounts', $html );
	}

	public function test_an_empty_list_says_so_and_the_form_is_present_and_hidden(): void {
		$this->service->method( 'overview' )->willReturn( array( 'rows' => array(), 'total' => 0 ) );
		$this->service->method( 'teacherOptions' )->willReturn( array( array( 'id' => 3, 'name' => 'Иванова А.' ) ) );

		$html = $this->render();

		self::assertStringContainsString( 'Мастер-классов пока нет', $html );
		self::assertMatchesRegularExpression( '/id="fs-masterclass-form-card"[^>]*\shidden/', $html );
		self::assertStringContainsString( 'value="09:00"', $html, 'окно по умолчанию — из настроек модуля' );
		self::assertStringContainsString( 'value="14:00"', $html );
		self::assertMatchesRegularExpression( '/name="count"[^>]*value="60"/', $html, 'размер пачки — из настроек модуля' );
		self::assertStringContainsString( 'Иванова А.', $html );
	}

	public function test_the_form_offers_only_active_subjects_with_a_lesson_bank(): void {
		$this->service->method( 'overview' )->willReturn( array( 'rows' => array(), 'total' => 0 ) );
		$this->service->method( 'teacherOptions' )->willReturn( array() );

		$html = $this->render();

		self::assertStringContainsString( '<option value="inf">Информатика</option>', $html );
		self::assertStringNotContainsString( 'value="old"', $html, 'архивный предмет не предлагается' );
		self::assertStringNotContainsString( 'value="bio"', $html, 'предмет без банка уроков не предлагается' );
	}

	public function test_the_page_has_no_inline_styles_scripts_or_svg(): void {
		$this->service->method( 'overview' )->willReturn( array( 'rows' => array( $this->overviewRow( MasterclassStatus::Waiting ) ), 'total' => 1 ) );
		$this->service->method( 'teacherOptions' )->willReturn( array() );

		$html = $this->render();

		self::assertStringNotContainsString( ' style="', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<svg', $html );
		self::assertDoesNotMatchRegularExpression( '/\sonclick=/i', $html );
	}

	public function test_a_long_list_is_paginated(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'paged' => '2' );
		$this->service->expects( self::once() )->method( 'overview' )->with( 2, MasterclassAdminController::PER_PAGE )->willReturn( array( 'rows' => array( $this->overviewRow( MasterclassStatus::Waiting ) ), 'total' => 41 ) );
		$this->service->method( 'teacherOptions' )->willReturn( array() );

		$html = $this->render();

		self::assertStringContainsString( 'Страница 2 из 3', $html );
		self::assertStringContainsString( 'paged=1', $html );
		self::assertStringContainsString( 'paged=3', $html );
	}

	// ─── вид «аккаунты» (AdminAccountsRenderTest) ──────────────────────────

	/** @return list<array<string, mixed>> */
	private function accountRows( int $from, int $to, array $deleted = array() ): array {
		$rows = array();
		for ( $n = $from; $n <= $to; ++$n ) {
			$isDeleted = in_array( $n, $deleted, true );
			$rows[]    = array(
				'number'          => $n,
				'login'           => 'student-' . $n,
				'state'           => 'Выдан',
				'firstLoginLocal' => 1 === $n ? '2026-11-12 09:30:00' : null,
				'homeLocal'       => null,
				'contactLocal'    => null,
				'deletedLocal'    => $isDeleted ? '2026-11-19 14:00:00' : null,
				'deleteReason'    => $isDeleted ? 'Не использован' : null,
				'hasApplication'  => 2 === $n,
			);
		}

		return $rows;
	}

	public function test_accounts_view_lists_numbers_logins_states_and_times_without_any_password(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => '7' );
		$this->accounts->method( 'page' )->willReturn( array( 'masterclass' => $this->masterclass(), 'rows' => $this->accountRows( 1, 3 ), 'total' => 3 ) );

		$html = $this->render();

		self::assertStringContainsString( 'Школа №5', $html );
		self::assertStringContainsString( '12.11.2026', $html );
		foreach ( array( 'student-1', 'student-2', 'student-3', '2026-11-12 09:30:00' ) as $needle ) {
			self::assertStringContainsString( $needle, $html );
		}
		self::assertStringContainsString( 'да', $html, 'у второго аккаунта есть заявка' );
		self::assertDoesNotMatchRegularExpression( '/парол|password|pwd/iu', $html, 'паролей и ПД в разметке нет' );
		self::assertStringContainsString( '← К мастер-классам', $html );
	}

	public function test_accounts_view_asks_for_fifty_per_page_and_flags_deleted_only_on_request(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => '7', 'paged' => '2', 'deleted' => '1' );
		$this->accounts->expects( self::once() )->method( 'page' )->with( 7, 2, 50, true )->willReturn( array(
			'masterclass' => $this->masterclass(),
			'rows'        => $this->accountRows( 51, 60, array( 55 ) ),
			'total'       => 60,
		) );

		$html = $this->render();

		self::assertStringContainsString( 'Удалён 2026-11-19 14:00:00: Не использован', $html );
		self::assertStringContainsString( 'Скрыть удалённые', $html );
		self::assertStringContainsString( 'Страница 2 из 2', $html );
		self::assertStringContainsString( 'paged=1', $html, 'на второй странице есть «Назад»' );
		self::assertStringNotContainsString( 'paged=3', $html );
		self::assertStringContainsString( 'deleted=1', $html, 'пагинация сохраняет галочку «удалённые»' );
	}

	public function test_accounts_view_without_the_deleted_flag_offers_to_show_them(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => '7' );
		$this->accounts->expects( self::once() )->method( 'page' )->with( 7, 1, 50, false )->willReturn( array( 'masterclass' => $this->masterclass(), 'rows' => $this->accountRows( 1, 50 ), 'total' => 60 ) );

		$html = $this->render();

		self::assertStringContainsString( 'Показать удалённые', $html );
		self::assertStringContainsString( 'Страница 1 из 2', $html );
		self::assertStringContainsString( 'paged=2', $html );
		self::assertStringNotContainsString( 'paged=0', $html );
	}

	public function test_accounts_view_of_an_empty_masterclass_says_so(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => '7' );
		$this->accounts->method( 'page' )->willReturn( array( 'masterclass' => $this->masterclass(), 'rows' => array(), 'total' => 0 ) );

		$html = $this->render();

		self::assertStringContainsString( 'Аккаунтов нет', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	public function test_accounts_view_of_a_purged_masterclass_explains_the_empty_list(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => '7' );
		$this->accounts->method( 'page' )->willReturn( array( 'masterclass' => $this->masterclass( array( 'purged_at' => '2026-12-30 00:00:00', 'group_id' => null ) ), 'rows' => array(), 'total' => 0 ) );

		self::assertStringContainsString( 'вычищен', $this->render() );
	}

	public function test_accounts_view_of_a_missing_masterclass_shows_a_message_not_a_fatal(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts', 'id' => '99' );
		$this->accounts->method( 'page' )->willThrowException( new \InvalidArgumentException( 'Мастер-класс не найден.' ) );

		$html = $this->render();

		self::assertStringContainsString( 'Мастер-класс не найден.', $html );
		self::assertStringContainsString( '← К мастер-классам', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	public function test_accounts_view_without_an_id_is_a_message_too(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'accounts' );
		$this->accounts->expects( self::once() )->method( 'page' )->with( 0, 1, 50, false )->willThrowException( new \InvalidArgumentException( 'Мастер-класс не найден.' ) );

		self::assertStringContainsString( 'Мастер-класс не найден.', $this->render() );
	}

	// ─── вид «журнал» (этап 6) ─────────────────────────────────────────────

	/** @return list<MasterclassLogDTO> */
	private function logRows(): array {
		return array(
			new MasterclassLogDTO(
				id: 2, action: MasterclassLogAction::ApplicationBindFailed->value, result: 'failure',
				actorType: 'user', actorUserId: 11, actorLabel: 'student-11',
				masterclassId: 7, accountId: 11, subjectLabel: 'Ученик#11',
				actorIp: '5.6.7.8', context: array( 'reason' => 'live_application', 'attempt_password' => 's3cret' ),
				createdAt: '2026-11-12 10:00:00'
			),
			new MasterclassLogDTO(
				id: 1, action: MasterclassLogAction::LoginAtHome->value, result: 'success',
				actorType: 'user', actorUserId: 12, actorLabel: null,
				masterclassId: 7, accountId: 12, subjectLabel: 'Ученик#12',
				actorIp: '', context: array(),
				createdAt: '2026-11-12 09:30:00'
			),
		);
	}

	public function test_log_view_renders_rows_with_labels_local_time_and_secret_free_context(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'log' );
		$this->log->method( 'list' )->willReturn( array( 'rows' => $this->logRows(), 'total' => 2 ) );
		$this->log->method( 'distinctActions' )->willReturn( array( 'login_at_home', 'legacy_action' ) );

		$html = $this->render();

		self::assertStringContainsString( '12.11.2026 13:00', $html, 'время — местное (Москва, +3)' );
		self::assertStringContainsString( 'Заявку не удалось привязать', $html, 'подпись действия по enum' );
		self::assertStringContainsString( 'не удалось', $html, 'отказ помечен' );
		self::assertStringContainsString( 'Вход дома', $html );
		self::assertStringContainsString( 'Ученик#12', $html );
		self::assertStringContainsString( 'Ученик#11', $html );
		self::assertStringContainsString( '№ 7', $html, 'мастер-класс — ссылка на аккаунты' );
		self::assertStringContainsString( 'view=accounts', $html );
		self::assertStringContainsString( 'reason', $html );
		self::assertStringContainsString( 'live_application', $html );
		self::assertStringNotContainsString( 's3cret', $html, 'секреты из контекста не показываются' );
		self::assertStringNotContainsString( 'attempt_password', $html );
		self::assertStringNotContainsString( ' style="', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<svg', $html );
	}

	public function test_log_view_passes_filters_converting_local_dates_to_utc_boundaries(): void {
		$_GET = array(
			'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'log', 'paged' => '2',
			'mk_action' => 'login_at_home', 'mk_mid' => '7', 'mk_acc' => '12',
			'mk_from' => '2026-11-12', 'mk_to' => '2026-11-12',
		);
		$this->log->expects( self::once() )->method( 'list' )->with(
			array(
				'action'         => 'login_at_home',
				'masterclass_id' => 7,
				'account_id'     => 12,
				'date_from'      => '2026-11-11',
				'date_to'        => '2026-11-12',
			),
			2,
			50
		)->willReturn( array( 'rows' => $this->logRows(), 'total' => 120 ) );
		$this->log->method( 'distinctActions' )->willReturn( array() );

		$html = $this->render();

		self::assertStringContainsString( 'Страница 2 из 3', $html );
		self::assertStringContainsString( 'mk_action=login_at_home', $html, 'пагинация сохраняет фильтры' );
		self::assertStringContainsString( 'mk_mid=7', $html );
		self::assertStringContainsString( 'mk_acc=12', $html );
		self::assertStringContainsString( 'mk_from=2026-11-12', $html, 'в форму возвращаются местные даты' );
		self::assertStringContainsString( 'mk_to=2026-11-12', $html );
		self::assertStringContainsString( 'paged=1', $html );
		self::assertStringContainsString( 'paged=3', $html );
	}

	public function test_log_view_shows_raw_label_for_actions_missing_from_the_enum(): void {
		$_GET = array( 'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'log' );
		$this->log->method( 'list' )->willReturn( array( 'rows' => array(), 'total' => 0 ) );
		$this->log->method( 'distinctActions' )->willReturn( array( 'legacy_action', 'flyers_printed' ) );

		$html = $this->render();

		self::assertStringContainsString( 'legacy_action', $html, 'неизвестное действие показывается сырым значением' );
		self::assertStringContainsString( '<option value="legacy_action"', $html );
		self::assertStringContainsString( 'value="flyers_printed"', $html );
		self::assertStringContainsString( 'Флаеры напечатаны', $html, 'известное действие фильтруется по подписи enum' );
		self::assertStringContainsString( 'Записей журнала по этому фильтру нет', $html );
	}

	public function test_log_view_ignores_garbage_in_filter_fields(): void {
		$_GET = array(
			'page' => MasterclassAdminController::PAGE_SLUG, 'view' => 'log',
			'mk_mid' => '0', 'mk_acc' => 'abc', 'mk_from' => 'not-a-date', 'mk_to' => '2026-13-40',
		);
		$this->log->expects( self::once() )->method( 'list' )->with( array(), 1, 50 )
			->willReturn( array( 'rows' => array(), 'total' => 0 ) );
		$this->log->method( 'distinctActions' )->willReturn( array() );

		$this->render();
	}

	public function test_the_list_tab_now_links_to_the_log_view(): void {
		$this->service->method( 'overview' )->willReturn( array( 'rows' => array(), 'total' => 0 ) );
		$this->service->method( 'teacherOptions' )->willReturn( array() );

		$html = $this->render();

		self::assertMatchesRegularExpression( '/<a class="nav-tab" href="[^"]*view=log[^"]*">Журнал<\/a>/', $html );
	}
}
