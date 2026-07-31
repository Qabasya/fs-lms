<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Managers\Wp\PostManager;
use Inc\Shared\Traits\ScopedFilter;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\PostTypeResolver;

readonly class SubjectDeletionService {

	use ScopedFilter;

	public function __construct(
		private TaxonomyRepository     $taxonomies,
		private MetaBoxRepository      $metaboxes,
		private BoilerplateRepository  $boilerplates,
		private TermManager            $terms,
		private PostManager            $posts,
		private StudentGroupRepository $student_groups,
	) {}

	public function deleteWithCascade( string $subject_key ): void {
		foreach ( $this->taxonomies->getBySubject( $subject_key ) as $tax_dto ) {
			$this->terms->deleteAll( $tax_dto->slug );
		}

		// Номера заданий уходят вместе с предметом: гард удаления занятых номеров
		// (TaskNumberTermGuard) защищает от случайной правки, а не от планового сноса.
		$this->withFilter(
			TaskNumberTermGuard::BYPASS_FILTER,
			'__return_true',
			fn() => $this->terms->deleteAll( "{$subject_key}_task_number" )
		);

		// Полный снос всех банков предмета. Удаление вызывается только для предмета БЕЗ групп
		// (гард в SubjectCrudCallbacks), поэтому референс-гард удаления здесь намеренно обходим —
		// иначе кросс-ссылки внутри предмета (курс→урок→работа→задача) блокировали бы снос.
		$this->withFilter(
			'pre_delete_post',
			static fn( $check ) => null,
			function () use ( $subject_key ): void {
				foreach ( array(
					PostTypeResolver::courses( $subject_key ),
					PostTypeResolver::lessons( $subject_key ),
					PostTypeResolver::works( $subject_key ),
					PostTypeResolver::assessments( $subject_key ),
					PostTypeResolver::tasks( $subject_key ),
					PostTypeResolver::articles( $subject_key ),
				) as $post_type ) {
					$this->posts->deleteAll( $post_type );
				}
			},
			99
		);

		$this->taxonomies->removeBySubject( $subject_key );
		$this->metaboxes->removeBySubject( $subject_key );
		$this->boilerplates->removeBySubject( $subject_key );

		$this->student_groups->removeBySubject( $subject_key );
	}
}
