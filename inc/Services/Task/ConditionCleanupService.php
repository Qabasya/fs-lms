<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\PluginLogger;

/**
 * Class ConditionCleanupService
 *
 * Разовая чистка сохранённых условий заданий: пустые строки по краям и
 * невидимый мусор ({@see ConditionHtmlNormalizer}).
 *
 * @package Inc\Services\Task
 *
 * Вывод условия и так прогоняется через нормализатор, а новое сохранение не
 * пропускает мусор в базу. Сервис правит то, что уже лежит в мете: оно
 * уезжает в экспорт, в пакет переноса предмета и в редактор задания.
 * Запускается WP-CLI командой `wp fs-lms task clean-conditions`.
 */
class ConditionCleanupService {

	public function __construct(
		private readonly PostManager             $posts,
		private readonly SubjectRepository       $subjects,
		private readonly ConditionHtmlNormalizer $normalizer,
	) {}

	/**
	 * @param bool $dryRun true — только посчитать, ничего не записывая
	 *
	 * @return array{scanned: int, updated: int, fields: int} Сколько заданий
	 *         просмотрено, сколько обновлено и сколько полей условия поправлено
	 */
	public function run( bool $dryRun = false ): array {
		$scanned = 0;
		$updated = 0;
		$fields  = 0;

		foreach ( $this->subjects->readAll() as $subject ) {
			foreach ( $this->posts->getIds( PostTypeResolver::tasks( $subject->key ) ) as $postId ) {
				++$scanned;

				$meta = $this->posts->taskMeta( (int) $postId );
				if ( array() === $meta ) {
					continue;
				}

				$changed = 0;
				foreach ( $meta as $key => $value ) {
					if ( ! is_string( $value ) || ! preg_match( '/_condition(?:_\d+)?$/', (string) $key ) ) {
						continue;
					}

					$clean = $this->normalizer->normalize( $value );
					if ( $clean !== $value ) {
						$meta[ $key ] = $clean;
						++$changed;
					}
				}

				if ( 0 === $changed ) {
					continue;
				}

				++$updated;
				$fields += $changed;

				if ( ! $dryRun ) {
					$this->posts->updateMeta( (int) $postId, PostMetaName::Meta->value, $meta );
				}
			}
		}

		if ( $updated > 0 && ! $dryRun ) {
			PluginLogger::warning(
				'ConditionCleanup',
				'Условия заданий очищены от пустых строк по краям',
				array( 'posts' => $updated, 'fields' => $fields )
			);
		}

		return array( 'scanned' => $scanned, 'updated' => $updated, 'fields' => $fields );
	}
}
