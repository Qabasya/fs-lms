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

	/** Копирует файлы и переключает ссылки, только если мета не изменилась. */
	public function apply( int $postId ): array {
		$before = $this->posts->getMeta( $postId, PostMetaName::Meta->value );
		$before = is_array( $before ) ? $before : array();
		$plan = $this->preview( $postId );
		$ops = $plan['ops'];
		if ( ! $ops ) {
			return array( 'updated' => 0, 'warnings' => $plan['warnings'] );
		}
		$after = $before;
		foreach ( $ops as $op ) {
			foreach ( $op['keys'] ?? array( $op['key'] ) as $key ) {
				if ( in_array( $key, TaskBulkFileService::KEYS, true ) ) {
					$after[ $key ] = $op['new_url'];
				}
			}
		}
		$this->files->execute( $ops );
		$created = array();
		try {
			foreach ( $ops as $op ) {
				if ( empty( $op['material_ids'] ) ) {
					continue;
				}
				$id = $this->media->createAttachmentForFile( $op['to'], $postId );
				$created[] = $id;
				foreach ( $after['task_materials']['attachment_ids'] ?? array() as $index => $oldId ) {
					if ( in_array( (int) $oldId, $op['material_ids'], true ) ) {
						$after['task_materials']['attachment_ids'][ $index ] = $id;
					}
				}
			}
			if ( false === $this->posts->updateMetaChecked( $postId, PostMetaName::Meta->value, $after, $before ) ) {
				throw new \RuntimeException( 'Задание изменилось во время обработки файлов.' );
			}
		} catch ( \Throwable $error ) {
			foreach ( $created as $id ) {
				$this->media->delete( $id );
			}
			$this->files->revert( $ops );
			throw $error;
		}
		return array( 'updated' => count( $ops ), 'warnings' => $plan['warnings'] );
	}

	/**
	 * Строит план без записи в базу и файловую систему.
	 *
	 * @return array{ops: array<int, array<string, mixed>>, warnings: string[], materials?: array<int, array<string, mixed>>, sources?: array<int, array<string, mixed>>}
	 */
	public function preview( int $postId, ?string $targetSlug = null ): array {
		$post = $this->posts->get( $postId );
		if ( null === $post || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return array( 'ops' => array(), 'warnings' => array( 'Это не публичное задание.' ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return array( 'ops' => array(), 'warnings' => array( 'Задание не опубликовано.' ) );
		}
		$slug = $targetSlug ?? $post->post_name;
		if ( ! preg_match( '/^[1-9][0-9]*$/D', $slug ) ) {
			return array( 'ops' => array(), 'warnings' => array( 'Номер задания не является положительным целым числом.' ) );
		}

		$meta = $this->posts->getMeta( $postId, PostMetaName::Meta->value );
		$meta = is_array( $meta ) ? $meta : array();
		$subject = PostTypeResolver::subjectFromTaskPostType( $post->post_type );
		$materialWarnings = array();
		$materials = $this->inspectMaterials( $meta, $materialWarnings );
		$extended = $meta;
		$keys = TaskBulkFileService::KEYS;
		foreach ( $materials as $material ) {
			$key = '__material_' . $material['attachment_id'];
			$keys[] = $key;
			$extended[ $key ] = $material['url'];
		}
		$plan = $materials
			? $this->files->plan( $extended, $slug, $postId, true, $subject ?? '', $keys )
			: $this->files->plan( $meta, $slug, $postId, true, $subject ?? '' );
		$plan['warnings'] = array_merge( $plan['warnings'], $materialWarnings );
		$plan['materials'] = $materials;
		foreach ( $plan['ops'] as &$op ) {
			foreach ( $op['keys'] ?? array() as $key ) {
				if ( str_starts_with( $key, '__material_' ) ) {
					$op['material_ids'][] = (int) substr( $key, strlen( '__material_' ) );
				}
			}
		}
		unset( $op );
		$plan['sources'] = $this->collectSources( $meta, $plan['materials'], $plan['warnings'] );
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
		return $materials;
	}
}
