<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Course\WorkType;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateResolver;
use Inc\Services\Task\TaskBundleService;

/**
 * Class WorkAuthoringService
 *
 * Бизнес-логика авторинга работы: кандидаты-элементы для селектора, коллекции.
 *
 * @package Inc\Services\Course
 */
class WorkAuthoringService {

	/**
	 * Размер страницы кандидатов. Пикер слота берёт только первую страницу (дальше —
	 * поиск), окно массового добавления догружает следующие по прокрутке.
	 */
	private const CANDIDATES_PER_PAGE = 50;

	/** Сколько страниц источника просматривается, пока набирается страница кандидатов с отбором по шаблону. */
	private const MAX_SCAN_PAGES = 20;

	public function __construct(
		private readonly PostManager      $posts,
		private readonly TermManager      $terms,
		private readonly TaskBundleService $taskBundles,
		private readonly TemplateResolver $templateResolver,
	) {}

	/**
	 * Кандидаты-задания для селектора работы (только {key}_tasks текущего предмета).
	 *
	 * @param string $subjectKey
	 * @param int    $taskTypeTermId   0 = все типы
	 * @param int    $collectionTermId 0 = все коллекции
	 * @param string $scope            'mine' | 'subject'
	 * @param string $search
	 * @param bool   $onlyPublished    true — только опубликованные задачи (источник
	 *                                 «публичные задачи»: то, что автор видит в
	 *                                 тренажёре); по умолчанию идут и черновики.
	 * @param int    $page             Страница выдачи, с 1.
	 * @return array<int, array{id: int, title: string, author: int}>
	 */
	public function getTaskCandidates(
		string $subjectKey,
		int    $taskTypeTermId   = 0,
		int    $collectionTermId = 0,
		string $scope            = 'mine',
		string $search           = '',
		bool   $onlyPublished    = false,
		int    $page             = 1
	): array {
		$tax_query = array();
		if ( $taskTypeTermId > 0 ) {
			$tax_query[] = array(
				'taxonomy' => PostTypeResolver::getTaskTaxonomy( $subjectKey ),
				'field'    => 'term_id',
				'terms'    => $taskTypeTermId,
			);
		}
		if ( $collectionTermId > 0 ) {
			$tax_query[] = array(
				'field' => 'term_id',
				'terms' => $collectionTermId,
			);
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		$args = array(
			'limit'     => self::CANDIDATES_PER_PAGE,
			'offset'    => $this->pageOffset( $page ),
			'author'    => 'mine' === $scope ? get_current_user_id() : 0,
			'search'    => $search,
			'tax_query' => $tax_query,
			'orderby'   => 'date',
			'order'     => 'DESC',
		);
		if ( $onlyPublished ) {
			$args['status'] = array( 'publish' );
		}

		$posts = $this->posts->search( PostTypeResolver::tasks( $subjectKey ), $args );

		return array_map( fn( \WP_Post $post ): array => $this->withBundleChildren( array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'author' => (int) $post->post_author,
			'type'   => 'task',
		), $post->ID ), $posts );
	}

	/**
	 * Если пост — parent связки (19/20/21), добавляет `bundle_children` (id+title
	 * трёх children): builder разворачивает такой пик в 3 слота вместо одного
	 * (см. .docs/Tasks.md, §3.3).
	 *
	 * @param array<string, mixed> $item
	 *
	 * @return array<string, mixed>
	 */
	private function withBundleChildren( array $item, int $postId ): array {
		$children = $this->taskBundles->childrenSummary( $postId );
		if ( ! empty( $children ) ) {
			$item['bundle_children'] = $children;
		}

		return $item;
	}

