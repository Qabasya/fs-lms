<?php

namespace Inc\Controllers\Builders;

use Inc\Contracts\MenuBuilderInterface;
use Inc\Controllers\Subject;
use Inc\Core\BaseController;
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
	private SubjectRepository $subjectRepository;

	/**
	 * Коллбеки для рендеринга страниц админ-панели.
	 *
	 * @var Subject
	 */
	private Subject $callbacks;

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
	 * @param SubjectRepository $subjectRepository Репозиторий предметов
	 * @param Subject $callbacks Коллбеки административной панели
	 */
	public function __construct(
		SubjectRepository $subjectRepository,
		Subject $callbacks
	) {
		$this->subjectRepository = $subjectRepository;
		$this->callbacks         = $callbacks;
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
	 *     callback: array{0: Subject, 1: string},
	 *     icon_url: string,
	 *     position: int
	 * }>
	 */
	public function buildPages(): array {
		if ( empty( $this->getSubjects() ) ) {
			return [];
		}
// Это первая страница в Предметы, она удаляется
		return [
			[
				'page_title' => 'Управление предметами',
				'menu_title' => 'Предметы',
				'capability' => BaseController::ADMIN_CAPABILITY,
				'menu_slug'  => BaseController::SUBJECTS_MENU_SLUG,
				'callback'   => [ $this->callbacks, 'subjectsRoot' ],
				'icon_url'   => 'dashicons-category',
				'position'   => 3,
			]
		];
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
 *     callback: array{0: Subject, 1: string}
 * }>
 */
	public function buildSubPages(): array {
		$subpages = [];

		foreach ( $this->getSubjects() as $key => $subject ) {
			$subpages[] = [
				'parent_slug' => BaseController::SUBJECTS_MENU_SLUG,
				'page_title'  => $subject['name'],
				'menu_title'  => $subject['name'],
				'capability'  => BaseController::ADMIN_CAPABILITY,
				'menu_slug'   => 'fs_subject_' . $key,
				'callback'    => [ $this->callbacks, 'subjectPage' ],
			];
		}

		return $subpages;
	}

	/**
	 * Возвращает предметы из репозитория, кэшируя результат.
	 *
	 * @return array
	 */
	private function getSubjects(): array {
		if ( $this->subjects === null ) {
			$this->subjects = $this->subjectRepository->read_all();
		}

		return $this->subjects;
	}
}