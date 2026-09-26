<?php

declare( strict_types=1 );

namespace Inc\Services\Print;

use RuntimeException;

/**
 * Class PdfFormFiller
 *
 * Заполнение текстовых полей PDF-формы (AcroForm) средствами PHP, без библиотек.
 *
 * ### Файл остаётся формой
 *
 * Изменения дописываются в конец файла инкрементальным обновлением (новые версии
 * изменённых объектов + своя таблица ссылок с /Prev на исходную): исходный документ
 * не трогается, поля остаются полями — их можно поправить руками в любом редакторе.
 *
 * ### Кириллица
 *
 * Шрифты формы обычно не содержат кириллицы, а просмотрщики по-разному
 * перерисовывают поля с NeedAppearances. Поэтому внешний вид каждого заполненного
 * поля рисуется здесь же вшитым моноширинным PT Mono (кодировка — Windows-1251
 * через /Differences), а NeedAppearances снимается: справка выглядит одинаково в
 * Acrobat, Chrome и Preview. Тот же шрифт регистрируется в ресурсах формы (/DR)
 * под именами из /DA полей — ручная правка тоже идёт с кириллицей.
 *
 * Поле-«гребёнка» (флаг Comb + MaxLen) рисуется по символу в центр каждой клетки.
 *
 * ### Ограничения
 *
 * Поддерживается классическая таблица ссылок (xref), в т.ч. цепочка /Prev;
 * потоковая таблица (XRef stream) и шифрование — нет: такой шаблон нужно
 * пересохранить (например, в Preview — «Экспортировать как PDF»).
 *
 * @package Inc\Services\Print
 */
class PdfFormFiller {

	/** Флаг Comb у текстового поля (бит 25). */
	private const int FLAG_COMB = 1 << 24;

	/** Метрики PT Mono (em = 1000): ширина любого символа, высота прописных. */
	private const int GLYPH_WIDTH = 600;
	private const int CAP_HEIGHT  = 700;

	/** Имя шрифта в ресурсах нарисованного внешнего вида поля. */
	private const string AP_FONT = 'FsMono';

	/** Шрифт по умолчанию, если в /DA поля размер не задан (0 — «авто»). */
	private const float DEFAULT_SIZE = 12.0;

	private string $pdf = '';

	/** @var array<int, int> Номер объекта → смещение (новейшая версия). */
	private array $offsets = array();

	/** @var array<string, string> Ключи трейлера (Root, Info, ID, Size, Encrypt). */
	private array $trailer = array();

	private int $startxref = 0;

	/** @var array<int, string> Изменённые словари: номер → новый текст словаря. */
	private array $dirty = array();

	/** @var array<int, string> Новые объекты: номер → полный текст тела. */
	private array $added = array();

	private int $nextId = 0;

	public function __construct(
		private readonly string $fontPath = FS_LMS_PATH . 'templates/documents/fonts/PTMono-Regular.ttf',
	) {}

	/**
	 * Имена текстовых полей формы (для проверки шаблона).
	 *
	 * @return string[]
	 */
	public function fieldNames( string $path ): array {
		$this->load( $path );

		return array_values( array_unique( array_column( $this->widgets(), 'name' ) ) );
	}

	/**
	 * Заполняет поля и возвращает содержимое нового PDF.
	 *
	 * @param string                $path   Шаблон
	 * @param array<string, string> $values Значения по полным именам полей
	 *
	 * @throws RuntimeException Шаблон не читается или в нём нет указанных полей.
	 */
	public function fill( string $path, array $values ): string {
		$this->load( $path );

		$widgets = $this->widgets();
		$known   = array_column( $widgets, 'name' );
		$unknown = array_diff( array_keys( $values ), $known );
		if ( $unknown ) {
			throw new RuntimeException( 'В PDF-шаблоне нет полей: ' . implode( ', ', $unknown ) . '.' );
		}

		$fontId    = $this->addFont();
		$daFonts   = array();

		foreach ( $widgets as $w ) {
			if ( ! array_key_exists( $w['name'], $values ) ) {
				continue;
			}
			$value = (string) $values[ $w['name'] ];

			// Значение — в объект поля (у «сплющенных» форм это сам виджет).
			$this->setKey( $w['field'], 'V', $this->textString( $value ) );

			$apId = $this->addAppearance( $w, $value, $fontId );
			$this->setKey( $w['id'], 'AP', '<< /N ' . $apId . ' 0 R >>' );

			if ( preg_match( '~/([^\s/]+)\s+[\d.]+\s+Tf~', $w['da'], $m ) ) {
				$daFonts[ $m[1] ] = true;
			}
		}

		$this->updateAcroForm( array_keys( $daFonts ), $fontId, array_values( array_unique( array_column( $widgets, 'root' ) ) ) );

		return $this->write();
	}

