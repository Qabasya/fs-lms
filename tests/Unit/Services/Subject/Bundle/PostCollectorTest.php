<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Subject\Bundle\PostCollector;
use PHPUnit\Framework\TestCase;

class PostCollectorTest extends TestCase {

	public function test_local_service_group_id_is_never_exported(): void {
		$posts = $this->createMock( PostManager::class );
		$post = new class( array(
			'ID' => 7, 'post_type' => 'inf_courses', 'post_status' => 'publish',
			'post_title' => 'Курс', 'post_name' => 'course', 'post_content' => '',
			'post_excerpt' => '', 'post_date' => '', 'menu_order' => 0,
		) ) extends \WP_Post { public string $post_date = ''; };
		$posts->method( 'getAll' )->willReturn( array( $post ) );
		$posts->method( 'getMeta' )->willReturn( 0 );
		$posts->method( 'getAllMeta' )->willReturn( array(
			PostMetaName::CourseServiceGroupId->value => 123,
			PostMetaName::CourseAudienceSubjectKeys->value => array( 'inf' ),
		) );
		$collector = new PostCollector( $posts, $this->createMock( TermManager::class ) );
		$result = $collector->collect( 'inf_courses' );
		self::assertArrayNotHasKey( PostMetaName::CourseServiceGroupId->value, $result[0]['meta'] );
		self::assertSame( array( 'inf' ), $result[0]['meta'][ PostMetaName::CourseAudienceSubjectKeys->value ] );
	}
}
