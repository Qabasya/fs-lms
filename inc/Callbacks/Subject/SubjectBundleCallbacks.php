<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Subject;

use Inc\Core\BaseController;
use Inc\DTO\Subject\BundleOptionsDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Log\ExportLogWriter;
use Inc\Services\Subject\Bundle\SubjectBundlePackager;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class SubjectBundleCallbacks
 *
 * AJAX-обработчики полного пакета переноса предмета (Этап 6).
 *
 * @package Inc\Callbacks\Subject
 *
 * ### Обязанности
 *
 * Только транспорт: авторизация, разбор запроса, делегирование
 * {@see SubjectBundlePackager} и отправка ответа. Бизнес-логики нет.
 *
 * ### Права
 *
 * Пакет содержит весь контент предмета, а с включённым разделом учеников —
 * ещё и персональные данные с учётными записями. Поэтому операции требуют
 * `Capability::Admin` и отдельного nonce `Nonce::SubjectBundle`: в журнале
 * аудита перенос предмета обязан отличаться от обычной правки предмета.
 */
class SubjectBundleCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectBundlePackager $packager  Оркестратор упаковки/распаковки
	 * @param ExportLogWriter       $exportLog Журнал экспорта/импорта
	 */
	public function __construct(
		private readonly SubjectBundlePackager $packager,
		private readonly ExportLogWriter       $exportLog,
	) {
		parent::__construct();
	}

	/**
	 * Собирает ZIP-пакет предмета и отдаёт одноразовую ссылку.
	 *
	 * @return void
	 */
	public function ajaxExportSubjectBundle(): void {
		$this->authorize( Nonce::SubjectBundle, Capability::Admin );

		$key     = $this->requireKey( 'key', error: 'Не выбран предмет для экспорта.' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- нонс проверен в authorize() выше; каждое поле санитизирует BundleOptionsDTO::fromRequest().
		$options = BundleOptionsDTO::fromRequest( $_POST );

		try {
			$result = $this->packager->pack( $key, $options );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->exportLog->record( 'subject_bundle', 'single', array(), 'export', $result['counts'] );

		$this->success( $result );
	}

	/**
	 * Предпросмотр импорта пакета: что будет создано и какие есть конфликты.
	 *
	 * @return void
	 */
	public function ajaxPreviewSubjectBundle(): void {
		$this->authorize( Nonce::SubjectBundle, Capability::Admin );

		try {
			$report = $this->packager->preview( $this->uploadedArchivePath() );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( $report->toArray() );
	}

	/**
	 * Импортирует пакет предмета.
	 *
	 * @return void
	 */
	public function ajaxImportSubjectBundle(): void {
		$this->authorize( Nonce::SubjectBundle, Capability::Admin );

		try {
			$report = $this->packager->unpack( $this->uploadedArchivePath() );
		} catch ( InvalidArgumentException | RuntimeException $e ) {
			$this->error( $e->getMessage() );
			return;
		} catch ( \Throwable $e ) {
			// Импорт уже откатил созданное — отдаём текст, а не молчаливый 500.
			$this->error( $e->getMessage() );
			return;
		}

		$this->exportLog->record( 'subject_bundle', 'single', array(), 'import', $report->counts );

		// flush_rewrite_rules() — перестраивает ЧПУ после появления новых CPT/таксономий
		flush_rewrite_rules();

		$this->success( array_merge(
			$report->toArray(),
			array( 'message' => "Предмет «{$report->subjectName}» импортирован из пакета" )
		) );
	}

	/**
	 * Валидирует загруженный ZIP и возвращает путь к временному файлу.
	 *
	 * Размерный лимит здесь не хардкодится: потолок задаёт хостинг
	 * (`upload_max_filesize` / `post_max_size`), и придумывать более строгий
	 * собственный смысла нет — пакет с медиа легко перерастает любой круглый
	 * лимит. Для предметов крупнее лимита хостинга есть WP-CLI
	 * ({@see \Inc\Cli\SubjectBundleCommand}).
	 *
	 * @return string Путь к tmp-файлу
	 */
	private function uploadedArchivePath(): string {
		$file = $this->uploadedFile( 'bundle' );

		if ( null === $file || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			$this->error( $this->uploadErrorMessage( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) );
		}

		$extension = strtolower( (string) pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( 'zip' !== $extension ) {
			$this->error( 'Допустим только файл пакета формата .zip.' );
		}

		$tmpPath = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmpPath || ! is_uploaded_file( $tmpPath ) ) {
			$this->error( 'Некорректный загруженный файл.' );
		}

		return $tmpPath;
	}

	/**
	 * Человекочитаемое объяснение ошибки загрузки.
	 *
	 * Пакет с медиа — крупный файл, и упереться в лимит хостинга здесь легко;
	 * «файл не загружен» в этом случае бесполезная подсказка.
	 *
	 * @param int $code Код из $_FILES['...']['error']
	 *
	 * @return string
	 */
	private function uploadErrorMessage( int $code ): string {
		return match ( $code ) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
				'Пакет больше лимита загрузки на этом сервере (%s). Увеличьте upload_max_filesize/post_max_size '
					. 'или импортируйте через WP-CLI: wp fs-lms subject import <файл.zip>.',
				size_format( wp_max_upload_size() )
			),
			UPLOAD_ERR_PARTIAL  => 'Файл загружен не полностью — повторите загрузку.',
			UPLOAD_ERR_NO_FILE  => 'Файл пакета не выбран.',
			default             => 'Не удалось загрузить файл пакета (код ' . $code . ').',
		};
	}
}