	// ─────────────────────────── Чтение ───────────────────────────

	private function load( string $path ): void {
		$pdf = file_get_contents( $path );
		if ( false === $pdf || ! str_starts_with( $pdf, '%PDF-' ) ) {
			throw new RuntimeException( 'PDF-шаблон не читается.' );
		}

		$this->pdf     = $pdf;
		$this->offsets = array();
		$this->trailer = array();
		$this->dirty   = array();
		$this->added   = array();

		if ( ! preg_match_all( '~startxref\s+(\d+)~', $pdf, $m ) ) {
			throw new RuntimeException( 'В PDF-шаблоне нет таблицы ссылок.' );
		}
		$this->startxref = (int) end( $m[1] );

		// Цепочка секций от новой к старой: запись из новой секции главнее.
		$offset = $this->startxref;
		$seen   = array();
		while ( null !== $offset && ! isset( $seen[ $offset ] ) ) {
			$seen[ $offset ] = true;
			$offset          = $this->readXrefSection( $offset );
		}

		if ( isset( $this->trailer['Encrypt'] ) ) {
			throw new RuntimeException( 'PDF-шаблон зашифрован — сохраните его без пароля.' );
		}

		$this->nextId = (int) ( $this->trailer['Size'] ?? 0 );
	}

	/**
	 * Читает секцию xref + трейлер; возвращает смещение /Prev или null.
	 */
	private function readXrefSection( int $offset ): ?int {
		if ( ! preg_match( '~\Gxref\s*~', $this->pdf, $m, 0, $offset ) ) {
			throw new RuntimeException( 'PDF-шаблон сохранён с потоковой таблицей ссылок — пересохраните его (например, в Preview: «Экспортировать как PDF»).' );
		}
		$pos = $offset + strlen( $m[0] );

		while ( preg_match( '~\G(\d+)\s+(\d+)\s*[\r\n]+~', $this->pdf, $h, 0, $pos ) ) {
			$pos  += strlen( $h[0] );
			$first = (int) $h[1];
			for ( $i = 0, $count = (int) $h[2]; $i < $count; $i++ ) {
				if ( ! preg_match( '~\G(\d{10})\s(\d{5})\s([nf])\s*~', $this->pdf, $e, 0, $pos ) ) {
					throw new RuntimeException( 'Таблица ссылок PDF-шаблона повреждена.' );
				}
				$pos += strlen( $e[0] );
				if ( 'n' === $e[3] && ! isset( $this->offsets[ $first + $i ] ) ) {
					$this->offsets[ $first + $i ] = (int) $e[1];
				}
			}
		}

		$start = strpos( $this->pdf, 'trailer', $pos );
		if ( false === $start ) {
			throw new RuntimeException( 'В PDF-шаблоне нет трейлера.' );
		}
		$dict = $this->parseDict( $this->pdf, strpos( $this->pdf, '<<', $start ) );
		foreach ( $dict as $key => $value ) {
			$this->trailer[ $key ] ??= $value;
		}

		return isset( $dict['Prev'] ) ? (int) $dict['Prev'] : null;
	}

	/**
	 * Текст словаря объекта (текущей версии, с учётом правок).
	 */
	private function objectDict( int $id ): string {
		if ( isset( $this->dirty[ $id ] ) ) {
			return $this->dirty[ $id ];
		}
		if ( ! isset( $this->offsets[ $id ] ) ) {
			return '';
		}

		$start = strpos( $this->pdf, '<<', $this->offsets[ $id ] );
		$end   = strpos( $this->pdf, 'endobj', $this->offsets[ $id ] );
		if ( false === $start || false === $end || $start > $end ) {
			return '';
		}

		return substr( $this->pdf, $start, $this->skipValue( $this->pdf, $start ) - $start );
	}

