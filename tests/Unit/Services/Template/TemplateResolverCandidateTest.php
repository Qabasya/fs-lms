<?php

declare( strict_types=1 );

namespace Unit\Services\Template;

use Inc\DTO\Task\TaskTemplateAssignmentDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

class TemplateResolverCandidateTest extends TestCase {
	public function test_global_assignment_keeps_priority_over_candidate_own_template(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'getMeta' )->willReturn( '' );
		$assignments = $this->createMock( MetaBoxRepository::class );
		$assignments->method( 'getAssignment' )->with( 'inf', '1' )->willReturn( new TaskTemplateAssignmentDTO( 'inf', '1', TaskTemplate::FileAnswer->value ) );
		$resolver = new TemplateResolver( $assignments, $posts );
		$term = new \WP_Term( 1 );
		$term->slug = '1';
		self::assertSame( TaskTemplate::FileAnswer->value, $resolver->resolveId( new \WP_Post( array( 'ID' => 20, 'post_type' => 'inf_tasks' ) ), array( $term ), array( PostMetaName::TemplateType->value => TaskTemplate::Standard->value ) ) );
	}

	public function test_removing_number_uses_candidate_own_template(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'getMeta' )->willReturn( '' );
		$resolver = new TemplateResolver( $this->createMock( MetaBoxRepository::class ), $posts );
		self::assertSame( TaskTemplate::FileAnswer->value, $resolver->resolveId( new \WP_Post( array( 'ID' => 20, 'post_type' => 'inf_tasks' ) ), array(), array( PostMetaName::TemplateType->value => TaskTemplate::FileAnswer->value ) ) );
	}
}
