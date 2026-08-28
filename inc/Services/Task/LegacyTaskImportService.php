<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Subject\TaskManager;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;

/**
 * Class LegacyTaskImportService
 *
 * Разовый перенос заданий со старой версии сайта (WXR-экспорт, разобранный
 * заранее в `.docs/legacy_tasks_import.json`, см. описание формата там же).
 *
 * @package Inc\Services\Task
 *
 * Каждая запись создаётся черновиком через {@see TaskManager::createNewTask()} —
 * так задание получает тот же номер и тот же шаблон (по назначению boilerplate
 * для номера), что и при обычном создании через админку. Порядок записей в
 * файле — порядок присвоения номеров: JSON уже отсортирован по
 * (номер ЕГЭ, исходный порядковый номер), поэтому обработка по возрастанию
 * offset воспроизводит его без пропусков от удалённых на старом сайте постов.
 *
 * Батчинг (importBatch с offset/limit) — вместо разбора всего файла одним
 * запросом: 646 записей с HTML-условиями почти наверняка упрутся в
 * max_execution_time дешёвого хостинга.
 */
class LegacyTaskImportService {

	private const FILE = '.docs/legacy_tasks_import.json';

	/** @var array<int, array<string, mixed>>|null */
	private ?array $rows = null;

	public function __construct(
		private readonly TaskManager $taskManager,
		private readonly TermManager $termManager,
		private readonly PostManager $postManager,
		private readonly MetaBoxRepository $metaboxes,
		private readonly TemplateRegistry $templates,
	) {}

	/** Общее число записей в файле переноса. */
	public function totalCount(): int {
		return count( $this->loadRows() );
	}

	/**
	 * Импортирует один батч записей, начиная с $offset.
	 *
	 * @return array{created:int, skipped:int, warnings:string[], next_offset:int, total:int, done:bool}
	 */
	public function importBatch(
		string $subjectKey,
		int $offset,
		int $limit,
		string $authorTaxonomy,
		string $yearTaxonomy,
		string $levelTaxonomy
	): array {
		$rows = $this->loadRows();
		$total = count( $rows );

		$numberTaxonomy = "{$subjectKey}_task_number";
		if ( ! taxonomy_exists( $numberTaxonomy ) ) {
			throw new \RuntimeException( "Таксономия номеров заданий «{$numberTaxonomy}» не найдена — проверьте ключ предмета." );
		}

		$postType = PostTypeResolver::tasks( $subjectKey );

		$slice   = array_slice( $rows, $offset, $limit );
		$created = 0;
		$skipped = 0;
		$warnings = array();

		foreach ( $slice as $i => $row ) {
			$rowIndex = $offset + $i;

			if ( ! is_array( $row ) ) {
				continue;
			}

			$legacyNumber = isset( $row['legacy_number'] ) ? (int) $row['legacy_number'] : 0;

			// Дедуп: повторный запуск (после сбоя сети/повторного клика) не должен
			// плодить дубли — строка с уже импортированным legacy_number пропускается.
			if ( $legacyNumber > 0 && $this->alreadyImported( $postType, $legacyNumber ) ) {
				$warnings[] = "Строка {$rowIndex}: legacy_number {$legacyNumber} уже импортирован ранее — пропущена.";
				++$skipped;
				continue;
			}

			$egeNumber = isset( $row['ege_number'] ) ? (int) $row['ege_number'] : 0;
			if ( $egeNumber <= 0 ) {
				$warnings[] = "Строка {$rowIndex}: не указан номер задания ЕГЭ — пропущена.";
				++$skipped;
				continue;
			}

			$termId = $this->termManager->getOrCreateIdByName( (string) $egeNumber, $numberTaxonomy );
			if ( 0 === $termId ) {
				$warnings[] = "Строка {$rowIndex}: не удалось получить термин номера {$egeNumber} — пропущена.";
				++$skipped;
				continue;
			}

			$title = trim( (string) ( $row['variant_label'] ?? '' ) ) ?: 'Импорт';

			try {
				$postId = $this->taskManager->createNewTask( $subjectKey, $termId, $title, null );
			} catch ( \Throwable $e ) {
				$warnings[] = "Строка {$rowIndex}: создание не удалось — {$e->getMessage()}";
				++$skipped;
				continue;
			}

			if ( $legacyNumber > 0 ) {
				$this->postManager->updateMeta( $postId, PostMetaName::LegacyImportNumber->value, $legacyNumber );
			}

			$this->fillContent( $postId, $row );
			$this->assignTerm( $postId, $authorTaxonomy, (string) ( $row['author'] ?? '' ) );
			$this->assignTerm( $postId, $yearTaxonomy, (string) ( $row['year'] ?? '' ) );
			$this->assignTerm( $postId, $levelTaxonomy, (string) ( $row['level'] ?? '' ) );

			$mismatch = $this->fieldMismatchWarning( $subjectKey, $numberTaxonomy, $termId, $row );
			if ( null !== $mismatch ) {
				$warnings[] = "Строка {$rowIndex} (пост {$postId}): {$mismatch}";
			}

			++$created;
		}

		$nextOffset = $offset + count( $slice );

		return array(
			'created'     => $created,
			'skipped'     => $skipped,
			'warnings'    => $warnings,
			'next_offset' => $nextOffset,
			'total'       => $total,
			'done'        => $nextOffset >= $total,
		);
	}

