<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\Masterclass\DTO\MasterclassInputDTO;
use Inc\Modules\Masterclass\Repositories\MasterclassAccountRepository;
use Inc\Modules\Masterclass\Services\MasterclassAccountService;
use Inc\Modules\Masterclass\Services\MasterclassService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class MasterclassAdminCallbacks
 *
 * AJAX страницы «FS LMS → Мастер-классы» и печать флаеров. Право одно на всё — `ManageLmsPlatform`
 * (офис и администратор), nonce — `Nonce::Config`. Логики здесь нет: ввод санируется, дальше работают сервисы;
 * отказ сервиса (`\InvalidArgumentException`) уходит пользователю его же текстом.
 *
 * Страница присылает время мастер-класса в формате `Y-m-d H:i:s` (местное); строгий разбор — в сервисе.
 *
 * @package Inc\Modules\Masterclass\Callbacks
 */
class MasterclassAdminCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly MasterclassService $service,
		private readonly MasterclassAccountService $accounts,
		private readonly MasterclassAccountRepository $accountRows,
		private readonly ExamTime $time,
	) {
		parent::__construct();
	}

	public function ajaxCreate(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$input = $this->inputFromRequest();

		try {
			$masterclass = $this->service->create( $input, get_current_user_id() );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			$this->failWith( $e );
			return;
		}

		$this->success( array( 'id' => $masterclass->id ) );
	}

	public function ajaxUpdate(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$id    = $this->requireInt( 'id' );
		$input = $this->inputFromRequest();

		try {
			$masterclass = $this->service->update( $id, $input, get_current_user_id() );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			$this->failWith( $e );
			return;
		}

		$this->success( array( 'id' => $masterclass->id ) );
	}

	public function ajaxExtend(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$id   = $this->requireInt( 'id' );
		$days = $this->requireInt( 'days' );

		try {
			$masterclass = $this->service->extend( $id, $days, get_current_user_id() );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			$this->failWith( $e );
			return;
		}

		$this->success(
			array(
				'freeze_at' => $this->time->toLocal( $masterclass->freezeAt ),
				'purge_at'  => $this->time->toLocal( $masterclass->purgeAt ),
			)
		);
	}

	public function ajaxDelete(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$id = $this->requireInt( 'id' );

		try {
			$detached = $this->service->requestDelete( $id, get_current_user_id() );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			$this->failWith( $e );
			return;
		}

		$this->success( array( 'detached' => $detached ) );
	}

	/** Одна порция аккаунтов; страница крутит цикл запросов, пока не наберёт нужное число. */
	public function ajaxAddAccounts(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$id    = $this->requireInt( 'id' );
		$count = $this->requireInt( 'count' );

		try {
			$created = $this->accounts->createChunk( $id, $count, get_current_user_id() );
			$total   = $this->accountRows->countLive( $id );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			$this->failWith( $e );
			return;
		}

		$this->success( array( 'created' => $created, 'total' => $total ) );
	}

	public function ajaxUnlock(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$id = $this->requireInt( 'id' );

		try {
			$unlocked = $this->accounts->unlockLogins( $id, get_current_user_id() );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			$this->failWith( $e );
			return;
		}

		$this->success( array( 'unlocked' => $unlocked ) );
	}

	public function ajaxLessons(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$this->success( $this->service->lessonOptions( $this->requireKey( 'subject_key' ) ) );
	}

	/**
	 * Печатная форма флаеров (admin-post). Не AJAX: nonce приходит в адресе (`security`), право проверяется напрямую
	 * (`authorize()` отвечает JSON, а здесь нужен обычный документ или `wp_die`). Документ выводится целиком и
	 * запрос завершается: страница меню выводится после шапки админки, «без обвязки» там не получить.
	 */
	public function printFlyers(): void {
		Nonce::Config->verify();

		if ( ! current_user_can( Capability::ManageLmsPlatform->value ) ) {
			wp_die( 'Недостаточно прав.', 403 );
		}

		try {
			$html = $this->flyersDocument( $this->sanitizeGetInt( 'id' ) );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );

			return; // wp_die() не возвращается; return — для читателя и анализатора.
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- шаблон экранирует сам.
		exit;
	}

	/**
	 * HTML-документ флаеров мастер-класса. Выдача паролей пишется в журнал (без самих паролей) — это делает сервис.
	 *
	 * @throws \InvalidArgumentException Мастер-класса нет.
	 */
	public function flyersDocument( int $masterclassId ): string {
		$masterclass = $this->service->find( $masterclassId );
		if ( null === $masterclass ) {
			throw new \InvalidArgumentException( 'Мастер-класс не найден.' );
		}

		$flyers = $this->accounts->flyers( $masterclassId, get_current_user_id() );

		$school_name = $masterclass->schoolName;
		$stylesheet  = $this->assetUrl( 'flyers.css' );
		$script      = $this->assetUrl( 'flyers.js' );
		$template    = $this->path( 'inc/Modules/Masterclass/templates/flyers.php' );

		ob_start();
		( static function () use ( $template, $flyers, $school_name, $stylesheet, $script ): void {
			require $template;
		} )();

		return (string) ob_get_clean();
	}

	/** Поля формы создания и правки. Время приходит местным (`Y-m-d H:i:s`). */
	private function inputFromRequest(): MasterclassInputDTO {
		return new MasterclassInputDTO(
			schoolName:    $this->requireText( 'school_name', 'POST', 'Укажите школу.' ),
			subjectKey:    $this->requireKey( 'subject_key', 'POST', 'Выберите предмет.' ),
			lessonId:      $this->requireInt( 'lesson_id', 'POST', 'Выберите урок.' ),
			teacherUserId: $this->requireInt( 'teacher_user_id', 'POST', 'Выберите преподавателя.' ),
			startsAtLocal: $this->sanitizeText( 'starts_at' ),
			endsAtLocal:   $this->sanitizeText( 'ends_at' ),
		);
	}

	/** Отказ или сбой сервиса — пользователю его текст; сбой хранилища ещё и в журнал PHP. */
	private function failWith( \InvalidArgumentException|\RuntimeException $e ): void {
		if ( ! $e instanceof \InvalidArgumentException ) {
			PluginLogger::exception( 'MasterclassAdmin', $e, array(), true );
		}

		$this->error( $e->getMessage() );
	}

	/** Адрес файла из `assets/` модуля с версией по времени изменения (сброс кеша при правке). */
	private function assetUrl( string $file ): string {
		$rel  = 'inc/Modules/Masterclass/assets/' . $file;
		$path = $this->path( $rel );

		return $this->url( $rel ) . '?ver=' . ( file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version );
	}
}
