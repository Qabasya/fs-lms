<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Subject\Bundle\PostRestorer;
use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use PHPUnit\Framework\TestCase;

/**
 * Восстановление записи из представления пакета.
 */
class PostRestorerTest extends TestCase {

	/**
	 * Слаг задания — это его номер, а не производная заголовка: без переноса
	 * post_name WP пересобирает слаг из «№ 27000. ЕГКР 18.04.26».
	 */
	public function test_restores_post_name_from_snapshot(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( $this->once() )
			->method( 'insert' )
			->with( $this->callback(
				static fn( array $args ): bool => '27000' === $args['post_name']
					&& '№ 27000. ЕГКР 18.04.26' === $args['post_title']
			) )
			->willReturn( 42 );

		$restorer = new PostRestorer( $posts, $this->createMock( TermManager::class ) );

		$id = $restorer->restore(
			'inf_ege_tasks',
			array(
				'post_title' => '№ 27000. ЕГКР 18.04.26',
				'post_name'  => '27000',
			),
			new ImportedEntitiesCollector()
		);

		$this->assertSame( 42, $id );
	}
}