	/**
	 * Кандидаты-элементы: {key}_tasks + fs_lms_problems (unified).
	 *
	 * Дропдаун конструктора по умолчанию сужен до предмета (упрощение поиска
	 * для пустого списка, НЕ жёсткое ограничение — банк всё равно доступен и
	 * через поиск, и через явный переключатель «Все задания»). Задача может
	 * быть «предметной», физически лежа в глобальном банке (fs_lms_problems,
	 * напр. предмет без своего CPT-банка, Эпик 18) — привязку задаёт мета
	 * {@see PostMetaName::BankTaskSubject}, выставляемая на странице банка.
	 * `$source` 'subject' — {key}_tasks + банк, помеченный ЭТИМ предметом;
	 * 'all' — {key}_tasks + весь банк без сужения; 'public' — только
	 * опубликованные задачи предмета, без банка и без сужения по автору
	 * (источник «Выбрать из публичных задач»: каталог тренажёра целиком,
	 * независимо от того, кто задачу завёл).
	 *
	 * @param string $subjectKey
	 * @param int    $collectionTermId 0 = все коллекции (только для task)
	 * @param string $scope            'mine' | 'subject'
	 * @param string $search
	 * @param string $source           'subject' | 'all' | 'public'
	 * @param int    $page             Страница выдачи, с 1. У смешанных источников страница —
	 *                                 это страница заданий предмета плюс страница банка, поэтому
	 *                                 конец списка — пустая страница, а не неполная.
	 * @param string $targetSubject    Предмет работы, в которую добавляют. Отличается от `$subjectKey` —
	 *                                 значит идёт кросс-предметный импорт: из чужого предмета берутся только
	 *                                 опубликованные задания (черновик чужого предмета в работу не пустят).
	 * @param string $workType         Тип работы ({@see WorkType}); у типа с ограничением шаблонов
	 *                                 ({@see WorkType::allowedTemplates()}) остаются только подходящие
	 *                                 задания, страницы нарезаются уже по отобранным.
	 * @return array<int, array{id: int, title: string, author: int, type: string}>
	 */
	public function getItemCandidates(
		string $subjectKey,
		int    $collectionTermId = 0,
		string $scope            = 'mine',
		string $search           = '',
		string $source           = 'subject',
		int    $page             = 1,
		string $workType         = '',
		string $targetSubject    = ''
	): array {
		$allowed = WorkType::tryFrom( $workType )?->allowedTemplates();
		if ( null === $allowed ) {
			return $this->sourceItemCandidates( $subjectKey, $collectionTermId, $scope, $search, $source, $page, $targetSubject );
		}

		$need = max( 1, $page ) * self::CANDIDATES_PER_PAGE;
		$kept = array();
		for ( $raw = 1; $raw <= self::MAX_SCAN_PAGES && count( $kept ) < $need; $raw++ ) {
			$batch = $this->sourceItemCandidates( $subjectKey, $collectionTermId, $scope, $search, $source, $raw, $targetSubject );
			if ( array() === $batch ) {
				break;
			}
			foreach ( $batch as $item ) {
				if ( $this->hasAllowedTemplate( (int) $item['id'], $allowed ) ) {
					$kept[] = $item;
				}
			}
		}

		return array_slice( $kept, $this->pageOffset( $page ), self::CANDIDATES_PER_PAGE );
	}

	/**
	 * Задания из списка, чей шаблон не разрешён типом работы (для проверки перед сохранением состава).
	 *
	 * @param int[] $itemIds
	 *
	 * @return int[] ID заданий, которых в работе такого типа быть не должно
	 */
	public function disallowedItemIds( WorkType $type, array $itemIds ): array {
		$allowed = $type->allowedTemplates();
		if ( null === $allowed ) {
			return array();
		}

		return array_values( array_filter(
			array_map( 'intval', $itemIds ),
			fn( int $id ): bool => $id > 0 && ! $this->hasAllowedTemplate( $id, $allowed )
		) );
	}

	/** @param \Inc\Enums\Subject\TaskTemplate[] $allowed */
	private function hasAllowedTemplate( int $postId, array $allowed ): bool {
		$post = $this->posts->get( $postId );

		return null !== $post && in_array( $this->templateResolver->resolveEnum( $post ), $allowed, true );
	}

