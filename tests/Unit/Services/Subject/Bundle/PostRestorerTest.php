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

		$restorer = new PostRestorer( $posts, $this->createMock( TermManager::class ), new \Inc\Repositories\OptionsRepositories\SubjectRepository() );

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

	public function test_imported_course_is_disabled_and_foreign_group_is_removed(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'insert' )->willReturn( 42 );
		$subjects = $this->createMock( \Inc\Repositories\OptionsRepositories\SubjectRepository::class );
		$subjects->method( 'getByKey' )->willReturnCallback( static fn ( string $key ) => 'inf' === $key
			? \Inc\DTO\Subject\SubjectDTO::fromArray( array( 'key' => 'inf', 'name' => 'Информатика' ) ) : null );
		$written = array();
		$posts->method( 'updateMeta' )->willReturnCallback( static function ( int $id, string $key, mixed $value ) use ( &$written ): bool {
			$written[ $key ] = $value;
			return true;
		} );
		$restorer = new PostRestorer( $posts, $this->createMock( TermManager::class ), $subjects );
		$restorer->restore( 'inf_courses', array( 'meta' => array(
			\Inc\Enums\Wp\PostMetaName::CourseSelfPacedEnabled->value => true,
			\Inc\Enums\Wp\PostMetaName::CourseServiceGroupId->value => 123,
			\Inc\Enums\Wp\PostMetaName::CourseAudienceSubjectKeys->value => array( 'inf', 'gone', 'inf' ),
		) ), new ImportedEntitiesCollector() );
		self::assertFalse( $written[ \Inc\Enums\Wp\PostMetaName::CourseSelfPacedEnabled->value ] );
		self::assertSame( 0, $written[ \Inc\Enums\Wp\PostMetaName::CourseServiceGroupId->value ] );
		self::assertSame( array( 'inf' ), $written[ \Inc\Enums\Wp\PostMetaName::CourseAudienceSubjectKeys->value ] );
	}
}
