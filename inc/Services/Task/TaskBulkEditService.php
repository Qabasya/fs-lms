<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\DTO\Task\TaskBulkPlanItemDTO;
use Inc\DTO\Task\TaskBulkRowDTO;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\WPDBRepositories\TaskBulkSnapshotRepository;
use Inc\Services\Subject\ContentCacheService;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskBulkEditService
 *
 * Массовая правка заданий предмета из CSV (Предметы → Менеджер заданий): номер, название,
 * дата публикации, серия (номер задания), таксономии и файлы под новый номер.
 *
 * Работает в два шага: {@see plan()} проверяет весь файл и ничего не пишет (это же — предпросмотр),
 * {@see apply()} применяет только строки без ошибок. Перед записью сохраняется СНИМОК партии
 * (таблица `task_bulk_snapshots`): состояние затронутых заданий до правки и после неё. По снимку
 * {@see rollback()} возвращает задания, а {@see TaskBulkCsvService::buildSnapshotCsv()} выгружает
 * сопоставление «старый номер → новые данные». В журнале действий — ОДНА запись на партию
 * («Изменение заданий, снимок №N»).
 *
 * Запись идёт мимо жизненного цикла сохранения (`updateFields()`, `renameSlug()`): валидаторы
 * публикации не должны понижать опубликованные задания в черновики, аудит записи не плодит строки
 * по заданию, а старый адрес НЕ запоминается — редиректа нет (задания в статьях и курсах
 * подключены по ID).
 * Обмен номерами (A↔B) и цепочки работают: сначала все меняющиеся слаги получают временные,
 * затем — итоговые.
 *
 * @package Inc\Services\Task
 */
class TaskBulkEditService {

	/** Префикс временного слага на время обмена номерами. */
	private const string TEMP_SLUG = 'fsbulk-';

	/** Допустимые форматы даты в файле. */
	private const array DATE_FORMATS = array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd.m.Y H:i', 'd.m.Y' );

	public function __construct(
		private readonly PostManager $posts,
		private readonly TermManager $terms,
		private readonly TaskNumberService $numbers,
		private readonly TaskBulkCsvService $csv,
		private readonly TaskBulkFileService $files,
		private readonly TaskBulkSnapshotRepository $snapshots,
		private readonly TaskFileSchemeService $scheme,
		private readonly ContentCacheService $cache,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface $clock,
	) {}

	/**
	 * Проверяет строки файла: ничего не пишет.
	 *
	 * @param TaskBulkRowDTO[] $rows
	 *
	 * @return TaskBulkPlanItemDTO[]
	 */
	public function plan( string $subjectKey, array $rows ): array {
		$numberTax = PostTypeResolver::getTaskTaxonomy( $subjectKey );
		if ( ! taxonomy_exists( $numberTax ) ) {
			throw new \RuntimeException( "Предмет «{$subjectKey}» не найден." );
		}

		$custom = $this->csv->customTaxonomies( $subjectKey );
		$seen   = array();
		$items  = array();

		foreach ( $rows as $row ) {
			$item = $this->evaluate( $subjectKey, $numberTax, $custom, $row, isset( $seen[ $row->oldNumber ] ) );
			$seen[ $row->oldNumber ] = true;
			$items[]                 = $item;
		}

		$this->resolveConflicts( PostTypeResolver::tasks( $subjectKey ), $items );

		return $items;
	}

