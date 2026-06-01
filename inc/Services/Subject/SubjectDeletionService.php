<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\PostTypeResolver;

class SubjectDeletionService {

	public function __construct(
		private readonly TaxonomyRepository    $taxonomies,
		private readonly MetaBoxRepository     $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager           $terms,
		private readonly PostManager           $posts,
		private readonly StudentGroupRepository $student_groups,
	) {}

	public function deleteWithCascade( string $subject_key ): void {
		foreach ( $this->taxonomies->getBySubject( $subject_key ) as $tax_dto ) {
			$this->terms->deleteAll( $tax_dto->slug );
		}

		$this->terms->deleteAll( "{$subject_key}_task_number" );

		foreach ( array( PostTypeResolver::tasks( $subject_key ), PostTypeResolver::articles( $subject_key ) ) as $post_type ) {
			$this->posts->deleteAll( $post_type );
		}

		$this->taxonomies->removeBySubject( $subject_key );
		$this->metaboxes->removeBySubject( $subject_key );
		$this->boilerplates->removeBySubject( $subject_key );
		$this->student_groups->removeBySubject( $subject_key );
	}
}
