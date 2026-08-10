<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Course\BankType;
use Inc\Services\Course\TeacherSubjectsService;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class BankChromeController
 *
 * «Хром» банков контента: шапка над нативной таблицей (описание + таб-бар
 * предметов одним `.notice`) и лендинг-фолбэки страниц банков, когда прямого
 * edit.php-слага нет (в системе нет предметов с CPT банка).
 *
 * Выделен из LearningMenuController (Т14.1): меню строит LearningMenuController,
 * колбеки его сабстраниц-фолбэков (`renderCourses()` и др.) живут здесь.
 *
 * @package Inc\Controllers\Course
 */
class BankChromeController extends BaseController implements ServiceInterface {

	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly TeacherSubjectsService $teacher_subjects,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Над таблицей банка: описание + таб-бар предметов ОДНИМ блоком-нотисом.
		// НБ-1: единый `.notice` штатный JS WP переносит под заголовок целиком, поэтому
		// табы не «прыгают» отдельно от описания при загрузке страницы.
		add_action( 'admin_notices', array( $this, 'renderBankChrome' ) );
	}

	public function renderCourses(): void {
		$this->renderBank( BankType::Courses );
	}

	public function renderLessons(): void {
		$this->renderBank( BankType::Lessons );
	}

	public function renderWorks(): void {
		$this->renderBank( BankType::Works );
	}

	public function renderTasks(): void {
		$this->renderBank( BankType::Tasks );
	}

	public function renderArticles(): void {
		$this->renderBank( BankType::Articles );
	}

	public function renderAssessments(): void {
		$this->renderBank( BankType::Assessments );
	}

	/**
	 * Тип банка для текущего экрана списка (или null — не экран банка).
	 */
	private function currentBankType(): ?BankType {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return null;
		}

		return BankType::fromPostType( $screen->post_type );
	}

	/**
	 * Выводит «шапку» банка над нативной таблицей ОДНИМ блоком: описание-абзац +
	 * таб-бар предметов (при 2+ предметах, курсы/уроки/работы/задания/статьи).
	 *
	 * НБ-1: и описание, и табы лежат в одном `.notice`, который штатный JS WP
	 * целиком переносит под заголовок (перед `subsubsub`-views), поэтому табы не
	 * «прыгают» отдельно от описания при загрузке. Хук admin_notices.
	 */
	public function renderBankChrome(): void {
		$bankType = $this->currentBankType();
		if ( null === $bankType ) {
			return;
		}

		$tabs     = array();
		// Эпик 18: не предлагаем переключиться на предмет без CPT этого банка (T18.4).
		$subjects = array_filter(
			$this->teacher_subjects->subjectsForUser( get_current_user_id() ),
			static fn( $s ) => post_type_exists( $bankType->cpt( $s->key ) )
		);
		if ( count( $subjects ) >= 2 ) {
			$active = $bankType->subjectFromPostType( get_current_screen()->post_type );
			foreach ( $subjects as $subject ) {
				$tabs[] = array(
					'name'   => $subject->name,
					'url'    => admin_url( 'edit.php?post_type=' . $bankType->cpt( $subject->key ) ),
					'active' => $subject->key === $active,
				);
			}
		}

		$this->render(
			'admin/components/bank-notice',
			array(
				'text' => $bankType->description(),
				'tabs' => $tabs,
			)
		);
	}

	/**
	 * Рендерит лендинг-фолбэк банка: вкладки-предметы + переход на нативный экран CPT.
	 * Вызывается только когда у меню нет прямого edit.php-слага (нет предметов в системе).
	 */
	private function renderBank( BankType $bankType ): void {
		$user     = get_current_user_id();
		// Эпик 18: только предметы, у которых реально зарегистрирован CPT этого банка
		// (T18.4) — иначе список/добавление вели бы на несуществующий post_type.
		$subjects = array_values( array_filter(
			$this->teacher_subjects->subjectsForUser( $user ),
			static fn( $s ) => post_type_exists( $bankType->cpt( $s->key ) )
		) );

		$active = $this->sanitizeGetKey( 'fs_subject' );
		$keys   = array_map( static fn( $s ) => $s->key, $subjects );
		if ( '' === $active || ! in_array( $active, $keys, true ) ) {
			$active = $keys[0] ?? '';
		}

		$page_slug = $bankType->menu()->value;
		$tabs      = array();
		foreach ( $subjects as $subject ) {
			$tabs[] = array(
				'name'   => $subject->name,
				'url'    => add_query_arg(
					array( 'page' => $page_slug, 'fs_subject' => $subject->key ),
					admin_url( 'admin.php' )
				),
				'active' => $subject->key === $active,
			);
		}

		$cpt      = '' !== $active ? $bankType->cpt( $active ) : '';
		$list_url = '' !== $cpt ? admin_url( 'edit.php?post_type=' . $cpt ) : '';
		$new_url  = '' !== $cpt ? admin_url( 'post-new.php?post_type=' . $cpt ) : '';

		$this->render( 'admin/learning/bank-landing', compact( 'subjects', 'tabs', 'list_url', 'new_url' ) + array(
			'title' => $bankType->title(),
		) );
	}
}