	/** @return array<string, string> */
	private function dict( int $id ): array {
		$text = $this->objectDict( $id );

		return '' === $text ? array() : $this->parseDict( $text, 0 );
	}

	/** Номер объекта из ссылки «12 0 R», иначе null. */
	private function ref( ?string $value ): ?int {
		return null !== $value && preg_match( '~^(\d+)\s+\d+\s+R$~', trim( $value ), $m ) ? (int) $m[1] : null;
	}

	/**
	 * Словарь значения: вложенный `<< >>` или объект по ссылке.
	 *
	 * @return array<string, string>
	 */
	private function resolveDict( ?string $value ): array {
		if ( null === $value ) {
			return array();
		}
		$ref = $this->ref( $value );
		if ( null !== $ref ) {
			return $this->dict( $ref );
		}

		return str_starts_with( ltrim( $value ), '<<' ) ? $this->parseDict( ltrim( $value ), 0 ) : array();
	}

	/**
	 * Все текстовые виджеты страниц: номер виджета, объект поля, корень его дерева,
	 * полное имя и унаследованные атрибуты.
	 *
	 * @return array<int, array{id:int, field:int, root:int, name:string, rect:float[], maxlen:int, ff:int, da:string, q:int}>
	 */
	private function widgets(): array {
		$root  = $this->ref( $this->trailer['Root'] ?? null );
		$pages = $this->ref( $this->dict( (int) $root )['Pages'] ?? null );

		$result = array();
		foreach ( $this->pageIds( (int) $pages ) as $pageId ) {
			foreach ( $this->refsIn( $this->dict( $pageId )['Annots'] ?? '' ) as $annotId ) {
				$annot = $this->dict( $annotId );
				if ( '/Widget' !== trim( $annot['Subtype'] ?? '' ) ) {
					continue;
				}
				$widget = $this->describeWidget( $annotId );
				if ( null !== $widget ) {
					$result[] = $widget;
				}
			}
		}

		return $result;
	}

	/** @return int[] */
	private function pageIds( int $nodeId, int $depth = 0 ): array {
		$node = $this->dict( $nodeId );
		if ( '/Page' === trim( $node['Type'] ?? '' ) || $depth > 32 ) {
			return array( $nodeId );
		}

		$ids = array();
		foreach ( $this->refsIn( $node['Kids'] ?? '' ) as $kid ) {
			array_push( $ids, ...$this->pageIds( $kid, $depth + 1 ) );
		}

		return $ids;
	}

	/**
	 * Ссылки из массива (вложенного или по ссылке).
	 *
	 * @return int[]
	 */
	private function refsIn( string $value ): array {
		$ref = $this->ref( $value );
		if ( null !== $ref ) {
			$value = $this->objectBody( $ref );
		}
		preg_match_all( '~(\d+)\s+\d+\s+R~', $value, $m );

		return array_map( 'intval', $m[1] );
	}

	/** Тело объекта без заголовка (для массивов по ссылке). */
	private function objectBody( int $id ): string {
		if ( ! isset( $this->offsets[ $id ] ) ) {
			return '';
		}
		$start = strpos( $this->pdf, 'obj', $this->offsets[ $id ] ) + 3;
		$end   = strpos( $this->pdf, 'endobj', $start );

		return false === $end ? '' : substr( $this->pdf, $start, $end - $start );
	}

