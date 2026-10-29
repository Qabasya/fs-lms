<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Menu;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Wp\MenuManager;
use Inc\Modules\Masterclass\Callbacks\MasterclassAdminCallbacks;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\DTO\MasterclassDTO;
use Inc\Modules\Masterclass\Enums\MasterclassStatus;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassService;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class MasterclassAdminController
 *
 * Страница модуля «FS LMS → Мастер-классы»: подстраница меню, AJAX и печать флаеров, её скрипт и два вида — список
 * мастер-классов и список аккаунтов одного из них (`?view=accounts&id=N`, серверный рендер без AJAX).
 * Вкладка «Журнал» приходит в этапе 6. Регистрируется только при включённом модуле.
 *
 * Хуки только здесь; ввод в AJAX проверяют коллбеки, данные считают сервисы. Контроллер лишь готовит данные для шаблонов.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassAdminController extends BaseController {

	use Sanitizer;

	/** Слаг страницы (`?page=`). */
	public const PAGE_SLUG = 'fs_lms_masterclasses';

	public const CREATE_ACTION       = 'fs_lms_masterclass_create';
	public const UPDATE_ACTION       = 'fs_lms_masterclass_update';
	public const EXTEND_ACTION       = 'fs_lms_masterclass_extend';
	public const DELETE_ACTION       = 'fs_lms_masterclass_delete';
	public const ADD_ACCOUNTS_ACTION = 'fs_lms_masterclass_add_accounts';
	public const LESSONS_ACTION      = 'fs_lms_masterclass_lessons';
	public const UNLOCK_ACTION       = 'fs_lms_masterclass_unlock';

	/** Печать флаеров идёт через `admin-post.php`, а не `admin-ajax.php`. */
	public const FLYERS_ACTION = 'fs_lms_masterclass_flyers';

	/** Вид «аккаунты мастер-класса» (`?view=accounts`). */
	public const VIEW_ACCOUNTS = 'accounts';

	public const PER_PAGE          = 20;
	public const ACCOUNTS_PER_PAGE = 50;

	/** Формат даты и времени на странице (местное время). */
	private const DISPLAY_FORMAT = 'd.m.Y H:i';

	public function __construct(
		private readonly MenuManager $menu,
		private readonly MasterclassAdminCallbacks $callbacks,
		private readonly MasterclassService $service,
		private readonly MasterclassAccountService $accounts,
		private readonly SubjectRepository $subjects,
		private readonly MasterclassConfig $config,
		private readonly ExamTime $time,
	) {
		parent::__construct();
	}

	public function register(): void {
		$this->menu->register(
			array(),
			array(
				array(
					'parent_slug' => Menu::Main->value,
					'page_title'  => 'Мастер-классы',
					'menu_title'  => 'Мастер-классы',
					'capability'  => Capability::ManageLmsPlatform->value,
					'menu_slug'   => self::PAGE_SLUG,
					'callback'    => array( $this, 'renderPage' ),
				),
			)
		);

		add_action( 'wp_ajax_' . self::CREATE_ACTION, array( $this->callbacks, 'ajaxCreate' ) );
		add_action( 'wp_ajax_' . self::UPDATE_ACTION, array( $this->callbacks, 'ajaxUpdate' ) );
		add_action( 'wp_ajax_' . self::EXTEND_ACTION, array( $this->callbacks, 'ajaxExtend' ) );
		add_action( 'wp_ajax_' . self::DELETE_ACTION, array( $this->callbacks, 'ajaxDelete' ) );
		add_action( 'wp_ajax_' . self::ADD_ACCOUNTS_ACTION, array( $this->callbacks, 'ajaxAddAccounts' ) );
		add_action( 'wp_ajax_' . self::LESSONS_ACTION, array( $this->callbacks, 'ajaxLessons' ) );
		add_action( 'wp_ajax_' . self::UNLOCK_ACTION, array( $this->callbacks, 'ajaxUnlock' ) );
		add_action( 'admin_post_' . self::FLYERS_ACTION, array( $this->callbacks, 'printFlyers' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/**
	 * Скрипт страницы грузится только на списке мастер-классов: вид аккаунтов серверный, ему JS не нужен.
	 * Локализует свой admin-JS сам модуль (исключение из правила «localize только в Core/Assets»).
	 */
	public function enqueueAssets(): void {
		if ( self::PAGE_SLUG !== $this->sanitizeGetKey( 'page' ) || self::VIEW_ACCOUNTS === $this->sanitizeGetKey( 'view' ) ) {
			return;
		}

		$rel  = 'inc/Modules/Masterclass/assets/admin.js';
		$path = $this->path( $rel );
		wp_enqueue_script(
			'fs-lms-masterclass-admin',
			$this->url( $rel ),
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version,
			true
		);
		wp_localize_script(
			'fs-lms-masterclass-admin',
			'fsLmsMasterclass',
			array(
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => Nonce::Config->create(),
				'actions'  => array(
					'create'      => self::CREATE_ACTION,
					'update'      => self::UPDATE_ACTION,
					'extend'      => self::EXTEND_ACTION,
					'delete'      => self::DELETE_ACTION,
					'addAccounts' => self::ADD_ACCOUNTS_ACTION,
					'lessons'     => self::LESSONS_ACTION,
					'unlock'      => self::UNLOCK_ACTION,
				),
				'chunkMax' => MasterclassAccountService::CHUNK_MAX,
				'defaults' => array(
					'batchSize' => $this->config->batchSize(),
					'startTime' => $this->config->startTime(),
					'endTime'   => $this->config->endTime(),
				),
			)
		);
	}

	public function renderPage(): void {
		if ( self::VIEW_ACCOUNTS === $this->sanitizeGetKey( 'view' ) ) {
			$this->renderTemplate( 'admin-accounts.php', $this->accountsView() );
			return;
		}

		$this->renderTemplate( 'admin-page.php', $this->listView() );
	}

	/**
	 * Данные списка мастер-классов: строки с воронкой, статусом, местным временем и адресами действий.
	 *
	 * @return array<string, mixed>
	 */
	private function listView(): array {
		$page     = max( 1, $this->sanitizeGetInt( 'paged' ) );
		$overview = $this->service->overview( $page, self::PER_PAGE );
		$teachers = $this->service->teacherOptions();
		$names    = array_column( $teachers, 'name', 'id' );
		$subjects    = array();
		$allSubjects = array();
		foreach ( $this->subjects->readAll() as $subject ) {
			$allSubjects[ $subject->key ] = $subject->name;
			if ( ! $subject->archived && $subject->hasBank ) {
				$subjects[ $subject->key ] = $subject->name;
			}
		}

		$rows = array();
		foreach ( $overview['rows'] as $row ) {
			/** @var MasterclassDTO $masterclass */
			$masterclass = $row['masterclass'];
			$startsLocal = $this->time->toLocal( $masterclass->startsAt );
			$endsLocal   = $this->time->toLocal( $masterclass->endsAt );

			$rows[] = array(
				'id'           => $masterclass->id,
				'school'       => $masterclass->schoolName,
				'subject_key'  => $masterclass->subjectKey,
				'subject_name' => $allSubjects[ $masterclass->subjectKey ] ?? $masterclass->subjectKey,
				'lesson_id'    => $masterclass->lessonId,
				'teacher_id'   => $masterclass->teacherUserId,
				'teacher_name' => $names[ $masterclass->teacherUserId ] ?? ( '#' . $masterclass->teacherUserId ),
				'date'         => substr( $startsLocal, 0, 10 ),
				'start'        => substr( $startsLocal, 11, 5 ),
				'end'          => substr( $endsLocal, 11, 5 ),
				'window_label' => ( new \DateTimeImmutable( $startsLocal ) )->format( self::DISPLAY_FORMAT ) . ' – ' . substr( $endsLocal, 11, 5 ),
				'status'       => $row['status'],
				'status_label' => $row['status']->label(),
				'funnel'       => $row['funnel'],
				'editable'     => ! in_array( $row['status'], array( MasterclassStatus::Deleting, MasterclassStatus::Purged ), true ),
				'accounts_url' => $this->pageUrl( array( 'view' => self::VIEW_ACCOUNTS, 'id' => $masterclass->id ) ),
				'flyers_url'   => add_query_arg(
					array(
						'action'   => self::FLYERS_ACTION,
						'id'       => $masterclass->id,
						'security' => Nonce::Config->create(),
					),
					admin_url( 'admin-post.php' )
				),
				'player_url'   => null !== $masterclass->groupId && null !== $masterclass->groupLessonId
					? PageRoutes::LessonPlayer->lessonUrl( $masterclass->groupId, $masterclass->groupLessonId )
					: null,
			);
		}

		return array(
			'rows'     => $rows,
			'total'    => $overview['total'],
			'page'     => $page,
			'pages'    => max( 1, (int) ceil( $overview['total'] / self::PER_PAGE ) ),
			'teachers' => $teachers,
			'subjects' => $subjects,
			'defaults' => array(
				'batch_size' => $this->config->batchSize(),
				'start_time' => $this->config->startTime(),
				'end_time'   => $this->config->endTime(),
			),
			'list_url' => $this->pageUrl( array() ),
			'chunk_max' => MasterclassAccountService::CHUNK_MAX,
		);
	}

	/**
	 * Данные списка аккаунтов одного мастер-класса. Мастер-класса нет — сообщение на странице, не фатал.
	 *
	 * @return array<string, mixed>
	 */
	private function accountsView(): array {
		$id          = $this->sanitizeGetInt( 'id' );
		$page        = max( 1, $this->sanitizeGetInt( 'paged' ) );
		$withDeleted = 1 === $this->sanitizeGetInt( 'deleted' );
		$base        = array( 'view' => self::VIEW_ACCOUNTS, 'id' => $id );

		$view = array(
			'error'       => null,
			'back_url'    => $this->pageUrl( array() ),
			'with_deleted' => $withDeleted,
			'page'        => $page,
			'pages'       => 1,
			'total'       => 0,
			'rows'        => array(),
			'school'      => '',
			'date'        => '',
			'purged'      => false,
			'toggle_url'  => $this->pageUrl( $base + ( $withDeleted ? array() : array( 'deleted' => 1 ) ) ),
			'prev_url'    => null,
			'next_url'    => null,
		);

		try {
			$result = $this->accounts->page( $id, $page, self::ACCOUNTS_PER_PAGE, $withDeleted );
		} catch ( \InvalidArgumentException $e ) {
			$view['error'] = $e->getMessage();

			return $view;
		}

		$masterclass = $result['masterclass'];
		$pages       = max( 1, (int) ceil( $result['total'] / self::ACCOUNTS_PER_PAGE ) );
		$paged       = $base + ( $withDeleted ? array( 'deleted' => 1 ) : array() );

		return array_merge(
			$view,
			array(
				'rows'     => $result['rows'],
				'total'    => $result['total'],
				'pages'    => $pages,
				'school'   => $masterclass->schoolName,
				'date'     => ( new \DateTimeImmutable( $this->time->toLocal( $masterclass->startsAt ) ) )->format( 'd.m.Y' ),
				'purged'   => $masterclass->isPurged(),
				'prev_url' => $page > 1 ? $this->pageUrl( $paged + array( 'paged' => $page - 1 ) ) : null,
				'next_url' => $page < $pages ? $this->pageUrl( $paged + array( 'paged' => $page + 1 ) ) : null,
			)
		);
	}

	/**
	 * Адрес страницы модуля с параметрами.
	 *
	 * @param array<string, scalar> $args
	 */
	private function pageUrl( array $args ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Подключает шаблон модуля со своими переменными (область видимости не пересекается со страницей настроек).
	 *
	 * @param array<string, mixed> $vars
	 */
	private function renderTemplate( string $file, array $vars ): void {
		$template = $this->path( 'inc/Modules/Masterclass/templates/' . $file );

		( static function () use ( $template, $vars ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			require $template;
		} )();
	}
}
