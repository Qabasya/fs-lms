<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use PHPUnit\Framework\TestCase;

/**
 * Граница модуля мастер-классов: ядро о нём не знает.
 *
 * Модуль живёт в `inc/Modules/Masterclass/` и связан с ядром только универсальными швами (`fs_lms_*`), названными по смыслу
 * ядра, а не по мастер-классам. Поэтому ни в `inc/` (кроме самого модуля), ни в шаблонах и исходниках фронтенда нет слов
 * «masterclass» и «мастер-класс»: ни классов, ни таблиц, ни опций, ни экшенов, ни текстов.
 *
 * Тест зелёный до появления модуля и остаётся зелёным после него; красным он станет, как только кто-то начнёт
 * ссылаться на модуль из ядра. Правило и проверка этапа — `.docs/masterclass-feature/tasks/README.md`.
 */
class ModuleBoundaryTest extends TestCase {

	private const ROOT = __DIR__ . '/../../../..';

	/** Каталоги ядра, где модуль упоминаться не должен (относительно корня плагина). */
	private const CORE_DIRS = array( 'inc', 'templates', 'src' );

	/** Расширения текстовых файлов, которые читаются. */
	private const EXTENSIONS = array( 'php', 'js', 'mjs', 'scss', 'css', 'json', 'html', 'svg', 'md' );

	/** Сам модуль — единственное место, где слово законно. */
	private const MODULE_DIR = 'inc/Modules/Masterclass';

	/** @var non-empty-string */
	private const PATTERN = '/masterclass|мастер-?класс/iu';

	/**
	 * Единственное место ядра, которое называет модуль: его регистрация. Разрешены только строки с классом
	 * `MasterclassModule` (`use` и запись в `Init::getServices()`); любое другое упоминание в этом файле — нарушение.
	 *
	 * @var array<string, non-empty-string>
	 */
	private const ALLOWED_LINES = array(
		'inc/Init.php' => '/\bMasterclassModule\b/',
	);

	/**
	 * Файлы ядра, в которых слово встречается: путь => первая строка с вхождением.
	 *
	 * @param string $root Корень сканирования (для самопроверки сканера на подложенном дереве).
	 *
	 * @return array<string, string>
	 */
	private function offenders( string $root, array $dirs = self::CORE_DIRS ): array {
		$found = array();

		foreach ( $dirs as $dir ) {
			$base = $root . '/' . $dir;
			if ( ! is_dir( $base ) ) {
				continue;
			}

			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( ! $file->isFile() || ! in_array( strtolower( $file->getExtension() ), self::EXTENSIONS, true ) ) {
					continue;
				}

				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( rtrim( $root, '/\\' ) ) + 1 ) );
				if ( str_starts_with( $relative, self::MODULE_DIR . '/' ) ) {
					continue;
				}

				$allowed = self::ALLOWED_LINES[ $relative ] ?? null;
				foreach ( file( $file->getPathname(), FILE_IGNORE_NEW_LINES ) ?: array() as $n => $line ) {
					if ( null !== $allowed && 1 === preg_match( $allowed, $line ) ) {
						continue;
					}
					if ( 1 === preg_match( self::PATTERN, $line ) ) {
						$found[ $relative ] = ( $n + 1 ) . ': ' . trim( mb_substr( $line, 0, 120 ) );
						break;
					}
				}
			}
		}

		ksort( $found );

		return $found;
	}

	public function test_core_does_not_mention_the_masterclass_module(): void {
		self::assertSame(
			array(),
			$this->offenders( self::ROOT ),
			'ядро (inc/ вне модуля, templates/, src/) не должно знать слово «мастер-класс»: связь только через универсальные швы fs_lms_*'
		);
	}

	public function test_scanner_catches_a_mention_in_core_files(): void {
		$root = sys_get_temp_dir() . '/fs-lms-boundary-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/inc/Services', 0777, true );
		mkdir( $root . '/inc/Modules/Masterclass', 0777, true );
		mkdir( $root . '/templates', 0777, true );
		file_put_contents( $root . '/inc/Services/Leak.php', "<?php\n// ok line\nuse Inc\\Modules\\Masterclass\\Config;\n" );
		file_put_contents( $root . '/templates/leak.php', "<p>Записаться на мастер-класс</p>\n" );
		file_put_contents( $root . '/inc/Services/Clean.php', "<?php\n// ничего лишнего\n" );
		file_put_contents(
			$root . '/inc/Init.php',
			"<?php\nuse Inc\\Modules\\Masterclass\\MasterclassModule;\n\$a = MasterclassModule::class; // мастер-класс\n\$b = 'masterclass_leak';\n"
		);
		file_put_contents( $root . '/inc/Modules/Masterclass/Own.php', "<?php\n// Masterclass внутри модуля — можно\n" );

		try {
			$found = $this->offenders( $root, array( 'inc', 'templates' ) );

			self::assertSame( array( 'inc/Init.php', 'inc/Services/Leak.php', 'templates/leak.php' ), array_keys( $found ) );
			self::assertStringStartsWith( '4:', $found['inc/Init.php'], 'в Init.php разрешены только строки с классом модуля, остальные ловятся' );
			self::assertStringStartsWith( '3:', $found['inc/Services/Leak.php'], 'указана строка вхождения' );
			self::assertArrayNotHasKey( 'inc/Modules/Masterclass/Own.php', $found, 'модуль может называть себя' );
			self::assertArrayNotHasKey( 'inc/Services/Clean.php', $found );
		} finally {
			foreach ( array( '/inc/Init.php', '/inc/Services/Leak.php', '/inc/Services/Clean.php', '/inc/Modules/Masterclass/Own.php', '/templates/leak.php' ) as $f ) {
				@unlink( $root . $f );
			}
			foreach ( array( '/inc/Modules/Masterclass', '/inc/Modules', '/inc/Services', '/inc', '/templates', '' ) as $d ) {
				@rmdir( $root . $d );
			}
		}
	}
}
