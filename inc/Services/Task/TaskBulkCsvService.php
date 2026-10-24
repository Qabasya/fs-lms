<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\DTO\Export\CsvColumn;
use Inc\DTO\Task\TaskBulkRowDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Export\CsvExportService;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskBulkCsvService
 *
 * Формат CSV массовой правки заданий: шаблон с текущими значениями и разбор загруженного файла.
 *
 * Колонки: «Старый номер» (ключ строки), «Новый номер», «Название» (без префикса «№ N. » —
 * его собирает плагин), «Дата публикации», «Номер задания» (для смены серии) и по колонке
 * на каждую пользовательскую таксономию предмета, заголовок вида «Таксономия: Автор [math_author]».
 * Пустая ячейка — «не менять»; в таксономии несколько термов разделяются «|», «-» снимает все.
 *
 * @package Inc\Services\Task
 */
class TaskBulkCsvService {

	public const string COL_OLD    = 'Старый номер';
	public const string COL_NEW    = 'Новый номер';
	public const string COL_TITLE  = 'Название';
	public const string COL_DATE   = 'Дата публикации';
	public const string COL_NUMBER = 'Номер задания';

	/** Потолок строк в одном файле: правка идёт одним запросом, дольше max_execution_time не живёт. */
	public const int MAX_ROWS = 2000;

	/** Статусы, в которых задание считается существующим (корзина не в счёт). */
	private const array STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	public function __construct(
		private readonly PostManager $posts,
		private readonly TermManager $terms,
		private readonly TaxonomyRepository $taxonomies,
		private readonly CsvExportService $csv,
	) {}

	/**
	 * Шаблон: по строке на задание предмета, текущие значения подставлены.
	 *
	 * @param string $taskNumberSlug Слаг терма номера задания — только задания этого номера; '' — все
	 *
	 * @return string CSV (UTF-8 с BOM)
	 */
	public function buildTemplate( string $subjectKey, string $taskNumberSlug = '' ): string {
		$postType  = PostTypeResolver::tasks( $subjectKey );
		$numberTax = PostTypeResolver::getTaskTaxonomy( $subjectKey );
		$custom    = $this->customTaxonomies( $subjectKey );

		$opts = array(
			'status'     => self::STATUSES,
			'limit'      => -1,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'meta_query' => $this->posts->bundleChildExclusion(),
		);
		if ( '' !== $taskNumberSlug ) {
			$opts['tax_query'] = array(
				array(
					'taxonomy' => $numberTax,
					'field'    => 'slug',
					'terms'    => array( $taskNumberSlug ),
				),
			);
		}

		$posts = $this->posts->search( $postType, $opts );
		usort( $posts, static fn( \WP_Post $a, \WP_Post $b ): int => strnatcmp( $a->post_name, $b->post_name ) );

		$this->posts->primePostCache( array_map( static fn( \WP_Post $p ): int => $p->ID, $posts ) );

		$columns = array(
			new CsvColumn( self::COL_OLD, static fn( array $r ): string => $r['old'] ),
			new CsvColumn( self::COL_NEW, static fn( array $r ): string => '' ),
			new CsvColumn( self::COL_TITLE, static fn( array $r ): string => $r['title'] ),
			new CsvColumn( self::COL_DATE, static fn( array $r ): string => $r['date'] ),
			new CsvColumn( self::COL_NUMBER, static fn( array $r ): string => $r['number'] ),
		);
		foreach ( $custom as $slug => $name ) {
			$columns[] = new CsvColumn(
				self::taxonomyHeader( $name, $slug ),
				static fn( array $r ): string => $r['terms'][ $slug ] ?? ''
			);
		}

		$rows = array();
		foreach ( $posts as $post ) {
			$terms = array();
			foreach ( array_keys( $custom ) as $slug ) {
				$terms[ $slug ] = implode( ' | ', array_map( static fn( \WP_Term $t ): string => $t->name, $this->terms->getPostTerms( $post->ID, $slug ) ) );
			}
			$numberTerm = $this->terms->getPostTerms( $post->ID, $numberTax )[0] ?? null;

			$rows[] = array(
				'old'    => $post->post_name,
				'title'  => self::stripPrefix( $post->post_title ),
				'date'   => substr( $post->post_date, 0, 16 ),
				'number' => $numberTerm ? $numberTerm->name : '',
				'terms'  => $terms,
			);
		}

		return $this->csv->export( $rows, $columns );
	}

