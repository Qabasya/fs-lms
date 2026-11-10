<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\MediaManager;
use Inc\Services\Subject\PostTypeResolver;

/** Предварительная проверка файлов опубликованного публичного задания. */
class TaskFileNormalizationService {
	public function __construct(
		private readonly PostManager $posts,
		private readonly TaskBulkFileService $files,
		private readonly MediaManager $media,
	) {}

	/**
	 * Строит план без записи в базу и файловую систему.
	 *
	 * @return array{ops: array<int, array<string, mixed>>, warnings: string[], materials?: array<int, array<string, mixed>>, sources?: array<int, array<string, mixed>>}
	 */
	public function preview( int $postId ): array {
		$post = $this->posts->get( $postId );
		if ( null === $post || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return array( 'ops' => array(), 'warnings' => array( 'Это не публичное задание.' ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return array( 'ops' => array(), 'warnings' => array( 'Задание не опубликовано.' ) );
		}
		if ( ! preg_match( '/^[1-9][0-9]*$/D', $post->post_name ) ) {
			return array( 'ops' => array(), 'warnings' => array( 'Номер задания не является положительным целым числом.' ) );
		}

		$meta = $this->posts->getMeta( $postId, PostMetaName::Meta->value );
		$meta = is_array( $meta ) ? $meta : array();
		$subject = PostTypeResolver::subjectFromTaskPostType( $post->post_type );
		$plan = $this->files->plan( $meta, $post->post_name, $postId, true, $subject ?? '' );
		$plan['materials'] = $this->inspectMaterials( $meta, $plan['warnings'] );
		$plan['sources'] = $this->collectSources( $meta, $plan['materials'], $plan['warnings'] );
		foreach ( $plan['sources'] as $source ) {
			if ( count( $source['references'] ) > 1 ) {
				$plan['warnings'][] = 'Один файл используется в нескольких полях или вложениях; совместная обработка ещё не поддерживается.';
			}
		}
		return $plan;
	}

	/**
	 * @param array<string, mixed> $meta Мета задания.
	 * @param array<int, array<string, mixed>> $materials Проверенные материалы.
	 * @param string[] $warnings Предупреждения отчёта.
	 * @return array<int, array<string, mixed>>
	 */
	private function collectSources( array $meta, array $materials, array &$warnings ): array {
		$sources = array();
		foreach ( TaskBulkFileService::KEYS as $key ) {
			$url = $meta[ $key ] ?? null;
			$path = is_string( $url ) ? $this->files->inspectLocalUrl( $url ) : null;
			if ( null !== $path ) {
				$this->addSource( $sources, $path, array( 'field' => $key ), $warnings );
			}
		}
		foreach ( $materials as $material ) {
			$this->addSource( $sources, $material['path'], array( 'attachment_id' => $material['attachment_id'] ), $warnings );
		}
		return array_values( $sources );
	}

	/** @param array<string, array<string, mixed>> $sources @param array<string, mixed> $reference @param string[] $warnings */
	private function addSource( array &$sources, string $path, array $reference, array &$warnings ): void {
		$stat = @stat( $path );
		if ( false !== $stat && isset( $stat['dev'], $stat['ino'] ) && $stat['ino'] > 0 ) {
			$key = 'inode:' . $stat['dev'] . ':' . $stat['ino'];
		} else {
			$key = 'path:' . ( realpath( $path ) ?: $path );
			$warnings[] = 'Не удалось определить идентификатор файла; жёсткие ссылки могут не распознаться.';
		}
		if ( ! isset( $sources[ $key ] ) ) {
			$sources[ $key ] = array( 'path' => $path, 'references' => array() );
		}
		$sources[ $key ]['references'][] = $reference;
	}

	/**
	 * @param array<string, mixed> $meta Мета задания.
	 * @param string[] $warnings Предупреждения отчёта.
	 * @return array<int, array<string, mixed>>
	 */
	private function inspectMaterials( array $meta, array &$warnings ): array {
		$ids = $meta['task_materials']['attachment_ids'] ?? array();
		if ( ! is_array( $ids ) ) {
			$warnings[] = 'Список вложений материалов повреждён.';
			return array();
		}
		$materials = array();
		$seen = array();
		foreach ( $ids as $rawId ) {
			if ( ! is_int( $rawId ) && ! ( is_string( $rawId ) && ctype_digit( $rawId ) ) ) {
				$warnings[] = 'Некорректный ID вложения материала.';
				continue;
			}
			$id = (int) $rawId;
			if ( $id < 1 ) {
				$warnings[] = 'Некорректный ID вложения материала.';
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$description = $this->media->describe( $id );
			$path = null === $description ? null : $this->files->inspectLocalUrl( $description['url'] );
			if ( null === $description || null === $path ) {
				$warnings[] = "Вложение материала #{$id} недоступно в локальном хранилище — пропущено.";
				continue;
			}
			$materials[] = array( 'attachment_id' => $id, 'url' => $description['url'], 'name' => $description['name'], 'path' => $path );
		}
		if ( array() !== $materials ) {
			$warnings[] = 'Переименование материалов по ID вложений пока не поддерживается.';
		}
		return $materials;
	}
}