	/**
	 * Имя поля — цепочка /T по /Parent; атрибуты FT/MaxLen/Ff/DA/Q наследуются.
	 *
	 * @return array{id:int, field:int, root:int, name:string, rect:float[], maxlen:int, ff:int, da:string, q:int}|null
	 */
	private function describeWidget( int $id ): ?array {
		$names   = array();
		$field   = null;
		$attrs   = array();
		$current = $id;
		$root    = $id;

		for ( $depth = 0; null !== $current && $depth < 16; $depth++ ) {
			$root = $current;
			$d = $this->dict( $current );
			if ( isset( $d['T'] ) ) {
				$names[] = $this->decodeText( $d['T'] );
				$field ??= $current;
			}
			foreach ( array( 'FT', 'MaxLen', 'Ff', 'DA', 'Q' ) as $key ) {
				if ( isset( $d[ $key ] ) && ! isset( $attrs[ $key ] ) ) {
					$attrs[ $key ] = $d[ $key ];
				}
			}
			$current = $this->ref( $d['Parent'] ?? null );
		}

		if ( null === $field || '/Tx' !== trim( $attrs['FT'] ?? '' ) ) {
			return null;
		}

		$rect = array_map( 'floatval', preg_split( '~\s+~', trim( $this->dict( $id )['Rect'] ?? '', " []\r\n\t" ) ) ?: array() );
		if ( 4 !== count( $rect ) ) {
			return null;
		}

		return array(
			'id'     => $id,
			'field'  => $field,
			'root'   => $root,
			'name'   => implode( '.', array_reverse( $names ) ),
			'rect'   => $rect,
			'maxlen' => (int) ( $attrs['MaxLen'] ?? 0 ),
			'ff'     => (int) ( $attrs['Ff'] ?? 0 ),
			'da'     => $this->decodeText( $attrs['DA'] ?? '()' ),
			'q'      => (int) ( $attrs['Q'] ?? 0 ),
		);
	}

	// ─────────────────────────── Правки ───────────────────────────

	/** Заменяет (или добавляет) ключ словаря объекта. */
	private function setKey( int $id, string $key, string $value ): void {
		$dict         = $this->dict( $id );
		$dict[ $key ] = $value;
		$this->dirty[ $id ] = $this->buildDict( $dict );
	}

	/**
	 * Снимает NeedAppearances (внешний вид нарисован) и регистрирует шрифт в /DR
	 * под именами из /DA полей — ручная правка тоже рисуется с кириллицей.
	 *
	 * /Fields пересобирается из полей, реально стоящих на страницах: редакторы
	 * (Preview) при пересохранении кладут туда копии полей вне страниц, и
	 * просмотрщик, найдя по имени копию, показывает пустое поле вместо настоящего,
	 * а настоящее не даёт править.
	 *
	 * @param string[] $daFonts Имена шрифтов из /DA заполненных полей
	 * @param int[]    $roots   Корневые поля виджетов страниц
	 */
	private function updateAcroForm( array $daFonts, int $fontId, array $roots ): void {
		$rootId  = (int) $this->ref( $this->trailer['Root'] ?? null );
		$catalog = $this->dict( $rootId );
		$formRef = $this->ref( $catalog['AcroForm'] ?? null );

		$form = null !== $formRef ? $this->dict( $formRef ) : $this->resolveDict( $catalog['AcroForm'] ?? null );
		unset( $form['NeedAppearances'] );
		$form['Fields'] = '[' . implode( ' ', array_map( static fn( int $id ): string => $id . ' 0 R', $roots ) ) . ']';

		$dr    = $this->resolveDict( $form['DR'] ?? null );
		$fonts = $this->resolveDict( $dr['Font'] ?? null );
		foreach ( $daFonts as $name ) {
			$fonts[ $name ] ??= $fontId . ' 0 R';
		}
		$dr['Font'] = $this->buildDict( $fonts );
		$form['DR'] = $this->buildDict( $dr );

		if ( null !== $formRef ) {
			$this->dirty[ $formRef ] = $this->buildDict( $form );
			return;
		}
		$this->setKey( $rootId, 'AcroForm', $this->buildDict( $form ) );
	}

	private function addObject( string $body ): int {
		$id                 = $this->nextId++;
		$this->added[ $id ] = $body;

		return $id;
	}

	private function stream( string $dict, string $data ): string {
		if ( function_exists( 'gzcompress' ) ) {
			$data = (string) gzcompress( $data );
			$dict = '/Filter /FlateDecode ' . $dict;
		}

		return '<< ' . $dict . ' /Length ' . strlen( $data ) . " >>\nstream\n" . $data . "\nendstream";
	}

