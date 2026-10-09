<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Превращает заявку гостя в участника при подтверждении оплаты (этап 11a.1.4) и читает его данные для экранов сотрудника.
 *
 * До оплаты участника нет: заявка хранит только зашифрованный черновик формы. Гость — не `Person`, не ученик и не пользователь WordPress:
 * `persons`, `student_records` и `wp_users` не затрагиваются. Выделен из `GuestApplicationService`, чтобы `ExamHoldService::convert()`
 * мог вызывать создание участника без цикла зависимостей (сервис заявок сам зависит от `ExamHoldService`).
 */
class GuestParticipantMaterializer {

	public function __construct(
		private readonly ExamParticipantRepository $participants,
		private readonly PiiCryptoService $crypto,
		private readonly GuestIdentity $identity,
		private readonly ExamTime $time,
	) {}

	/**
	 * Шифрует значение для текстовых колонок `exam_*`: шифртекст бинарный, поэтому хранится в base64
	 * (в колонку `text`/`longtext` сырые байты не ложатся — запись отвергается как неверная кодировка).
	 */
	public function seal( string $plaintext ): string {
		return base64_encode( $this->crypto->encrypt( $plaintext ) );
	}

	/**
	 * ФИО гостя для сотрудника и страницы входа; null — имени нет (обезличен или не расшифровалось).
	 * Единственное место расшифровки имени гостя: таблица сеанса, проверка работы, страница входа.
	 */
	public function displayName( \Inc\DTO\Exam\ExamParticipantDTO $participant ): ?string {
		if ( null === $participant->nameEnc || null !== $participant->anonymizedAt ) {
			return null;
		}

		try {
			$name = trim( $this->unseal( $participant->nameEnc ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		return '' !== $name ? $name : null;
	}

	/** Обратное к {@see seal()}. */
	public function unseal( string $stored ): string {
		return $this->crypto->decrypt( (string) base64_decode( $stored, true ) );
	}

	/** Создаёт участника из черновика заявки; школа и класс — из снимка источника. */
	public function materialize( ExamGuestApplicationDTO $application ): int {
		$draft    = $this->decodeDraft( $application );
		$snapshot = null !== $application->sourceSnapshot ? json_decode( $application->sourceSnapshot, true ) : null;
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$now      = $this->time->nowUtc();

		$last   = (string) ( $draft['last_name'] ?? '' );
		$first  = (string) ( $draft['first_name'] ?? '' );
		$middle = (string) ( $draft['middle_name'] ?? '' );
		$phone  = (string) ( $draft['phone'] ?? '' );

		$row = array( 'created_at' => $now, 'updated_at' => $now );
		if ( '' !== trim( $last . $first ) ) {
			$row['name_enc']  = $this->seal( trim( implode( ' ', array_filter( array( $last, $first, $middle ) ) ) ) );
			$row['name_hash'] = $this->identity->nameHash( $last, $first, $middle );
		}
		if ( '' !== $phone ) {
			$row['phone_enc']  = $this->seal( $phone );
			$row['phone_hash'] = $this->identity->phoneHash( $phone );
		}
		if ( '' !== (string) ( $draft['messenger'] ?? '' ) ) {
			$row['messenger_enc'] = $this->seal( (string) $draft['messenger'] );
		}
		if ( '' !== (string) ( $snapshot['school_name'] ?? '' ) ) {
			$row['school_name'] = (string) $snapshot['school_name'];
		}
		if ( '' !== (string) ( $snapshot['school_key'] ?? '' ) ) {
			$row['school_key'] = (string) $snapshot['school_key'];
		}
		if ( (int) ( $snapshot['grade'] ?? 0 ) > 0 ) {
			$row['grade'] = (int) $snapshot['grade'];
		}

		$id = $this->participants->insert( $row );
		if ( 0 === $id ) {
			throw new \RuntimeException( 'Не удалось создать участника экзамена.' );
		}

		return $id;
	}

	/** ФИО из черновика заявки — для корзины и страницы «Спасибо», пока участника ещё нет. Расшифровка не удалась — пусто. */
	public function draftName( ExamGuestApplicationDTO $application ): string {
		try {
			$draft = $this->decodeDraft( $application );
		} catch ( \Throwable ) {
			return '';
		}

		return trim( implode( ' ', array_filter( array( (string) ( $draft['last_name'] ?? '' ), (string) ( $draft['first_name'] ?? '' ), (string) ( $draft['middle_name'] ?? '' ) ) ) ) );
	}

	/** ФИО гостя для экранов сотрудника; расшифровка не удалась или данных нет — «Гость #id». */
	public function decryptName( ExamParticipantDTO $participant ): string {
		if ( null === $participant->nameEnc || '' === $participant->nameEnc ) {
			return sprintf( 'Гость #%d', $participant->id );
		}

		try {
			return $this->unseal( $participant->nameEnc );
		} catch ( \Throwable ) {
			return sprintf( 'Гость #%d', $participant->id );
		}
	}

	/**
	 * Контакты гостя (телефон, мессенджер). Только для вызывающего с правом `ManageExamGuests`; доступ к ПД пишет вызывающий код.
	 *
	 * @return array{phone: string, messenger: string}
	 */
	public function decryptContacts( ExamParticipantDTO $participant ): array {
		return array(
			'phone'     => null !== $participant->phoneEnc && '' !== $participant->phoneEnc ? $this->unseal( $participant->phoneEnc ) : '',
			'messenger' => null !== $participant->messengerEnc && '' !== $participant->messengerEnc ? $this->unseal( $participant->messengerEnc ) : '',
		);
	}

	/** @return array<string, mixed> */
	private function decodeDraft( ExamGuestApplicationDTO $application ): array {
		if ( null === $application->draftEnc || '' === $application->draftEnc ) {
			return array();
		}

		$decoded = json_decode( $this->unseal( $application->draftEnc ), true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