	/**
	 * Применяет строки без ошибок и сохраняет снимок партии.
	 *
	 * @param TaskBulkPlanItemDTO[] $items Результат {@see plan()}
	 *
	 * @return array{applied: int, skipped: int, failed: int, links: int, snapshot_id: int}
	 * @throws \RuntimeException Если запись не удалась (партия откатывается, снимок удаляется)
	 */
	public function apply( string $subjectKey, array $items, int $actorUserId ): array {
		$numberTax = PostTypeResolver::getTaskTaxonomy( $subjectKey );
		$todo      = array_values( array_filter( $items, static fn( TaskBulkPlanItemDTO $i ): bool => $i->isApplicable() ) );

		$report = array(
			'applied'     => 0,
			'skipped'     => count( array_filter( $items, static fn( TaskBulkPlanItemDTO $i ): bool => ! $i->hasErrors() && ! $i->isChange() ) ),
			'failed'      => count( array_filter( $items, static fn( TaskBulkPlanItemDTO $i ): bool => $i->hasErrors() ) ),
			'links'       => 0,
			'snapshot_id' => 0,
		);
		if ( array() === $todo ) {
			return $report;
		}

		$snapshotItems = array_map( fn( TaskBulkPlanItemDTO $i ): array => $this->snapshotItem( $i, $numberTax ), $todo );
		$snapshotId    = $this->snapshots->create( $subjectKey, $actorUserId, $snapshotItems, $this->clock->now( 'mysql', true ) );

		try {
			// Фаза 1: меняющиеся слаги уходят на временные — освобождают номера для обмена и цепочек.
			foreach ( $todo as $item ) {
				if ( $item->slugChanges() ) {
					$this->posts->renameSlug( $item->postId, self::TEMP_SLUG . $item->postId );
				}
			}

			$this->files->execute( array_merge( array(), ...array_map( static fn( TaskBulkPlanItemDTO $i ): array => $i->fileOps, $todo ) ) );

			// Фаза 2: содержимое и итоговые слаги.
			foreach ( $todo as $item ) {
				$this->write( $item, $numberTax );
				++$report['applied'];
			}
		} catch ( \Throwable $e ) {
			$this->restore( $subjectKey, $snapshotItems, false );
			$this->snapshots->delete( $snapshotId );
			throw new \RuntimeException( 'Правка прервана и отменена: ' . $e->getMessage(), 0, $e );
		}

		$this->snapshots->markApplied( $snapshotId );

		// Ссылки http → https — одним проходом по всем заданиям (идемпотентно, в снимок не входит).
		$report['links']       = (int) ( $this->scheme->migrate()['links'] ?? 0 );
		$report['snapshot_id'] = $snapshotId;

		$this->dispatcher->dispatch(
			LogEvent::TaskSnapshotApplied,
			new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::TaskSnapshot, $snapshotId, "Изменение заданий, снимок №{$snapshotId}" )
		);