	/**
	 * Вшитый PT Mono с кодировкой Windows-1251 (кириллица — через /Differences).
	 */
	private function addFont(): int {
		$ttf = file_get_contents( $this->fontPath );
		if ( false === $ttf ) {
			throw new RuntimeException( 'Не найден шрифт для заполнения PDF.' );
		}

		$fileId = $this->addObject( $this->stream( '/Length1 ' . strlen( $ttf ), $ttf ) );
		$descId = $this->addObject(
			'<< /Type /FontDescriptor /FontName /PTMono-Regular /Flags 33 /FontBBox [-36 -235 728 915]'
			. ' /ItalicAngle 0 /Ascent 885 /Descent -235 /CapHeight ' . self::CAP_HEIGHT
			. ' /StemV 80 /FontFile2 ' . $fileId . ' 0 R >>'
		);

		return $this->addObject(
			'<< /Type /Font /Subtype /TrueType /BaseFont /PTMono-Regular /FirstChar 32 /LastChar 255'
			. ' /Widths [' . rtrim( str_repeat( self::GLYPH_WIDTH . ' ', 224 ) ) . ']'
			. ' /FontDescriptor ' . $descId . ' 0 R'
			. ' /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ' . $this->cp1251Differences() . ' >> >>'
		);
	}

	/**
	 * Нарисованный внешний вид поля.
	 *
	 * @param array{rect:float[], maxlen:int, ff:int, da:string, q:int} $w
	 */
	private function addAppearance( array $w, string $value, int $fontId ): int {
		[ $x1, $y1, $x2, $y2 ] = $w['rect'];
		$width  = abs( $x2 - $x1 );
		$height = abs( $y2 - $y1 );

		$chars = '' === $value ? array() : mb_str_split( $value );
		$comb  = ( $w['ff'] & self::FLAG_COMB ) && $w['maxlen'] > 0;
		if ( $w['maxlen'] > 0 ) {
			$chars = array_slice( $chars, 0, $w['maxlen'] );
		}

		$size = preg_match( '~([\d.]+)\s+Tf~', $w['da'], $m ) && (float) $m[1] > 0 ? (float) $m[1] : self::DEFAULT_SIZE;
		$size = min( $size, $height * 0.75 );
		$y    = ( $height - self::CAP_HEIGHT / 1000 * $size ) / 2;
		$ops  = '';

		if ( $comb ) {
			$cell = $width / $w['maxlen'];
			$size = min( $size, $cell / ( self::GLYPH_WIDTH / 1000 ) * 0.95 );
			$y    = ( $height - self::CAP_HEIGHT / 1000 * $size ) / 2;
			$glyph = self::GLYPH_WIDTH / 1000 * $size;
			foreach ( $chars as $i => $char ) {
				$x    = $i * $cell + ( $cell - $glyph ) / 2;
				$ops .= sprintf( "1 0 0 1 %.3F %.3F Tm %s Tj\n", $x, $y, $this->hexCp1251( $char ) );
			}
		} elseif ( $chars ) {
			$text  = implode( '', $chars );
			$fit   = ( $width - 4 ) / ( count( $chars ) * self::GLYPH_WIDTH / 1000 );
			$size  = min( $size, $fit );
			$y     = ( $height - self::CAP_HEIGHT / 1000 * $size ) / 2;
			$textW = count( $chars ) * self::GLYPH_WIDTH / 1000 * $size;
			$x     = match ( $w['q'] ) {
				1       => ( $width - $textW ) / 2,
				2       => $width - $textW - 2,
				default => 2,
			};
			$ops .= sprintf( "1 0 0 1 %.3F %.3F Tm %s Tj\n", $x, $y, $this->hexCp1251( $text ) );
		}

		$content = "/Tx BMC\nq BT\n/" . self::AP_FONT . sprintf( ' %.3F Tf 0 g', $size ) . "\n" . $ops . "ET Q\nEMC";

		return $this->addObject( $this->stream(
			sprintf( '/Type /XObject /Subtype /Form /BBox [0 0 %.3F %.3F]', $width, $height )
			. ' /Resources << /Font << /' . self::AP_FONT . ' ' . $fontId . ' 0 R >> >>',
			$content
		) );
	}

	// ─────────────────────────── Запись ───────────────────────────

