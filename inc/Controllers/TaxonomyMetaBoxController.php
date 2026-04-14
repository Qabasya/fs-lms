<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Repositories\TaxonomyRepository;
use Inc\DTO\TaxonomyDataDTO;

class TaxonomyMetaBoxController extends BaseController implements ServiceInterface {


	public function __construct(
		private readonly TaxonomyRepository $repository
	) {
		parent::__construct();
	}

	public function register(): void {
		// Хук для добавления/удаления метабоксов
		add_action( 'add_meta_boxes', [ $this, 'manageMetaBoxes' ] );
		// Хук для сохранения данных
		add_action( 'save_post', [ $this, 'saveTaxonomyData' ] );
	}


}