	/**
	 * Мержит условие/ответ/код/файл в существующую fs_lms_meta поста, не
	 * трогая ключи, которые мог выставить boilerplate при создании.
	 *
	 * @param array<string, mixed> $row
	 */
	private function fillContent( int $postId, array $row ): void {
		$existing = $this->postManager->getMeta( $postId, PostMetaName::Meta->value );
		$existing = is_array( $existing ) ? $existing : array();

		$condition = trim( (string) ( $row['condition_html'] ?? '' ) );
		$answer    = trim( (string) ( $row['answer'] ?? '' ) );
		$code      = trim( (string) ( $row['code_python'] ?? '' ) );
		$fileUrl   = trim( (string) ( $row['file_url'] ?? '' ) );

		if ( '' !== $condition ) {
			$existing['task_condition'] = $condition;
		}
		if ( '' !== $answer ) {
			$existing['task_answer'] = $answer;
		}
		if ( '' !== $code ) {
			$existing['task_code'] = $code;
		}
		if ( '' !== $fileUrl ) {
			$existing['file'] = $fileUrl;
		}

		$this->postManager->updateMeta( $postId, PostMetaName::Meta->value, $existing );
	}

	/** Уже есть задание с этим legacy_number (повторный/прерванный запуск переноса). */
	private function alreadyImported( string $postType, int $legacyNumber ): bool {
		$existing = $this->postManager->search(
			$postType,
			array(
				'status'     => array( 'publish', 'pending', 'future', 'private', 'draft' ),
				'limit'      => 1,
				'meta_query' => array(
					array(
						'key'     => PostMetaName::LegacyImportNumber->value,
						'value'   => $legacyNumber,
						'compare' => '=',
					),
				),
			)
		);

		return array() !== $existing;
	}

	/**
	 * Предупреждает, если у назначенного шаблона номера нет поля кода/файла,
	 * а в строке есть код или ссылка на файл — значение всё равно уйдёт в
	 * fs_lms_meta, но метабокс его не отобразит, пока шаблон номера не сменят.
	 *
	 * @param array<string, mixed> $row
	 */
	private function fieldMismatchWarning( string $subjectKey, string $numberTaxonomy, int $termId, array $row ): ?string {
		$hasCode = '' !== trim( (string) ( $row['code_python'] ?? '' ) );
		$hasFile = '' !== trim( (string) ( $row['file_url'] ?? '' ) );

		if ( ! $hasCode && ! $hasFile ) {
			return null;
		}

		$term = $this->termManager->get( $termId, $numberTaxonomy );
		if ( ! $term ) {
			return null;
		}

		$templateId = $this->metaboxes->getAssignment( $subjectKey, (string) $term->slug )?->template_id ?? TaskTemplate::Standard->value;
		$template   = $this->templates->get( $templateId );
		$fields     = $template?->fields ?? array();

		$missing = array();
		if ( $hasCode && ! isset( $fields['task_code'] ) ) {
			$missing[] = 'код решения';
		}
		if ( $hasFile && ! isset( $fields['file'] ) ) {
			$missing[] = 'файл задания';
		}

		if ( array() === $missing ) {
			return null;
		}

		return 'у назначенного шаблона нет поля «' . implode( '», «', $missing ) . '» — данные сохранены в мете, но не видны в метабоксе; смените шаблон номера или перенесите вручную.';
	}

	/** Проставляет термин по названию, если таксономия зарегистрирована и название непусто. */
	private function assignTerm( int $postId, string $taxonomy, string $name ): void {
		$name = trim( $name );
		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$termId = $this->termManager->getOrCreateIdByName( $name, $taxonomy );
		if ( $termId > 0 ) {
			$this->termManager->setPostTerms( $postId, array( $termId ), $taxonomy );
		}
	}

	/** @return array<int, array<string, mixed>> */
	private function loadRows(): array {
		if ( null === $this->rows ) {
			$path    = FS_LMS_PATH . self::FILE;
			$json    = is_readable( $path ) ? (string) file_get_contents( $path ) : '[]';
			$decoded = json_decode( $json, true );

			$this->rows = is_array( $decoded ) ? $decoded : array();
		}

		return $this->rows;
	}
}
