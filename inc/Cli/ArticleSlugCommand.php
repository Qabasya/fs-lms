<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\DTO\Article\ArticleSlugChangeDTO;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\ArticleSlugPlanner;
use WP_CLI;

/**
 * Class ArticleSlugCommand
 *
 * WP-CLI: приведение слагов статей к правилу `article-task-{задание}-{номер}`.
 *
 * @package Inc\Cli
 *
 * ### Зачем команда
 *
 * Автогенерация слага работает на сохранении статьи и замораживает адрес при
 * первой публикации — статьи, изданные раньше, сами не переименуются. Команда
 * перенумеровывает серии целиком по порядку чтения и оставляет за собой
 * `_wp_old_slug`, чтобы прежние адреса отдавали 301.
 *
 * ### Использование
 *
 * ```
 * wp fs-lms article reslug [<subject>] [--dry-run] [--include-drafts]
 * ```
 *
 * В Docker-окружении проекта:
 * `docker compose run --rm wpcli wp fs-lms article reslug math --dry-run`
 */
class ArticleSlugCommand implements ServiceInterface {

	/**
	 * @param ArticleSlugPlanner $planner  Планировщик переименования.
	 * @param SubjectRepository  $subjects Предметы.
	 */
	public function __construct(
		private readonly ArticleSlugPlanner $planner,
		private readonly SubjectRepository $subjects,
	) {}

	/**
	 * Регистрирует команду, если плагин запущен под WP-CLI.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'fs-lms article reslug', array( $this, 'reslug' ) );
	}

	/**
	 * Переименовывает слаги статей предмета.
	 *
	 * ## OPTIONS
	 *
	 * [<subject>]
	 * : Ключ предмета. Без него — все предметы с банком.
	 *
	 * [--dry-run]
	 * : Только показать план, ничего не записывая.
	 *
	 * [--include-drafts]
	 * : Переименовать и черновики. Номера опубликованных статей от этого не сдвигаются.
	 *
	 * @param string[]              $args      Позиционные аргументы.
	 * @param array<string, string> $assocArgs Именованные аргументы.
	 *
	 * @return void
	 */
	public function reslug( array $args, array $assocArgs ): void {
		$key           = (string) ( $args[0] ?? '' );
		$dryRun        = isset( $assocArgs['dry-run'] );
		$includeDrafts = isset( $assocArgs['include-drafts'] );

		$keys = $this->subjectKeys( $key );

		if ( array() === $keys ) {
			WP_CLI::error( '' !== $key ? "Предмет «{$key}» не найден или у него нет банка." : 'Предметов с банком нет.' );
			return;
		}

		$total = 0;

		foreach ( $keys as $subjectKey ) {
			$total += $this->runForSubject( $subjectKey, $dryRun, $includeDrafts );
		}

		if ( 0 === $total ) {
			WP_CLI::success( 'Все слаги уже соответствуют правилу — менять нечего.' );
			return;
		}

		WP_CLI::success( $dryRun
			? sprintf( 'План готов: под переименование попадает статей — %d. Повторите без --dry-run.', $total )
			: sprintf( 'Переименовано статей: %d.', $total )
		);
	}

	/**
	 * Обрабатывает один предмет.
	 *
	 * @param string $subjectKey    Ключ предмета.
	 * @param bool   $dryRun        Не записывать.
	 * @param bool   $includeDrafts Включить черновики.
	 *
	 * @return int Сколько статей попало под переименование.
	 */
	private function runForSubject( string $subjectKey, bool $dryRun, bool $includeDrafts ): int {
		$changes = $this->planner->plan( $subjectKey, $includeDrafts );

		if ( array() === $changes ) {
			WP_CLI::log( "[{$subjectKey}] менять нечего." );

			return 0;
		}

		WP_CLI::log( "[{$subjectKey}] статей под переименование: " . count( $changes ) );

		WP_CLI\Utils\format_items(
			'table',
			array_map( static fn( ArticleSlugChangeDTO $change ): array => $change->toRow(), $changes ),
			array( 'ID', 'статус', 'задание', 'было', 'станет', 'заголовок' )
		);

		if ( $dryRun ) {
			return count( $changes );
		}

		return $this->planner->apply( $changes );
	}

	/**
	 * Ключи предметов, по которым идти.
	 *
	 * @param string $key Ключ из аргумента; пустой — все предметы с банком.
	 *
	 * @return string[]
	 */
	private function subjectKeys( string $key ): array {
		$subjects = array_filter(
			$this->subjects->readAll(),
			static fn( SubjectDTO $subject ): bool => $subject->hasBank && ( '' === $key || $key === $subject->key )
		);

		return array_map( static fn( SubjectDTO $subject ): string => $subject->key, $subjects );
	}
}