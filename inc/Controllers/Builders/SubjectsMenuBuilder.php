<?php

namespace Inc\Controllers\Builders;

use Inc\Callbacks\SubjectPageCallbacks;
use Inc\Contracts\MenuBuilderInterface;
use Inc\Enums\Capability;
use Inc\Enums\MenuSlug;
use Inc\Repositories\SubjectRepository;

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
 * @implements MenuBuilderInterface
 *
 * @example
 * $builder = new SubjectsMenuBuilder($repository, $callbacks);
 * $pages = $builder->buildPages();     // Главная страница предметов
 * $subpages = $builder->buildSubPages(); // Подстраницы для каждого предмета
 */
class SubjectsMenuBuilder implements MenuBuilderInterface {
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
		if ( empty( $this->getSubjects() ) ) {
			return array();
		}

		// Это первая страница в Предметы, она удаляется
		return array(
			array(
				'page_title' => 'Управление предметами',
				'menu_title' => 'Предметы',
				'capability' => Capability::ADMIN->value,
				'menu_slug'  => MenuSlug::SUBJECTS->value,
				'callback'   => array( $this->callbacks, 'subjectsRoot' ),
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

		foreach ( $this->getSubjects() as $subject ) {
			$subpages[] = array(
				'parent_slug' => MenuSlug::SUBJECTS->value,
				'page_title'  => $subject->name, // Используем -> вместо ['name']
				'menu_title'  => $subject->name,
				'capability'  => Capability::ADMIN->value,
				'menu_slug'   => 'fs_subject_' . $subject->key, // Используем свойство key
				'callback'    => array( $this->callbacks, 'subjectPage' ),
			);
		}

		return $subpages;
	}

	/**
	 * Возвращает предметы из репозитория, кэшируя результат.
	 *
	 * @return \Inc\DTO\SubjectDTO[]
	 */
	private function getSubjects(): array {
		if ( $this->subjects === null ) {
			$this->subjects = $this->subject_repository->readAll();
		}

		return $this->subjects;
	}
}
