<?php

declare(strict_types=1);

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Nonce;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TaxonomySeeder;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class SubjectSettingsCallbacks
 *
 * AJAX-обработчики для CRUD-операций с предметами,
 * а также экспорт/импорт полных данных предмета.
 *
 * @package Inc\Callbacks
 */
class SubjectSettingsCallbacks extends BaseController
{
	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;
	use TemplateRenderer;
	
	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository     $subjects     Репозиторий предметов
	 * @param TaxonomyRepository    $taxonomies   Репозиторий таксономий
	 * @param MetaBoxRepository     $metaboxes    Репозиторий метабоксов (привязка шаблонов)
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TermManager           $terms        Менеджер терминов
	 * @param PostManager           $posts        Менеджер постов
	 */
	public function __construct(
		private SubjectRepository $subjects,
		private TaxonomyRepository $taxonomies,
		private MetaBoxRepository $metaboxes,
		private BoilerplateRepository $boilerplates,
		private TermManager $terms,
		private PostManager $posts,
	) {
		parent::__construct();
	}
	
	
	
	
	

	
	
	
	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //
	

	


	
	
}