	/**
	 * Выгрузка снимка: старый номер и то, что с заданием стало.
	 *
	 * @param array{id: int, items: array<int, array<string, mixed>>} $snapshot Снимок ({@see TaskBulkSnapshotRepository::find()})
	 *
	 * @return string CSV (UTF-8 с BOM)
	 */
	public function buildSnapshotCsv( array $snapshot ): string {
		$columns = array(
			new CsvColumn( 'Снимок №', static fn( array $r ): string => (string) $r['snapshot'] ),
			new CsvColumn( 'ID задания', static fn( array $r ): string => (string) $r['post_id'] ),
			new CsvColumn( self::COL_OLD, static fn( array $r ): string => (string) $r['old_slug'] ),
			new CsvColumn( self::COL_NEW, static fn( array $r ): string => (string) $r['new_slug'] ),
			new CsvColumn( 'Название было', static fn( array $r ): string => (string) $r['before']['title'] ),
			new CsvColumn( 'Название стало', static fn( array $r ): string => (string) $r['after']['title'] ),
			new CsvColumn( 'Дата была', static fn( array $r ): string => substr( (string) $r['before']['post_date'], 0, 16 ) ),
			new CsvColumn( 'Дата стала', static fn( array $r ): string => substr( (string) $r['after']['post_date'], 0, 16 ) ),
			new CsvColumn( 'Что изменилось', static fn( array $r ): string => (string) $r['summary'] ),
		);

		$rows = array_map(
			static fn( array $item ): array => $item + array( 'snapshot' => $snapshot['id'] ),
			$snapshot['items']
		);

		return $this->csv->export( $rows, $columns );
	}

	/**
	 * Разбирает загруженный CSV.
	 *
	 * @return TaskBulkRowDTO[]
	 * @throws \InvalidArgumentException Если структура файла неверна.
	 */
	public function parse( string $csv, string $subjectKey ): array {
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ?? $csv;
		if ( '' === trim( $csv ) ) {
			throw new \InvalidArgumentException( 'Файл пуст.' );
		}

		$known     = $this->customTaxonomies( $subjectKey );
		$delimiter = $this->detectDelimiter( $csv );
		$handle    = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $csv );
		rewind( $handle );

		$header = fgetcsv( $handle, 0, $delimiter, '"', '\\' );
		if ( ! is_array( $header ) ) {
			throw new \InvalidArgumentException( 'Не удалось прочитать заголовок файла.' );
		}

		// Часть редакторов (Numbers и др.) при сохранении заворачивает каждую строку целиком в кавычки:
		// вся строка оказывается одной ячейкой с запятыми внутри. Тогда разбираем ячейку заново.
		$wrapped = 1 === count( $header ) && str_contains( (string) $header[0], $delimiter );
		if ( $wrapped ) {
			$header = str_getcsv( (string) $header[0], $delimiter, '"', '\\' );
		}

		$map = $this->mapHeader( $header, $known );

		$rows = array();
		$line = 1;
		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			++$line;
			if ( array( null ) === $cells || '' === trim( implode( '', array_map( 'strval', $cells ) ) ) ) {
				continue;
			}

			if ( $wrapped ) {
				$cells = str_getcsv( (string) $cells[0], $delimiter, '"', '\\' );
			}

			// Апостроф перед «=», «+», «-», «@» ставит сам экспорт (защита от формул) — при чтении снимаем.
			$get = static fn( string $key ): string => isset( $map[ $key ] )
				? (string) preg_replace( "/^'(?=[=+\\-@])/", '', trim( (string) ( $cells[ $map[ $key ] ] ?? '' ) ) )
				: '';

