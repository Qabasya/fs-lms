<?php

declare( strict_types=1 );

namespace Inc\Controllers\Task;

use Inc\Core\BaseController;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\LegacyTaskImportService;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class LegacyTaskImportPageController
 *
 * Отображение скрытой страницы разового переноса заданий со старой версии
 * сайта. Резолвится контейнером из AdminCallbacks — не сервис, register()
 * не требуется (аналогично BoilerplatePageController).
 *
 * @package Inc\Controllers\Task
 */
class LegacyTaskImportPageController extends BaseController {

	use TemplateRenderer;

	public function __construct(
		private readonly SubjectRepository  $subjects,
		private readonly TaxonomyRepository $taxonomies,
	) {
		parent::__construct();
	}

	/** Главная точка входа (вызывается из AdminCallbacks::legacyTaskImportPage()). */
	public function displayPage(): void {
		$subjects = $this->subjects->readActive();

		$this->render(
			'admin/legacy-task-import',
			array(
				'subjects'   => $subjects,
				'taxonomies' => $this->taxonomyOptions( $subjects ),
				'batch_size' => LegacyTaskImportService::BATCH_SIZE,
			)
		);
	}

	/**
	 * Таксономии предметов для выпадающих списков автора, года и сложности.
	 *
	 * Номер задания исключён: его импорт назначает сам, по полю номера в файле.
	 *
	 * @param \Inc\DTO\Subject\SubjectDTO[] $subjects Активные предметы
	 *
	 * @return array<string, array<int, array{slug: string, name: string}>> Ключ предмета → таксономии
	 */
	private function taxonomyOptions( array $subjects ): array {
		$options = array();

		foreach ( $subjects as $subject ) {
			$numberTaxonomy = PostTypeResolver::getTaskTaxonomy( $subject->key );

			$options[ $subject->key ] = array_values( array_map(
				static fn( $dto ): array => array( 'slug' => $dto->slug, 'name' => $dto->name ),
				array_filter(
					$this->taxonomies->getBySubject( $subject->key ),
					static fn( $dto ): bool => $dto->slug !== $numberTaxonomy
				)
			) );
		}

		return $options;
	}
}
