<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\DTO\Task\LegacyTaskRowDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Subject\TaskManager;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;

/**
 * Class LegacyTaskImportService
 *
 * Разовый перенос заданий со старой версии сайта (WXR-экспорт, разобранный
 * заранее в `legacy_tasks_import.json`).
 *
 * @package Inc\Services\Task
 *
 * Каждая запись создаётся черновиком через {@see TaskManager::createNewTask()} —
 * так задание получает тот же номер и тот же шаблон (по назначению boilerplate
 * для номера), что и при обычном создании через админку. Порядок записей в
 * файле — порядок присвоения номеров: JSON уже отсортирован по
 * (номер ЕГЭ, исходный порядковый номер), поэтому последовательная отправка
 * батчей воспроизводит его без пропусков от удалённых на старом сайте постов.
 *
 * Файл выбирается на странице переноса и разбирается в браузере: на сервер
 * уходят только записи текущего батча. Так импорт работает на сборке без
 * `.docs`, а сервер не хранит файл между запросами (транзиент на ~1,3 МБ
 * упёрся бы в max_allowed_packet дешёвого хостинга). Батч — вместо всего файла
 * одним запросом: сотни записей с HTML-условиями упрутся в max_execution_time.
 */
class LegacyTaskImportService {

	/** Записей за один AJAX-запрос — держит батч в пределах max_execution_time дешёвого хостинга. */
	public const BATCH_SIZE = 15;

	public function __construct(
		private readonly TaskManager $taskManager,
		private readonly TermManager $termManager,
		private readonly PostManager $postManager,
		private readonly MetaBoxRepository $metaboxes,
		private readonly TemplateRegistry $templates,
		private readonly TemplateResolver $resolver,
		private readonly TaskBundleService $taskBundles,
	) {}

