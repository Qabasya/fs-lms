<?php

declare( strict_types=1 );

namespace Unit\Services\Template;

use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\Template\TemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Дочернее задание связки 19-21 — обычное `standard_task`: глобальная привязка шаблона к номеру (триплет у №19)
 * его не перебивает, иначе верный ответ на №19 в экзамене не засчитывался.
 */
class TemplateResolverBundleChildTest extends TestCase {

	public function test_bundle_child_keeps_its_own_standard_template(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'getMeta' )->willReturnCallback(
			static fn( int $id, string $key ) => match ( $key ) {
				'fs_lms_task_bundle_parent_id' => '20350',
				'fs_lms_template_type'         => 'standard_task',
				default                        => '',
			}
		);
		// Привязка по номеру не должна даже читаться.
		$metaboxes = $this->createMock( MetaBoxRepository::class );
		$metaboxes->expects( self::never() )->method( 'getAssignment' );

		$post            = new \WP_Post( array( 'ID' => 20351, 'post_type' => 'inf_ege_tasks' ) );
		$resolver        = new TemplateResolver( $metaboxes, $posts );

		self::assertSame( 'standard_task', $resolver->resolveId( $post ) );
	}
}