		return $report;
	}

	/**
	 * Возвращает задания к состоянию ДО правки, записанному в снимке.
	 *
	 * Задание, которое после снимка менялось ещё раз (его номер уже не тот, что дала правка),
	 * не трогается: откат не должен затереть более позднюю работу.
	 *
	 * @return array{restored: int, skipped: string[], subject_key: string}
	 * @throws \InvalidArgumentException Если снимка нет или он уже откатан
	 */
	public function rollback( int $snapshotId, int $actorUserId ): array {
		$snapshot = $this->snapshots->find( $snapshotId );
		if ( null === $snapshot ) {
			throw new \InvalidArgumentException( "Снимок №{$snapshotId} не найден." );
		}
		if ( TaskBulkSnapshotRepository::STATUS_APPLIED !== $snapshot['status'] ) {
			throw new \InvalidArgumentException( "Снимок №{$snapshotId} уже откатан." );
		}

		$result = $this->restore( $snapshot['subject_key'], $snapshot['items'], true );

		$this->snapshots->markRolledBack( $snapshotId, $this->clock->now( 'mysql', true ) );
		$this->dispatcher->dispatch(
			LogEvent::TaskSnapshotRolledBack,
			new EntityChangedEvent( $actorUserId, OperationType::Update, EntityType::TaskSnapshot, $snapshotId, "Откат заданий к снимку №{$snapshotId}" )
		);

		return $result + array( 'subject_key' => $snapshot['subject_key'] );
	}

	/**
	 * Снимки предмета (без заданий), новые сверху.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function snapshotList( string $subjectKey ): array {
		return $this->snapshots->listBySubject( $subjectKey );
	}

	/**
	 * Снимок целиком; null — нет такого.
	 *
	 * @return array<string, mixed>|null
	 */
	public function snapshot( int $snapshotId ): ?array {
		return $this->snapshots->find( $snapshotId );
	}

	// ------------------------------------------------------------------ проверка

	/** @param array<string, string> $custom */
	private function evaluate( string $subjectKey, string $numberTax, array $custom, TaskBulkRowDTO $row, bool $duplicate ): TaskBulkPlanItemDTO {
		$postType = PostTypeResolver::tasks( $subjectKey );
		$item     = new TaskBulkPlanItemDTO( $row->line, 0, $row->oldNumber, '' !== $row->newNumber ? $row->newNumber : $row->oldNumber );

		if ( '' === $row->oldNumber ) {
			$item->fail( 'Не указан старый номер.' );
			return $item;
		}
		if ( $duplicate ) {
			$item->fail( "Номер {$row->oldNumber} указан в файле повторно." );
			return $item;
		}

		$post = $this->posts->findBySlug( $postType, $row->oldNumber );
		if ( null === $post ) {
			$item->fail( "Задание с номером {$row->oldNumber} не найдено." );
			return $item;
		}
		$item->postId = $post->ID;

		if ( '' !== $row->newNumber && ! ctype_digit( $row->newNumber ) ) {
			$item->fail( "Новый номер «{$row->newNumber}» должен состоять из цифр." );
			return $item;
		}

		$this->evaluateSeries( $subjectKey, $numberTax, $row, $post, $item );
		if ( $item->hasErrors() ) {
			return $item;
		}

		$this->evaluateTitle( $row, $post, $item );
		$this->evaluateDate( $row, $post, $item );
		$this->evaluateTerms( $custom, $row, $post, $item );
		$this->evaluateFiles( $row, $post, $item );

		return $item;
	}

	/** Номер и серия: новый номер должен принадлежать номеру задания (текущему или указанному). */
	private function evaluateSeries( string $subjectKey, string $numberTax, TaskBulkRowDTO $row, \WP_Post $post, TaskBulkPlanItemDTO $item ): void {
		$current   = $this->terms->getPostTerms( $post->ID, $numberTax )[0] ?? null;
		$currentNo = $current ? (int) $current->name : 0;
		$targetNo  = $currentNo;

		if ( '' !== $row->taskNumber ) {
			if ( ! ctype_digit( $row->taskNumber ) || (int) $row->taskNumber < 1 ) {
				$item->fail( "Номер задания «{$row->taskNumber}» должен быть целым числом." );
				return;
			}

			$targetNo = (int) $row->taskNumber;
			if ( $targetNo !== $currentNo ) {
				$term = $this->terms->findBySlug( "{$subjectKey}_{$targetNo}", $numberTax );
				if ( null === $term ) {
					$item->fail( "Номера задания {$targetNo} нет у предмета — создайте его в таксономиях." );
					return;
				}
				$item->numberTerm = $term->term_id;
				$item->changes[]  = "номер задания {$currentNo} → {$targetNo}";
			}
		}

		if ( $item->slugChanges() || null !== $item->numberTerm ) {
			if ( ! $this->numbers->belongsTo( $item->newSlug, $targetNo ) ) {
				$item->fail(
					"Номер {$item->newSlug} не подходит к заданию №{$targetNo}: номера имеют вид {$targetNo}000, {$targetNo}001…"
					. ( '' === $row->taskNumber ? ' Чтобы перенести в другую серию, укажите «' . TaskBulkCsvService::COL_NUMBER . '».' : '' )
				);
				return;
			}
		}

		if ( $item->slugChanges() ) {
			$item->changes[] = "номер {$item->oldSlug} → {$item->newSlug}";
		}
	}

	private function evaluateTitle( TaskBulkRowDTO $row, \WP_Post $post, TaskBulkPlanItemDTO $item ): void {
		$currentRest = TaskBulkCsvService::stripPrefix( $post->post_title );
		$rest        = '' !== $row->title ? $row->title : $currentRest;

		if ( ! $item->slugChanges() && $rest === $currentRest ) {
			return;
		}

		$title = "№ {$item->newSlug}. {$rest}";
		if ( $title !== $post->post_title ) {
			$item->newTitle  = $title;
			$item->changes[] = $rest !== $currentRest ? "название «{$currentRest}» → «{$rest}»" : 'заголовок: номер обновлён';
		}
	}

	private function evaluateDate( TaskBulkRowDTO $row, \WP_Post $post, TaskBulkPlanItemDTO $item ): void {
		if ( '' === $row->date ) {
			return;
		}

		$date = $this->parseDate( $row->date );
		if ( null === $date ) {
			$item->fail( "Дата «{$row->date}» не распознана — формат ГГГГ-ММ-ДД ЧЧ:ММ." );
			return;
		}

		if ( substr( $date, 0, 16 ) === substr( $post->post_date, 0, 16 ) ) {
			return;
		}

		// Запись идёт мимо планировщика публикации: будущая дата сделала бы опубликованное задание «отложенным» без расписания.
		if ( 'publish' === $post->post_status && get_gmt_from_date( $date ) > $this->clock->now( 'mysql', true ) ) {
			$item->fail( "Дата {$row->date} в будущем — у опубликованного задания она должна быть не позже текущей." );
			return;
		}

		$item->newDate   = $date;
		$item->changes[] = 'дата ' . substr( $post->post_date, 0, 16 ) . ' → ' . substr( $date, 0, 16 );
	}

	/** @param array<string, string> $custom */
	private function evaluateTerms( array $custom, TaskBulkRowDTO $row, \WP_Post $post, TaskBulkPlanItemDTO $item ): void {
		foreach ( $row->terms as $taxonomy => $names ) {
			$current = array_map( static fn( \WP_Term $t ): string => $t->name, $this->terms->getPostTerms( $post->ID, $taxonomy ) );

			$a = $current;
			$b = $names;
			sort( $a );
			sort( $b );
			if ( $a === $b ) {
				continue;
			}

			$ids = array();
			foreach ( $names as $name ) {
				$id = $this->terms->findIdByName( $name, $taxonomy );
				if ( $id > 0 ) {
					$ids[] = $id;
				} else {
					$item->newTerms[ $taxonomy ][] = $name;
					$item->warnings[]              = "«{$name}» ({$custom[ $taxonomy ]}) — новое значение, будет создано";
				}
			}

			$item->termIds[ $taxonomy ] = $ids;
			$item->changes[]            = ( $custom[ $taxonomy ] ?? $taxonomy ) . ': ' . ( implode( ', ', $current ) ?: '—' ) . ' → ' . ( implode( ', ', $names ) ?: '—' );
		}
	}

	private function evaluateFiles( TaskBulkRowDTO $row, \WP_Post $post, TaskBulkPlanItemDTO $item ): void {
		if ( '' === $row->newNumber ) {
			return;
		}

		$meta = $this->posts->getMeta( $post->ID, PostMetaName::Meta->value );
		$plan = $this->files->plan( is_array( $meta ) ? $meta : array(), $item->newSlug, $post->ID );

		$item->fileOps    = $plan['ops'];
		$item->warnings   = array_merge( $item->warnings, $plan['warnings'] );
		foreach ( $plan['ops'] as $op ) {
			$verb            = TaskBulkFileService::MODE_COPY === $op['mode'] ? 'скопирован (файл общий с другим заданием)' : 'переименован';
			$item->changes[] = 'файл ' . basename( $op['from'] ) . ' → ' . basename( $op['to'] ) . ", {$verb}";
		}
	}

	/**
	 * Конфликты внутри партии: два задания на один номер, занятый номер, занятое имя файла.
	 * Номер, который освобождает другая строка (обмен, цепочка), конфликтом не считается.
	 * Повторяется, пока появляются новые ошибки: строка с ошибкой не двигается и номер не освобождает.
	 *
	 * @param TaskBulkPlanItemDTO[] $items
	 */
	private function resolveConflicts( string $postType, array $items ): void {
		do {
			$failed = false;

			$movers   = array_filter( $items, static fn( TaskBulkPlanItemDTO $i ): bool => ! $i->hasErrors() && $i->slugChanges() );
			$vacating = array_fill_keys( array_map( static fn( TaskBulkPlanItemDTO $i ): string => $i->oldSlug, $movers ), true );
			$targets  = array();

			foreach ( $movers as $item ) {
				if ( isset( $targets[ $item->newSlug ] ) ) {
					$item->fail( "Номер {$item->newSlug} назначен в файле нескольким заданиям." );
					$failed = true;
					continue;
				}
				$targets[ $item->newSlug ] = true;

				$other = $this->posts->findBySlug( $postType, $item->newSlug, $item->postId );
				if ( null !== $other && ! isset( $vacating[ $item->newSlug ] ) ) {
					$item->fail( "Номер {$item->newSlug} уже занят заданием «{$other->post_title}» (ID {$other->ID})." );
					$failed = true;
				}
			}

			$live    = array_filter( $items, static fn( TaskBulkPlanItemDTO $i ): bool => ! $i->hasErrors() && array() !== $i->fileOps );
			$sources = array();
			foreach ( $live as $item ) {
				foreach ( $item->fileOps as $op ) {
					if ( TaskBulkFileService::MODE_MOVE === $op['mode'] ) {
						$sources[ $op['from'] ] = true;
					}
				}
			}

			$taken = array();
			foreach ( $live as $item ) {
				foreach ( $item->fileOps as $op ) {
					$busy = isset( $taken[ $op['to'] ] )
						|| ( file_exists( $op['to'] ) && ! ( TaskBulkFileService::MODE_MOVE === $op['mode'] && isset( $sources[ $op['to'] ] ) ) );
					$taken[ $op['to'] ] = true;

					if ( $busy ) {
						$item->fail( 'Файл ' . basename( $op['to'] ) . ' уже существует — переименование невозможно.' );
						$failed = true;
						break;
					}
				}
			}
		} while ( $failed );
	}

	private function parseDate( string $value ): ?string {
		foreach ( self::DATE_FORMATS as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value );
			$err  = \DateTimeImmutable::getLastErrors();
			if ( false !== $date && ( false === $err || ( 0 === $err['warning_count'] && 0 === $err['error_count'] ) ) ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}

		return null;
	}

	// ------------------------------------------------------------------ запись

	/**
	 * Строка снимка: состояние задания до правки (по нему откат) и после (по нему сопоставление в CSV).
	 *
	 * @return array<string, mixed>
	 */
	private function snapshotItem( TaskBulkPlanItemDTO $item, string $numberTax ): array {
		$post = $this->posts->get( $item->postId );
		$meta = $this->posts->getMeta( $item->postId, PostMetaName::Meta->value );
		$meta = is_array( $meta ) ? $meta : array();

		$taxonomies = array_keys( $item->termIds + $item->newTerms );
		if ( null !== $item->numberTerm ) {
			$taxonomies[] = $numberTax;
		}

		$terms = array();
		foreach ( array_unique( $taxonomies ) as $taxonomy ) {
			$terms[ $taxonomy ] = array_map( static fn( \WP_Term $t ): int => $t->term_id, $this->terms->getPostTerms( $item->postId, $taxonomy ) );
		}

		$fileMeta = array();
		foreach ( $item->fileOps as $op ) {
			$fileMeta[ $op['key'] ] = $meta[ $op['key'] ] ?? '';
		}

		$title = $post ? $post->post_title : '';
		$date  = $post ? $post->post_date : '';

		return array(
			'post_id'    => $item->postId,
			'old_slug'   => $item->oldSlug,
			'new_slug'   => $item->newSlug,
			'before'     => array(
				'title'         => $title,
				'post_date'     => $date,
				'post_date_gmt' => $post ? $post->post_date_gmt : '',
				'post_status'   => $post ? $post->post_status : '',
				'terms'         => $terms,
				'file_meta'     => $fileMeta,
			),
			'after'      => array(
				'title'     => $item->newTitle ?? $title,
				'post_date' => $item->newDate ?? $date,
			),
			'file_ops'   => $item->fileOps,
			'summary'    => implode( '; ', $item->changes ),
		);
	}

	private function write( TaskBulkPlanItemDTO $item, string $numberTax ): void {
		$fields = array();
		if ( null !== $item->newTitle ) {
			$fields['post_title'] = $item->newTitle;
		}
		if ( null !== $item->newDate ) {
			$fields['post_date']     = $item->newDate;
			$fields['post_date_gmt'] = get_gmt_from_date( $item->newDate );
		}
		$this->posts->updateFields( $item->postId, $fields );

		if ( array() !== $item->fileOps ) {
			$meta = $this->posts->getMeta( $item->postId, PostMetaName::Meta->value );
			$meta = is_array( $meta ) ? $meta : array();
			foreach ( $item->fileOps as $op ) {
				$meta[ $op['key'] ] = $op['new_url'];
			}
			$this->posts->updateMeta( $item->postId, PostMetaName::Meta->value, $meta );
		}

		foreach ( array_keys( $item->termIds + $item->newTerms ) as $taxonomy ) {
			$ids = $item->termIds[ $taxonomy ] ?? array();
			foreach ( $item->newTerms[ $taxonomy ] ?? array() as $name ) {
				$id = $this->terms->getOrCreateIdByName( $name, $taxonomy );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$this->terms->setPostTerms( $item->postId, array_values( array_unique( $ids ) ), $taxonomy );
		}

		if ( null !== $item->numberTerm ) {
			$this->terms->setPostTerms( $item->postId, array( $item->numberTerm ), $numberTax );
		}

		if ( $item->slugChanges() ) {
			$this->posts->renameSlug( $item->postId, $item->newSlug );
		}

		$this->purgeCache( $item->postId );
	}

	/**
	 * Возвращает задания партии к состоянию `before`.
	 *
	 * @param array<int, array<string, mixed>> $items  Строки снимка
	 * @param bool                             $strict true — штатный откат: задание, изменённое после снимка, пропускается;
	 *                                                 false — отмена неудавшейся правки (слаги могут быть временными)
	 *
	 * @return array{restored: int, skipped: string[]}
	 */
	private function restore( string $subjectKey, array $items, bool $strict ): array {
		$postType = PostTypeResolver::tasks( $subjectKey );
		$batchIds = array_map( static fn( array $s ): int => (int) $s['post_id'], $items );
		$skipped  = array();
		$todo     = array();

		foreach ( $items as $snap ) {
			$id   = (int) $snap['post_id'];
			$post = $this->posts->get( $id );

			if ( $strict && ( null === $post || $post->post_name !== (string) $snap['new_slug'] ) ) {
				$skipped[] = "{$snap['old_slug']}: задание изменено после правки — не откатано";
				continue;
			}

			$other = $this->posts->findBySlug( $postType, (string) $snap['old_slug'], $id );
			if ( null !== $other && ! in_array( $other->ID, $batchIds, true ) ) {
				$skipped[] = "{$snap['old_slug']}: номер уже занят заданием «{$other->post_title}» (ID {$other->ID}) — не откатано";
				continue;
			}

			$todo[] = $snap;
		}

		foreach ( $todo as $snap ) {
			$this->posts->renameSlug( (int) $snap['post_id'], self::TEMP_SLUG . $snap['post_id'] );
		}

		$this->files->revert( array_merge( array(), ...array_map( static fn( array $s ): array => $s['file_ops'], $todo ) ) );

		foreach ( $todo as $snap ) {
			$id     = (int) $snap['post_id'];
			$before = $snap['before'];

			$fields = array(
				'post_title'    => (string) $before['title'],
				'post_date'     => (string) $before['post_date'],
				'post_date_gmt' => (string) $before['post_date_gmt'],
			);
			if ( '' !== (string) $before['post_status'] ) {
				$fields['post_status'] = (string) $before['post_status'];
			}
			$this->posts->updateFields( $id, $fields );

			if ( array() !== $before['file_meta'] ) {
				$meta = $this->posts->getMeta( $id, PostMetaName::Meta->value );
				$meta = is_array( $meta ) ? $meta : array();
				foreach ( $before['file_meta'] as $key => $url ) {
					$meta[ $key ] = $url;
				}
				$this->posts->updateMeta( $id, PostMetaName::Meta->value, $meta );
			}

			foreach ( $before['terms'] as $taxonomy => $ids ) {
				$this->terms->setPostTerms( $id, array_map( 'intval', $ids ), (string) $taxonomy );
			}

			$this->posts->renameSlug( $id, (string) $snap['old_slug'] );
			$this->purgeCache( $id );
		}

		return array( 'restored' => count( $todo ), 'skipped' => $skipped );
	}

	private function purgeCache( int $postId ): void {
		$post = $this->posts->get( $postId );
		if ( $post ) {
			$this->cache->clearRecentContentCache( $postId, $post );
		}
	}
}