	private function write(): string {
		$out     = $this->pdf;
		if ( ! str_ends_with( $out, "\n" ) ) {
			$out .= "\n";
		}

		$offsets = array();
		foreach ( $this->dirty as $id => $dict ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $dict . "\nendobj\n";
		}
		foreach ( $this->added as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		ksort( $offsets );

		$xref = strlen( $out );
		$out .= "xref\n";
		foreach ( $this->runs( array_keys( $offsets ) ) as [ $first, $count ] ) {
			$out .= $first . ' ' . $count . "\n";
			for ( $id = $first; $id < $first + $count; $id++ ) {
				$out .= sprintf( "%010d 00000 n\r\n", $offsets[ $id ] );
			}
		}

		$trailer = array(
			'Size' => (string) $this->nextId,
			'Root' => (string) $this->trailer['Root'],
			'Prev' => (string) $this->startxref,
		);
		foreach ( array( 'Info', 'ID' ) as $key ) {
			if ( isset( $this->trailer[ $key ] ) ) {
				$trailer[ $key ] = $this->trailer[ $key ];
			}
		}

		return $out . 'trailer' . "\n" . $this->buildDict( $trailer ) . "\nstartxref\n" . $xref . "\n%%EOF\n";
	}

	/**
	 * Номера объектов подряд — подсекции xref.
	 *
	 * @param int[] $ids Отсортированные номера
	 *
	 * @return array<int, array{0:int, 1:int}>
	 */
	private function runs( array $ids ): array {
		$runs = array();
		foreach ( $ids as $id ) {
			$last = array_key_last( $runs );
			if ( null !== $last && $runs[ $last ][0] + $runs[ $last ][1] === $id ) {
				++$runs[ $last ][1];
				continue;
			}
			$runs[] = array( $id, 1 );
		}

		return $runs;
	}

	// ─────────────────────────── Синтаксис PDF ───────────────────────────

	/**
	 * Разбор словаря `<< /Key value ... >>` верхнего уровня.
	 *
	 * @return array<string, string> Ключ без «/» → сырой текст значения
	 */
	private function parseDict( string $s, int $pos ): array {
		$dict = array();
		$pos += 2; // <<
		$len  = strlen( $s );

		while ( $pos < $len ) {
			$pos = $this->skipSpace( $s, $pos );
			if ( '>>' === substr( $s, $pos, 2 ) ) {
				break;
			}
			if ( '/' !== ( $s[ $pos ] ?? '' ) ) {
				throw new RuntimeException( 'Словарь PDF-шаблона повреждён.' );
			}
			$keyEnd = $this->skipValue( $s, $pos );
			$key    = substr( $s, $pos + 1, $keyEnd - $pos - 1 );
			$vStart = $this->skipSpace( $s, $keyEnd );
			$vEnd   = $this->skipValue( $s, $vStart );

			// Ссылка «12 0 R» — три токена.
			if ( preg_match( '~\G\d+\s+\d+\s+R(?![A-Za-z])~', $s, $m, 0, $vStart ) ) {
				$vEnd = $vStart + strlen( $m[0] );
			}
			$dict[ $key ] = trim( substr( $s, $vStart, $vEnd - $vStart ) );
			$pos          = $vEnd;
		}

		return $dict;
	}

	private function buildDict( array $dict ): string {
		$out = '<<';
		foreach ( $dict as $key => $value ) {
			$out .= ' /' . $key . ' ' . $value;
		}

		return $out . ' >>';
	}

	private function skipSpace( string $s, int $pos ): int {
		$len = strlen( $s );
		while ( $pos < $len ) {
			$c = $s[ $pos ];
			if ( '%' === $c ) {
				$nl  = strcspn( $s, "\r\n", $pos );
				$pos += $nl;
				continue;
			}
			if ( ! str_contains( " \t\r\n\f\0", $c ) ) {
				break;
			}
			++$pos;
		}

		return $pos;
	}

	/** Конец значения, начинающегося в $pos. */
	private function skipValue( string $s, int $pos ): int {
		$len = strlen( $s );
		$c   = $s[ $pos ] ?? '';

		if ( '<' === $c && '<' === ( $s[ $pos + 1 ] ?? '' ) ) {
			$pos += 2;
			while ( $pos < $len ) {
				$pos = $this->skipSpace( $s, $pos );
				if ( '>>' === substr( $s, $pos, 2 ) ) {
					return $pos + 2;
				}
				$pos = $this->skipValue( $s, $pos );
			}
			return $len;
		}
		if ( '<' === $c ) {
			$end = strpos( $s, '>', $pos );
			return false === $end ? $len : $end + 1;
		}
		if ( '[' === $c ) {
			++$pos;
			while ( $pos < $len ) {
				$pos = $this->skipSpace( $s, $pos );
				if ( ']' === ( $s[ $pos ] ?? '' ) ) {
					return $pos + 1;
				}
				$pos = $this->skipValue( $s, $pos );
			}
			return $len;
		}
		if ( '(' === $c ) {
			$depth = 0;
			for ( ; $pos < $len; $pos++ ) {
				$ch = $s[ $pos ];
				if ( '\\' === $ch ) {
					++$pos;
				} elseif ( '(' === $ch ) {
					++$depth;
				} elseif ( ')' === $ch && 0 === --$depth ) {
					return $pos + 1;
				}
			}
			return $len;
		}
		if ( '/' === $c ) {
			++$pos;
		}
		// Имя, число, true/false/null, R — до разделителя.
		$run = strcspn( $s, " \t\r\n\f\0/<>[]()%", $pos );

		return $pos + max( $run, '/' === $c ? 0 : 1 );
	}

	/** Строка PDF (литерал или hex, UTF-16BE с BOM либо PDFDocEncoding) → UTF-8. */
	private function decodeText( string $raw ): string {
		$raw = trim( $raw );
		if ( str_starts_with( $raw, '<' ) ) {
			$bytes = (string) hex2bin( preg_replace( '~[^0-9A-Fa-f]~', '', $raw ) . ( strlen( preg_replace( '~[^0-9A-Fa-f]~', '', $raw ) ) % 2 ? '0' : '' ) );
		} else {
			$bytes = preg_replace_callback(
				'~\\\\([0-7]{1,3}|.)~s',
				static fn( array $m ): string => match ( true ) {
					ctype_digit( $m[1] ) => chr( octdec( $m[1] ) & 0xFF ),
					'n' === $m[1]        => "\n",
					'r' === $m[1]        => "\r",
					't' === $m[1]        => "\t",
					'b' === $m[1]        => "\x08",
					'f' === $m[1]        => "\f",
					"\n" === $m[1], "\r" === $m[1] => '',
					default              => $m[1],
				},
				substr( $raw, 1, -1 )
			);
		}

		if ( str_starts_with( $bytes, "\xFE\xFF" ) ) {
			return (string) mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
		}

		return (string) mb_convert_encoding( $bytes, 'UTF-8', 'ISO-8859-1' );
	}

	/** Значение поля: UTF-16BE с BOM, hex-строкой. */
	private function textString( string $value ): string {
		return '<FEFF' . strtoupper( bin2hex( (string) mb_convert_encoding( $value, 'UTF-16BE', 'UTF-8' ) ) ) . '>';
	}

	/** Текст для Tj в кодировке шрифта (Windows-1251), hex-строкой. */
	private function hexCp1251( string $text ): string {
		$bytes = (string) mb_convert_encoding( $text, 'Windows-1251', 'UTF-8' );

		return '<' . strtoupper( bin2hex( $bytes ) ) . '>';
	}

	/**
	 * /Differences для 0x80–0xFF Windows-1251 (имена глифов — Adobe Glyph List).
	 */
	private function cp1251Differences(): string {
		$upper = array( 'А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я' );

		$names = array(
			0x84 => 'quotedblbase',
			0x85 => 'ellipsis',
			0x88 => 'Euro',
			0x91 => 'quoteleft',
			0x92 => 'quoteright',
			0x93 => 'quotedblleft',
			0x94 => 'quotedblright',
			0x95 => 'bullet',
			0x96 => 'endash',
			0x97 => 'emdash',
			0xA0 => 'space',
			0xA7 => 'section',
			0xA8 => 'afii10023', // Ё
			0xAB => 'guillemotleft',
			0xB0 => 'degree',
			0xB1 => 'plusminus',
			0xB7 => 'periodcentered',
			0xB8 => 'afii10071', // ё
			0xB9 => 'afii61352', // №
			0xBB => 'guillemotright',
		);

		// А..Я: afii10017.. с пропуском afii10023 (это Ё); а..я — те же +48.
		foreach ( $upper as $i => $unused ) {
			$afii                 = 10017 + $i + ( $i >= 6 ? 1 : 0 );
			$names[ 0xC0 + $i ]   = 'afii' . $afii;
			$names[ 0xE0 + $i ]   = 'afii' . ( $afii + 48 );
		}
		ksort( $names );

		$out = '[';
		foreach ( $names as $code => $name ) {
			$out .= ' ' . $code . ' /' . $name;
		}

		return $out . ' ]';
	}
}
