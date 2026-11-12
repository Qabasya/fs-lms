<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\TaskFileNormalizationRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskFileNormalizationService;
use WP_CLI;

/** Ручная пакетная нормализация файлов опубликованных публичных заданий. */
class TaskFileRenameCommand implements ServiceInterface {
	public function __construct(
		private readonly TaskFileNormalizationService $normalizer,
		private readonly TaskFileNormalizationRepository $repository,
		private readonly SubjectRepository $subjects,
		private readonly PostManager $posts,
	) {}

	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'fs-lms task rename-files', array( $this, 'run' ) );
		}
	}

	/**
	 * ## OPTIONS
	 * [--dry-run]
	 * : Показать план без изменения файлов и данных.
	 * [--subject=<key>]
	 * : Ограничить предметом.
	 * [--post-id=<id>]
	 * : Обработать одно публичное задание.
	 * [--batch-size=<count>]
	 * : Размер пакета, от 1 до 1000; по умолчанию 100.
	 * [--after-id=<id>]
	 * : Продолжить после указанного ID.
	 *
	 * ## EXAMPLES
	 *     wp fs-lms task rename-files --dry-run
	 *     wp fs-lms task rename-files --subject=inf --dry-run
	 */
	public function run( array $args, array $options ): void {
		$known = array_map( static fn( $subject ): string => $subject->key, $this->subjects->readAll() );
		$subject = (string) ( $options['subject'] ?? '' );
		if ( '' !== $subject && ! in_array( $subject, $known, true ) ) {
			WP_CLI::error( 'Предмет не найден.' );
		}
		$types = array_map( PostTypeResolver::tasks( ... ), '' !== $subject ? array( $subject ) : $known );
		$batch = filter_var( $options['batch-size'] ?? 100, FILTER_VALIDATE_INT );
		$after = filter_var( $options['after-id'] ?? 0, FILTER_VALIDATE_INT );
		$postId = filter_var( $options['post-id'] ?? 0, FILTER_VALIDATE_INT );
		if ( false === $batch || $batch < 1 || $batch > 1000 || false === $after || $after < 0 || false === $postId || $postId < 0 ) {
			WP_CLI::error( 'Некорректный размер пакета или ID.' );
		}
		if ( $postId ) {
			$post = $this->posts->get( $postId );
			if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, $types, true ) ) {
				WP_CLI::error( 'Указано неопубликованное или не публичное задание выбранного предмета.' );
			}
		}
		$dry = isset( $options['dry-run'] );
		$scanned = 0;
		$changed = 0;
		$errors = 0;
		$skipped = 0;
		do {
			$ids = $postId ? array( $postId ) : $this->repository->publishedIds( $types, $after, $batch );
			foreach ( $ids as $id ) {
				$after = $id;
				++$scanned;
				try {
					$plan = $this->normalizer->preview( $id );
					foreach ( $plan['ops'] as $op ) {
						WP_CLI::line( sprintf( '#%d: %s → %s (копия), поля: %s, вложения: %s, URL: %s → %s', $id, $op['from'], $op['to'], implode( ',', $op['keys'] ?? array( $op['key'] ) ), implode( ',', $op['material_ids'] ?? array() ), $op['old_url'], $op['new_url'] ) );
					}
					foreach ( $plan['warnings'] as $warning ) {
						WP_CLI::warning( sprintf( '#%d: %s', $id, $warning ) );
						++$skipped;
						if ( str_contains( $warning, 'уже существует' ) || str_contains( $warning, 'не найден' ) || str_contains( $warning, 'поврежд' ) ) {
							++$errors;
						}
					}
					$changed += $dry ? count( $plan['ops'] ) : $this->normalizer->apply( $id )['updated'];
				} catch ( \Throwable $error ) {
					++$errors;
					WP_CLI::warning( sprintf( '#%d: %s', $id, $error->getMessage() ) );
				}
			}
		} while ( ! $postId && count( $ids ) === $batch );
		WP_CLI::line( sprintf( '%s: заданий %d, файлов %d, пропусков %d, ошибок %d; последний ID %d.', $dry ? 'Предпросмотр' : 'Обработано', $scanned, $changed, $skipped, $errors, $after ) );
		if ( $errors ) {
			WP_CLI::error( 'Некоторые задания не обработаны.' );
		}
	}
}
