<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Registrars\PluginRegistrar;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\TemplateRenderer;
use Inc\Shared\Traits\NumericSorter;
use Inc\Services\TaxonomySeeder;

/**
 * Class SubjectController
 *
 * Контроллер для управления предметами и связанными с ними CPT.
 *
 * Отвечает за:
 * - Динамическую регистрацию CPT (задания и статьи) для каждого предмета
 * - Отображение страницы управления конкретным предметом с навигацией
 *   к связанным типам записей
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * @method void render( string $template, array $data = [] ) — трейт TemplateRenderer
 */
class SubjectController extends BaseController implements ServiceInterface {
	use TemplateRenderer;
	use NumericSorter;

	protected SubjectRepository $subjects;
	protected TaxonomyRepository $taxonomies; // Свойство называется так
	private PluginRegistrar $registrar;
	private TaxonomySeeder $seeder;

	public function __construct(
		SubjectRepository $subjects,
		PluginRegistrar $registrar,
		TaxonomySeeder $seeder,
		TaxonomyRepository $taxonomies // Имя аргумента для ясности
	) {
		parent::__construct();
		$this->subjects     = $subjects;
		$this->registrar    = $registrar;
		$this->seeder       = $seeder;
		$this->taxonomies = $taxonomies; // Исправлено: записываем в правильное свойство
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * Вызывается один раз при инициализации плагина (в Init.php).
	 * Для каждого предмета из репозитория создаёт два типа записей:
	 * - {key}_tasks — задания
	 * - {key}_articles — статьи
	 *
	 * @return void
	 */
	public function register(): void {
		// Сортировка номеров заданий. Ключом сортировки является НАЗВАНИЕ (name)
		$this->addNumericSort(
			'get_terms_orderby',
			't.name',
			function ( $args ) {
				$tax = (array) ( $args['taxonomy'] ?? [] );

				return str_contains( reset( $tax ), '_task_number' );
			}
		);

		$all_subjects = $this->subjects->read_all();

		if ( empty( $all_subjects ) ) {
			return;
		}

		foreach ( $all_subjects as $key => $data ) {
			$name        = $data['name'];
			$task_cpt    = "{$key}_tasks";
			$article_cpt = "{$key}_articles";

			$this->registrar->cpt()
			                ->addStandardType( $task_cpt, "Задания ($name)", "Задание" )
			                ->addStandardType( $article_cpt, "Статьи ($name)", "Статья" );

			$fixed_tax_slug = "{$key}_task_number";
			$this->registrar->taxonomy()
			                ->addFixedTaxonomy( $fixed_tax_slug, [ $task_cpt ], "Номера заданий ($name)", "Номер задания" );

			// Используем $this->taxonomies, которое мы корректно заполнили в конструкторе
			$custom_taxes = $this->taxonomies->get_by_subject( $key );
			foreach ( $custom_taxes as $tax_slug => $tax_data ) {
				$this->registrar->taxonomy()
				                ->addStandardTaxonomy( $tax_slug, [
					                $task_cpt,
					                $article_cpt
				                ], $tax_data['name'], $tax_data['name'] );
			}

			add_action( 'init', function () use ( $fixed_tax_slug, $data ) {
				$count = $data['tasks_count'] ?? 27;
				$this->seeder->seedTaskNumbers( $fixed_tax_slug, $count );
			}, 20 );
		}

		$this->registrar->cpt()->register();
		$this->registrar->taxonomy()->register();
	}

	public function subjectPage(): void {
		$page = $_GET['page'] ?? '';
		$key  = str_replace( 'fs_subject_', '', $page );

		$all_subjects    = $this->subjects->read_all();
		$current_subject = $all_subjects[ $key ] ?? null;

		if ( ! $current_subject ) {
			echo "Предмет не найден";

			return;
		}

		$custom_taxes = $this->taxonomies->get_by_subject( $key );

		$data = [
			'subject_key'   => $key,
			'subject'       => $current_subject,
			'tasks_url'     => admin_url( "edit.php?post_type={$key}_tasks" ),
			'articles_url'  => admin_url( "edit.php?post_type={$key}_articles" ),
			'protected_tax' => "{$key}_task_number",
			'taxonomies'    => array_merge(
				[ "{$key}_task_number" => [ 'name' => "Номера заданий ({$current_subject['name']})" ] ],
				$custom_taxes
			)
		];

		$this->render( 'SubjectTest', $data );
	}
}