			$terms = array();
			foreach ( array_keys( $known ) as $slug ) {
				$cell = $get( 'tax:' . $slug );
				if ( '' === $cell ) {
					continue;
				}
				$terms[ $slug ] = '-' === $cell ? array() : array_values( array_filter( array_map( 'trim', explode( '|', $cell ) ), static fn( string $v ): bool => '' !== $v ) );
			}

			$rows[] = new TaskBulkRowDTO(
				line      : $line,
				oldNumber : $get( 'old' ),
				newNumber : $get( 'new' ),
				title     : $get( 'title' ),
				date      : $get( 'date' ),
				taskNumber: $get( 'number' ),
				terms     : $terms,
			);

			if ( count( $rows ) > self::MAX_ROWS ) {
				fclose( $handle );
				throw new \InvalidArgumentException( 'В файле больше ' . self::MAX_ROWS . ' строк — разбейте его на части.' );
			}
		}
		fclose( $handle );

		if ( array() === $rows ) {
			throw new \InvalidArgumentException( 'В файле нет строк с заданиями.' );
		}

		return $rows;
	}

	/** Название задания без служебного префикса «№ 1000. ». */
	public static function stripPrefix( string $title ): string {
		return trim( (string) preg_replace( '/^№\s*\S+?\.\s+/u', '', $title ) );
	}

	/** Заголовок колонки таксономии. */
	public static function taxonomyHeader( string $name, string $slug ): string {
		return "Таксономия: {$name} [{$slug}]";
	}

	/**
	 * Пользовательские таксономии предмета (без номера задания): слаг => название.
	 *
	 * @return array<string, string>
	 */
	public function customTaxonomies( string $subjectKey ): array {
		$numberTax = PostTypeResolver::getTaskTaxonomy( $subjectKey );
		$result    = array();

		foreach ( $this->taxonomies->getBySubject( $subjectKey ) as $dto ) {
			if ( $dto->slug !== $numberTax ) {
				$result[ $dto->slug ] = $dto->name;
			}
		}

		return $result;
	}

	/** Разделитель по первой строке: Excel с русской локалью сохраняет «;». */
	private function detectDelimiter( string $csv ): string {
		$first = strtok( $csv, "\r\n" ) ?: '';

		$best  = ',';
		$count = substr_count( $first, ',' );
		foreach ( array( ';', "\t" ) as $candidate ) {
			if ( substr_count( $first, $candidate ) > $count ) {
				$best  = $candidate;
				$count = substr_count( $first, $candidate );
			}
		}

		return $best;
	}

	/**
	 * Колонки файла → индексы. Ключи: old|new|title|date|number|tax:{slug}.
	 *
	 * @param array<int, string|null> $header
	 * @param array<string, string>   $known  Таксономии предмета
	 *
	 * @return array<string, int>
	 */
	private function mapHeader( array $header, array $known ): array {
		$fixed = array(
			mb_strtolower( self::COL_OLD )    => 'old',
			mb_strtolower( self::COL_NEW )    => 'new',
			mb_strtolower( self::COL_TITLE )  => 'title',
			mb_strtolower( self::COL_DATE )   => 'date',
			mb_strtolower( self::COL_NUMBER ) => 'number',
		);

		$map = array();
		foreach ( $header as $index => $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}

			$lower = mb_strtolower( $name );
			if ( isset( $fixed[ $lower ] ) ) {
				$map[ $fixed[ $lower ] ] = $index;
				continue;
			}

			if ( 1 === preg_match( '/\[([a-z0-9_]+)\]\s*$/i', $name, $m ) && isset( $known[ $m[1] ] ) ) {
				$map[ 'tax:' . $m[1] ] = $index;
				continue;
			}

			throw new \InvalidArgumentException( "Неизвестная колонка «{$name}». Скачайте актуальный шаблон." );
		}

		if ( ! isset( $map['old'] ) ) {
			throw new \InvalidArgumentException( 'Нет колонки «' . self::COL_OLD . '».' );
		}

		return $map;
	}
}
