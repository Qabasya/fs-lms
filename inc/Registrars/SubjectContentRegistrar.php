<?php

declare( strict_types=1 );

namespace Inc\Registrars;

use Inc\Controllers\Builders\SubjectCptArgsBuilder;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class SubjectContentRegistrar
 *
 * Ставит в очередь и регистрирует весь контент предметов: CPT банков
 * (задания, статьи, уроки, работы, курсы, экзамены), фиксированную таксономию
 * «Номера заданий» и пользовательские таксономии.
 *
 * @package Inc\Registrars
 *
 * Знает, ЧТО регистрируется для предмета; КАК — {@see SubjectCPTRegistrar} и
 * {@see SubjectTaxonomyRegistrar}, с какими аргументами — {@see SubjectCptArgsBuilder}.
 * Хуки не вешает: список task-CPT, у которых надо скрыть метабокс номеров,
 * возвращается вызывающему контроллеру.
 */
class SubjectContentRegistrar {

	/** Банки, которые есть у любого предмета (даже без собственного банка заданий). */
	private const COMMON_BANKS = array(
		'lessons'     => 'Уроки',
		'works'       => 'Работы',
		'courses'     => 'Курсы',
		'assessments' => 'Экзамены',
	);

	/**
	 * @param SubjectCPTRegistrar      $cpt        Очередь регистрации CPT
	 * @param SubjectTaxonomyRegistrar $taxRegistrar Очередь регистрации таксономий
	 * @param TaxonomyRepository       $taxonomies Пользовательские таксономии предмета
	 * @param SubjectCptArgsBuilder    $args       Конфигурация CPT
	 */
	public function __construct(
		private readonly SubjectCPTRegistrar      $cpt,
		private readonly SubjectTaxonomyRegistrar $taxRegistrar,
		private readonly TaxonomyRepository       $taxonomies,
		private readonly SubjectCptArgsBuilder    $args,
	) {}

	/**
	 * Регистрирует контент всех предметов.
	 *
	 * @param object[] $subjects DTO предметов (key, name, hasBank)
	 *
	 * @return string[] Слаги task-CPT предметов с банком — для скрытия метабокса
	 *                  фиксированной таксономии на экране задания
	 */
	public function registerAll( array $subjects ): array {
		if ( empty( $subjects ) ) {
			return array();
		}

		$taskPostTypes = array();
		foreach ( $subjects as $subject ) {
			$taskCpt = $this->registerSubject( $subject );
			if ( null !== $taskCpt ) {
				$taskPostTypes[] = $taskCpt;
			}
		}

		$this->cpt->register();
		$this->taxRegistrar->register();

		return $taskPostTypes;
	}

	/**
	 * Ставит в очередь CPT и таксономии одного предмета.
	 *
	 * @param object $subject DTO предмета
	 *
	 * @return string|null Слаг task-CPT, если у предмета есть банк заданий
	 */
	private function registerSubject( object $subject ): ?string {
		$key = $subject->key;

		$this->addType( PostTypeResolver::lessons( $key ), 'lessons', self::COMMON_BANKS['lessons'], $subject );
		$this->addType( PostTypeResolver::works( $key ), 'works', self::COMMON_BANKS['works'], $subject );
		$this->addType( PostTypeResolver::courses( $key ), 'courses', self::COMMON_BANKS['courses'], $subject );
		$this->addType( PostTypeResolver::assessments( $key ), 'assessments', self::COMMON_BANKS['assessments'], $subject );

		// Эпик 18: предмет без собственного банка заданий/статей (tasks_count=0 при
		// создании, D18.1) — CPT tasks/articles и таксономия «Номера заданий» ему не
		// нужны. Уроки/работы/курсы/контрольные регистрируются как обычно выше —
		// такой предмет всё ещё может иметь лекционные/видео-курсы и группы.
		if ( ! $subject->hasBank ) {
			return null;
		}

		$taskCpt    = PostTypeResolver::tasks( $key );
		$articleCpt = PostTypeResolver::articles( $key );

		$this->addType( $taskCpt, 'tasks', 'Задания', $subject );
		$this->addType( $articleCpt, 'articles', 'Статьи', $subject );

		$this->addTaskNumberTaxonomy( $key, $taskCpt, $articleCpt );
		$this->addCustomTaxonomies( $key );

		return $taskCpt;
	}

	/**
	 * Ставит один CPT в очередь регистрации.
	 *
	 * @param string $postType Слаг типа записи
	 * @param string $type     Тип банка для конфигурации
	 * @param string $label    Заголовок раздела
	 * @param object $subject  DTO предмета
	 *
	 * @return void
	 */
	private function addType( string $postType, string $type, string $label, object $subject ): void {
		$args = $this->args->build( $type, $subject );

		$this->cpt->addStandardType( $postType, $label, $args['labels'], $args['options'] );
	}

	/**
	 * Фиксированная таксономия «Номера заданий» предмета.
	 *
	 * @param string $key        Ключ предмета
	 * @param string $taskCpt    CPT заданий
	 * @param string $articleCpt CPT статей
	 *
	 * @return void
	 */
	private function addTaskNumberTaxonomy( string $key, string $taskCpt, string $articleCpt ): void {
		$slug = "{$key}_task_number";

		$this->taxRegistrar->addFixedTaxonomy(
			$slug,
			array( $taskCpt, $articleCpt ),
			'Номера заданий',
			'Номер задания',
			array(
				'public'       => true,
				'show_ui'      => true,
				// buildMetaBoxCallback() — создаёт коллбек для отображения метабокса
				'meta_box_cb'  => $this->taxRegistrar->buildMetaBoxCallback( 'select' ),
				'show_in_menu' => true,
				'rewrite'      => array( 'slug' => $slug ),
			)
		);
	}

	/**
	 * Пользовательские таксономии предмета.
	 *
	 * Задания получают все таксономии предмета; статьи — только те, у которых
	 * включён флаг «Использовать в статьях» ({@see TaxonomyDataDTO::postTypes()}).
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function addCustomTaxonomies( string $key ): void {
		foreach ( $this->taxonomies->getBySubject( $key ) as $tax ) {
			$this->taxRegistrar->addStandardTaxonomy(
				$tax->slug,
				$tax->postTypes(),
				$tax->name,
				$tax->name,
				$tax->display_type
			);
		}
	}
}