	/**
	 * Импортирует один батч записей.
	 *
	 * @param string             $subjectKey Ключ предмета
	 * @param int                $offset     Позиция первой записи батча в файле — только для нумерации строк в предупреждениях
	 * @param LegacyTaskRowDTO[] $rows       Записи батча в порядке файла
	 * @param bool               $refill     Перезаполнить уже импортированные задания
	 *                                       (по legacy_number) вместо пропуска
	 *
	 * @return array{created:int, updated:int, skipped:int, warnings:string[]}
	 */
	public function importBatch(
		string $subjectKey,
		int $offset,
		array $rows,
		string $authorTaxonomy,
		string $yearTaxonomy,
		string $levelTaxonomy,
		bool $refill = false
	): array {
		$numberTaxonomy = "{$subjectKey}_task_number";
		if ( ! taxonomy_exists( $numberTaxonomy ) ) {
			throw new \RuntimeException( "Таксономия номеров заданий «{$numberTaxonomy}» не найдена — проверьте ключ предмета." );
		}

		$postType = PostTypeResolver::tasks( $subjectKey );

		$created  = 0;
		$updated  = 0;
		$skipped  = 0;
		$warnings = array();

		foreach ( array_values( $rows ) as $i => $row ) {
			$rowIndex = $offset + $i;

			// Дедуп: повторный запуск (после сбоя сети/повторного клика) не должен
			// плодить дубли — строка с уже импортированным legacy_number пропускается,
			// а в режиме перезаполнения обновляет контент найденного задания (так
			// дозаливаются записи, перенесённые до поддержки нового поля файла).
			$existingId = $row->legacyNumber > 0 ? $this->findImported( $postType, $row->legacyNumber ) : 0;
			if ( $existingId > 0 ) {
				if ( ! $refill ) {
					$warnings[] = "Строка {$rowIndex}: legacy_number {$row->legacyNumber} уже импортирован ранее — пропущена.";
					++$skipped;
					continue;
				}

				$this->fillContent( $existingId, $row );
				$this->syncBundle( $existingId );
				++$updated;
				continue;
			}

			if ( $row->egeNumber <= 0 ) {
				$warnings[] = "Строка {$rowIndex}: не указан номер задания ЕГЭ — пропущена.";
				++$skipped;
				continue;
			}

			$termId = $this->termManager->getOrCreateIdByName( (string) $row->egeNumber, $numberTaxonomy );
			if ( 0 === $termId ) {
				$warnings[] = "Строка {$rowIndex}: не удалось получить термин номера {$row->egeNumber} — пропущена.";
				++$skipped;
				continue;
			}

			$title = trim( $row->variantLabel ) ?: 'Импорт';

			try {
				$postId = $this->taskManager->createNewTask( $subjectKey, $termId, $title, null );
			} catch ( \Throwable $e ) {
				$warnings[] = "Строка {$rowIndex}: создание не удалось — {$e->getMessage()}";
				++$skipped;
				continue;
			}

			if ( $row->legacyNumber > 0 ) {
				$this->postManager->updateMeta( $postId, PostMetaName::LegacyImportNumber->value, $row->legacyNumber );
			}

			$this->fillContent( $postId, $row );
			$this->syncBundle( $postId );
			$this->assignTerm( $postId, $authorTaxonomy, $row->author );
			$this->assignTerm( $postId, $yearTaxonomy, $row->year );
			$this->assignTerm( $postId, $levelTaxonomy, $row->level );

			$mismatch = $this->fieldMismatchWarning( $subjectKey, $numberTaxonomy, $termId, $row );
			if ( null !== $mismatch ) {
				$warnings[] = "Строка {$rowIndex} (пост {$postId}): {$mismatch}";
			}

			++$created;
		}

		return array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'warnings' => $warnings,
		);
	}

	/**
	 * Мержит условие/ответ/код/файл в существующую fs_lms_meta поста, не
	 * трогая ключи, которые мог выставить boilerplate при создании.
	 */
	private function fillContent( int $postId, LegacyTaskRowDTO $row ): void {
		$existing = $this->postManager->getMeta( $postId, PostMetaName::Meta->value );
		$existing = is_array( $existing ) ? $existing : array();

		// Составное задание (связка 19-21): подпункты идут в свои поля шаблона,
		// а общее `task_condition` не заполняется — иначе условие подпункта 19
		// попало бы на страницу дважды (`TaskMetaService::getCombinedCondition()`
		// собирает ВСЕ поля с `_condition` в ключе).
		foreach ( $row->subparts as $key => $part ) {
			if ( '' !== $part['condition'] ) {
				$existing[ "task_{$key}_condition" ] = $part['condition'];
			}
			if ( '' !== $part['answer'] ) {
				$existing[ "task_{$key}_answer" ] = $part['answer'];
			}
		}

		if ( '' !== $row->conditionHtml ) {
			$existing['task_condition'] = $row->conditionHtml;
		}
		if ( '' !== $row->answer ) {
			$existing['task_answer'] = $row->answer;
		}
		if ( '' !== $row->codePython ) {
			$existing['task_code'] = $row->codePython;
		}
		if ( '' !== $row->fileUrl ) {
			$existing['file'] = $row->fileUrl;
		}

		$this->postManager->updateMeta( $postId, PostMetaName::Meta->value, $existing );
	}

	/**
	 * Материализует children связки 19-21. Обычное сохранение делает это в
	 * {@see \Inc\Controllers\Task\MetaBoxController}, импорт пишет мету мимо
	 * метабокса — без явного вызова подзадания 19/20/21 оставались пустыми, пока
	 * автор не пересохранит связку вручную.
	 *
	 * Задание в банке при этом одно — связка под номером 19: дети служебные,
	 * нужны только для разворота в три оцениваемых слота работы/экзамена, и из
	 * списков, счётчиков и поиска банка отсекаются
	 * ({@see PostManager::bundleChildExclusion()}).
	 */
	private function syncBundle( int $postId ): void {
		$post = $this->postManager->get( $postId );
		if ( $post && TaskTemplate::Triple->value === $this->resolver->resolveId( $post ) ) {
			$this->taskBundles->syncChildren( $postId );
		}
	}

	/** ID задания с этим legacy_number (повторный/прерванный запуск переноса), 0 — не импортировалось. */
	private function findImported( string $postType, int $legacyNumber ): int {
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

		return array() === $existing ? 0 : (int) $existing[0]->ID;
	}

	/**
	 * Предупреждает, если у назначенного шаблона номера нет поля кода/файла,
	 * а в строке есть код или ссылка на файл — значение всё равно уйдёт в
	 * fs_lms_meta, но метабокс его не отобразит, пока шаблон номера не сменят.
	 */
	private function fieldMismatchWarning( string $subjectKey, string $numberTaxonomy, int $termId, LegacyTaskRowDTO $row ): ?string {
		$hasCode = '' !== $row->codePython;
		$hasFile = '' !== $row->fileUrl;

		if ( ! $hasCode && ! $hasFile && array() === $row->subparts ) {
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

		// Подпункты (связка 19-21) видны только у составного шаблона: если номеру
		// назначен обычный, три условия молча осядут в мете и автор решит, что
		// задание импортировалось пустым.
		foreach ( array_keys( $row->subparts ) as $key ) {
			if ( ! isset( $fields[ "task_{$key}_condition" ] ) ) {
				$missing[] = "условие подпункта №{$key}";
			}
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
}
