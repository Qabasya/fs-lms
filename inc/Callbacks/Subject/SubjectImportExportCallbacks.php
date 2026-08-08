<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Subject;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Log\ExportLogWriter;
use Inc\Services\Subject\SubjectExportService;
use Inc\Services\Subject\SubjectImportService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class SubjectImportExportCallbacks
 *
 * AJAX-обработчики для импорта и экспорта предметов.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Экспорт предмета** — сбор всех данных предмета (таксономии, термины, посты, метабоксы, boilerplate) в JSON.
 * 2. **Импорт предмета** — восстановление предмета из JSON-файла со всеми связями.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику экспорта SubjectExportService, а импорта — SubjectImportService.
 * Сам отвечает только за авторизацию, валидацию входных данных и отправку ответа.
 */
class SubjectImportExportCallbacks extends BaseController {
	use Authorizer;   // Трейт с методами authorize(), requireKey(), error(), success()
	use Sanitizer;    // Трейт с методами sanitizeHtml() и др.

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository    $subjects       Репозиторий предметов (для проверки существования)
	 * @param SubjectExportService $export_service Сервис экспорта данных предмета
	 * @param SubjectImportService $import_service Сервис импорта данных предмета
	 * @param ExportLogWriter      $exportLog      Писатель журнала экспорта/импорта
	 */
	public function __construct(
		private readonly SubjectRepository   $subjects,
		private readonly SubjectExportService $export_service,
		private readonly SubjectImportService $import_service,
		private readonly ExportLogWriter      $exportLog,
	) {
		parent::__construct();
	}

	/**
	 * Экспортирует все данные предмета в JSON.
	 *
	 * @return void
	 */
	public function ajaxExportSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		// Получение и проверка существования предмета
		$key     = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$subject = $this->subjects->getByKey( $key );

		if ( ! $subject ) {
			$this->error( 'Предмет не найден', array( 'key' => $key ) );
		}

		// Формирование ответа: данные предмета + результат экспорта
		$this->success( array_merge(
			array( 'subject' => array( 'key' => $subject->key, 'name' => $subject->name, 'hasBank' => $subject->hasBank ) ),
			$this->export_service->export( $key )
		) );
	}

	/**
	 * Предпросмотр импорта предмета (dry-run, A7).
	 *
	 * Ничего не пишет в БД: считает объекты к созданию и возвращает конфликты,
	 * чтобы администратор увидел исход до необратимой записи.
	 *
	 * @return void
	 */
	public function ajaxPreviewSubjectImport(): void {
		$this->authorize( Nonce::Subject );

		$data = $this->decodeImportPayload();

		try {
			$report = $this->import_service->preview( $data );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( $report->toArray() );
	}

	/**
	 * Импортирует полные данные предмета из JSON.
	 *
	 * @return void
	 */
	public function ajaxImportSubject(): void {
		// Проверка прав доступа
		$this->authorize( Nonce::Subject );

		$data = $this->decodeImportPayload();

		try {
			// Делегирование импорта сервису
			$report = $this->import_service->import( $data );
		} catch ( \InvalidArgumentException $e ) {
			// Ошибка валидации данных (неверный формат, дубликат ключа)
			$this->error( $e->getMessage() );
			return;
		} catch ( \Throwable $e ) {
			// Любая другая ошибка: сервис уже откатил созданное (A5),
			// пользователю отдаём текст, а не молчаливый 500.
			$this->error( $e->getMessage() );
			return;
		}

		$this->exportLog->record( 'subject', 'single', array(), 'import' );

		// flush_rewrite_rules() — перестраивает правила ЧПУ после регистрации новых CPT/таксономий
		flush_rewrite_rules();

		// Отправка ответа об успешном импорте
		$this->success( array_merge(
			$report->toArray(),
			array( 'message' => "Предмет «{$report->subjectName}» успешно импортирован" )
		) );
	}

	/**
	 * Читает и декодирует JSON-файл импорта из запроса.
	 *
	 * Общий вход для dry-run и реального импорта — оба обязаны принимать
	 * ровно один и тот же payload, иначе предпросмотр перестаёт быть честным.
	 *
	 * @return array Декодированные данные
	 */
	private function decodeImportPayload(): array {
		$raw = $this->unslashRawString( 'json' );
		if ( empty( $raw ) ) {
			$this->error( 'JSON не передан' );
		}

		// json_decode(, true) — преобразует JSON в ассоциативный массив
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			$this->error( 'Неверный формат файла импорта', array( 'raw_length' => strlen( (string) $raw ) ) );
		}

		return $data;
	}
}