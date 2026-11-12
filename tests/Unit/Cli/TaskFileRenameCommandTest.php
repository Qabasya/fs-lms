<?php

declare( strict_types=1 );

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			public static array $lines = array();
			public static function line( string $message ): void { self::$lines[] = $message; }
			public static function warning( string $message ): void { self::$lines[] = $message; }
			public static function error( string $message ): never { throw new \RuntimeException( $message ); }
		}
	}
}

namespace Unit\Cli {
	use Inc\Cli\TaskFileRenameCommand;
	use Inc\DTO\Subject\SubjectDTO;
	use Inc\Managers\Wp\PostManager;
	use Inc\Repositories\OptionsRepositories\SubjectRepository;
	use Inc\Repositories\WPDBRepositories\TaskFileNormalizationRepository;
	use Inc\Services\Task\TaskFileNormalizationService;
	use PHPUnit\Framework\TestCase;

	class TaskFileRenameCommandTest extends TestCase {
		public function test_dry_run_reports_files_without_applying_changes(): void {
			$normalizer = $this->createMock( TaskFileNormalizationService::class );
			$normalizer->expects( self::once() )->method( 'preview' )->with( 42 )->willReturn( array( 'ops' => array( array( 'key' => 'file', 'from' => '/old', 'to' => '/1000.txt', 'old_url' => 'https://site.example/old.txt', 'new_url' => 'https://site.example/1000.txt' ) ), 'warnings' => array() ) );
			$normalizer->expects( self::never() )->method( 'apply' );
			$subjects = $this->createStub( SubjectRepository::class );
			$subjects->method( 'readAll' )->willReturn( array( new SubjectDTO( 'inf', 'Информатика' ) ) );
			$posts = $this->createStub( PostManager::class );
			$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 42, 'post_type' => 'inf_tasks', 'post_status' => 'publish' ) ) );
			\WP_CLI::$lines = array();
			( new TaskFileRenameCommand( $normalizer, $this->createStub( TaskFileNormalizationRepository::class ), $subjects, $posts ) )->run( array(), array( 'dry-run' => true, 'post-id' => '42' ) );
			self::assertStringContainsString( '/old → /1000.txt', implode( "\n", \WP_CLI::$lines ) );
		}

		public function test_explicit_private_task_is_rejected(): void {
			$normalizer = $this->createMock( TaskFileNormalizationService::class );
			$normalizer->expects( self::never() )->method( 'preview' );
			$subjects = $this->createStub( SubjectRepository::class );
			$subjects->method( 'readAll' )->willReturn( array( new SubjectDTO( 'inf', 'Информатика' ) ) );
			$posts = $this->createStub( PostManager::class );
			$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 42, 'post_type' => 'fs_lms_problems', 'post_status' => 'publish' ) ) );
			$this->expectException( \RuntimeException::class );
			( new TaskFileRenameCommand( $normalizer, $this->createStub( TaskFileNormalizationRepository::class ), $subjects, $posts ) )->run( array(), array( 'post-id' => '42' ) );
		}
	}
}
