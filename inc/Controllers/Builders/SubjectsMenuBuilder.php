<?php

namespace Inc\Controllers\Builders;

use Inc\Callbacks\Subject\SubjectPageCallbacks;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Menu;
use Inc\Repositories\OptionsRepositories\SubjectRepository;

/**
 * Class SubjectsMenuBuilder
 *
 * Динамический билдер меню для раздела "Предметы".
 *
 * Отвечает за построение структуры административного меню на основе
 * данных, хранящихся в репозитории предметов. Каждый предмет получает
 * собственную подстраницу с уникальным slug.
 *
 * @package Inc\Controllers\Builders
 * @example
 * $builder = new SubjectsMenuBuilder($repository, $callbacks);
 * $pages = $builder->buildPages();     // Главная страница предметов
 * $subpages = $builder->buildSubPages(); // Подстраницы для каждого предмета
 */
class SubjectsMenuBuilder {
	/**
	 * Репозиторий для работы с предметами.
	 *
	 * @var SubjectRepository
	 */
	private SubjectRepository $subject_repository;

	/**
	 * Коллбеки для рендеринга страниц админ-панели.
	 *
	 * @var SubjectPageCallbacks
	 */
	private SubjectPageCallbacks $callbacks;

	/**
	 * Кэш результата read_all() — чтобы не ходить в базу дважды
	 * при последовательном вызове buildPages() и buildSubPages().
	 *
	 * @var array|null
	 */
	private ?array $subjects = null;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository    $subject_repository Репозиторий предметов
	 * @param SubjectPageCallbacks $callbacks          Коллбеки рендера страниц
	 */
	public function __construct(
		SubjectRepository $subject_repository,
		SubjectPageCallbacks $callbacks
	) {
		$this->subject_repository = $subject_repository;
		$this->callbacks          = $callbacks;
	}

	/**
	 * Строит конфигурацию главной страницы раздела "Предметы".
	 * Возвращает пустой массив, если предметов нет.
	 *
	 * @return array<int, array{
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: array{0: SubjectPageCallbacks, 1: string},
	 *     icon_url: string,
	 *     position: int
	 * }>
	 */
	public function buildPages(): array {
		// Эпик 18: у безбанковых предметов нет своей подстраницы (buildSubPages()),
		// поэтому если банковых предметов нет вообще — верхний пункт меню вести
		// некуда (subjectsRoot-колбэка не существует, страница подменяется первой
		// подстраницей через remove_submenu_page() в AdminController).
		if ( empty( $this->getBankSubjects() ) ) {
			return array();
		}

		// Это первая страница в Предметы, она удаляется
		return array(
			array(
				'page_title' => Menu::Subjects->page_title(),
				'menu_title' => Menu::Subjects->menu_title(),
				'capability' => Capability::ManageLmsPlatform->value,
				'menu_slug'  => Menu::Subjects->value,
				'callback'   => array( $this->callbacks, Menu::Subjects->callback() ),
				'icon_url'   => 'dashicons-category',
				'position'   => 3,
			),
		);
	}

	/**
	 * Строит конфигурацию подстраниц для каждого предмета.
	 * Возвращает пустой массив, если предметов нет.
	 *
	 * @return array<int, array{
	 *     parent_slug: string,
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: array{0: SubjectPageCallbacks, 1: string}
	 * }>
	 */
	public function buildSubPages(): array {
		$subpages = array();

		foreach ( $this->getBankSubjects() as $subject ) {
			$subpages[] = array(
				'parent_slug' => Menu::Subjects->value,
				'page_title'  => $subject->name, // Используем -> вместо ['name']
				'menu_title'  => $subject->name,
				'capability'  => Capability::ManageLmsPlatform->value,
				'menu_slug'   => 'fs_subject_' . $subject->key, // Используем свойство key
				'callback'    => array( $this->callbacks, 'subjectPage' ),
			);
		}

		return $subpages;
	}

	/**
	 * Возвращает предметы из репозитория, кэшируя результат.
	 *
	 * @return SubjectDTO[]
	 */
	private function getSubjects(): array {
		if ( null === $this->subjects ) {
			$this->subjects = $this->subject_repository->readActive();
		}

		return $this->subjects;
	}

	/**
	 * Активные предметы С банком заданий/статей — только у них есть подстраница
	 * «Предметы → {имя}» (Эпик 18, D18.2).
	 *
	 * @return SubjectDTO[]
	 */
	private function getBankSubjects(): array {
		return array_filter( $this->getSubjects(), static fn( SubjectDTO $s ): bool => $s->hasBank );
	}
}
