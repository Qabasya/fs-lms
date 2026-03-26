<?php

	namespace Inc\Controllers\Builders;

	use Inc\Callbacks\AdminCallbacks;
	use Inc\Contracts\MenuBuilderInterface;
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
	class SubjectsMenuBuilder implements MenuBuilderInterface
	{
		/**
		 * Репозиторий для работы с предметами.
		 *
		 * @var SubjectRepository
		 */
		private SubjectRepository $subjectRepository;

		/**
		 * Коллбеки для рендеринга страниц админ-панели.
		 *
		 * @var AdminCallbacks
		 */
		private AdminCallbacks $callbacks;

		/**
		 * Конструктор.
		 *
		 * @param SubjectRepository $subjectRepository Репозиторий предметов
		 * @param AdminCallbacks    $callbacks         Коллбеки административной панели
		 */
		public function __construct(
			SubjectRepository $subjectRepository,
			AdminCallbacks $callbacks
		) {
			$this->subjectRepository = $subjectRepository;
			$this->callbacks = $callbacks;
		}

		/**
		 * Строит конфигурацию главных страниц меню.
		 *
		 * Возвращает массив с данными для создания родительской страницы
		 * раздела "Предметы" в административном меню.
		 *
		 * Если предметы отсутствуют, возвращает пустой массив —
		 * страница не будет создана.
		 *
		 * @return array<int, array{
		 *     page_title: string,
		 *     menu_title: string,
		 *     capability: string,
		 *     menu_slug: string,
		 *     callback: array{0: AdminCallbacks, 1: string},
		 *     icon_url: string,
		 *     position: int
		 * }> Конфигурация страницы предметов
		 */
		public function buildPages(): array
		{
			$subjects = $this->subjectRepository->read_all();

			if (empty($subjects)) {
				return [];
			}

			return [
				[
					'page_title' => 'Предметы',
					'menu_title' => 'Предметы',
					'capability' => BaseController::ADMIN_CAPABILITY,
					'menu_slug'  => BaseController::SUBJECTS_MENU_SLUG,
					'callback'   => [$this->callbacks, 'subjectsRoot'],
					'icon_url'   => 'dashicons-category',
					'position'   => 5
				]
			];
		}

		/**
		 * Строит конфигурацию подстраниц меню.
		 *
		 * Для каждого предмета из репозитория создаёт подстраницу
		 * с уникальным идентификатором (fs_subject_{key}).
		 *
		 * Если предметы отсутствуют, возвращает пустой массив.
		 *
		 * @return array<int, array{
		 *     parent_slug: string,
		 *     page_title: string,
		 *     menu_title: string,
		 *     capability: string,
		 *     menu_slug: string,
		 *     callback: array{0: AdminCallbacks, 1: string}
		 * }> Конфигурация подстраниц для каждого предмета
		 */
		public function buildSubPages(): array
		{
			$subjects = $this->subjectRepository->read_all();
			$subpages = [];

			foreach ($subjects as $key => $subject) {
				$subpages[] = [
					'parent_slug' => BaseController::SUBJECTS_MENU_SLUG,
					'page_title'  => $subject['name'],
					'menu_title'  => $subject['name'],
					'capability'  => BaseController::ADMIN_CAPABILITY,
					'menu_slug'   => 'fs_subject_' . $key,
					'callback'    => [$this->callbacks, 'subjectPage'],
				];
			}

			return $subpages;
		}
	}