	/**
	 * Страница кандидатов-элементов выбранного источника, без отбора по типу работы.
	 *
	 * @return array<int, array{id: int, title: string, author: int, type: string}>
	 */
	private function sourceItemCandidates(
		string $subjectKey,
		int    $collectionTermId,
		string $scope,
		string $search,
		string $source,
		int    $page,
		string $targetSubject = ''
	): array {
		if ( 'public' === $source ) {
			return $this->getTaskCandidates( $subjectKey, 0, $collectionTermId, 'subject', $search, true, $page );
		}

		$foreign  = '' !== $targetSubject && $targetSubject !== $subjectKey;
		$tasks    = $this->getTaskCandidates( $subjectKey, 0, $collectionTermId, $scope, $search, $foreign, $page );
		$problems = $this->getProblemCandidates( $search, 'all' === $source ? '' : $subjectKey, $page );

		return array_merge( $tasks, $problems );
	}

	/**
	 * Кандидаты из банка задач (fs_lms_problems, глобальный).
	 *
	 * @param string $search
	 * @param string $subjectKey Непусто — сузить до задач, помеченных этим предметом
	 *                           ({@see PostMetaName::BankTaskSubject}); пусто — весь банк.
	 * @param int    $page       Страница выдачи, с 1.
	 * @return array<int, array{id: int, title: string, author: int, type: string}>
	 */
	public function getProblemCandidates( string $search = '', string $subjectKey = '', int $page = 1 ): array {
		$opts = array(
			'limit'   => self::CANDIDATES_PER_PAGE,
			'offset'  => $this->pageOffset( $page ),
			'search'  => $search,
			'orderby' => 'date',
			'order'   => 'DESC',
		);
		if ( '' !== $subjectKey ) {
			$opts['meta_query'] = array(
				array( 'key' => PostMetaName::BankTaskSubject->value, 'value' => $subjectKey ),
			);
		}

		$posts = $this->posts->search( PostTypeResolver::problems(), $opts );

		return array_map( fn( \WP_Post $post ): array => $this->withBundleChildren( array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'author' => (int) $post->post_author,
			'type'   => 'problem',
		), $post->ID ), $posts );
	}

	/** Смещение выборки для страницы кандидатов (страницы — с 1). */
	private function pageOffset( int $page ): int {
		return ( max( 1, $page ) - 1 ) * self::CANDIDATES_PER_PAGE;
	}

	/**
	 * Создаёт черновик задачи (fs_lms_problems).
	 *
	 * @param string $title
	 * @return int  ID новой задачи (>0) или 0 при ошибке.
	 */
	public function createProblemDraft( string $title ): int {
		return $this->posts->insert( array(
			'post_title'  => $title,
			'post_type'   => PostTypeResolver::problems(),
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		) );
	}

	/**
	 * Термины коллекций (пользовательские таксономии на заданиях).
	 *
	 * @param string $subjectKey
	 * @return array<int, string> term_id => name
	 */
	public function getCollections( string $subjectKey ): array {
		return $this->terms->listCollections(
			PostTypeResolver::tasks( $subjectKey ),
			PostTypeResolver::getTaskTaxonomy( $subjectKey )
		);
	}

	/**
	 * Валидирует элементы работы: оставляет задания предмета работы (любой статус), опубликованные
	 * задания других предметов (кросс-предметный импорт) и задачи банка `fs_lms_problems`.
	 *
	 * Чужой черновик не пускаем: он ещё не вышел из рук автора и может пропасть или измениться.
	 *
	 * @param string $subjectKey Предмет работы
	 * @param int[]  $itemIds
	 * @return int[]
	 */
	public function validateItemIds( string $subjectKey, array $itemIds ): array {
		$task_cpt = PostTypeResolver::tasks( $subjectKey );

		return array_values( array_filter( $itemIds, function ( int $id ) use ( $task_cpt ): bool {
			$post = $this->posts->get( $id );
			if ( ! $post instanceof \WP_Post ) {
				return false;
			}
			if ( $post->post_type === $task_cpt || PostTypeResolver::isProblemPostType( $post->post_type ) ) {
				return true;
			}

			return PostTypeResolver::isTaskPostType( $post->post_type ) && 'publish' === $post->post_status;
		} ) );
	}
}
