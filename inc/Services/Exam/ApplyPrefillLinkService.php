<?php
/**
 * Ссылка на форму заявки (/apply/) с заполненными данными гостя экзамена.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;

/**
 * Личные данные в адрес не попадают: ссылка несёт только номер гостя и срок под подписью,
 * а поля читаются из базы при показе формы. Подпись и срок проверяются при каждом открытии.
 *
 * Данные формы — то, что известно о госте: ФИО, телефон, школа, класс и направление проведения,
 * на которое он записан. Города у гостя нет — остаётся значение формы по умолчанию.
 */
class ApplyPrefillLinkService {

	/** Параметр адреса со ссылкой. */
	public const QUERY_ARG = 'guest';

	/** Срок действия ссылки, дней. */
	private const TTL_DAYS = 30;

	/** Получает участников, расшифровку данных и часы. */
	public function __construct(
		private readonly ExamParticipantRepository $participants,
		private readonly GuestParticipantMaterializer $guestData,
	) {}

	/** Ссылка на форму заявки для гостя; действует {@see self::TTL_DAYS} дней. */
	public function issue( int $participantId ): string {
		$payload = $participantId . '.' . ( time() + self::TTL_DAYS * DAY_IN_SECONDS );

		return add_query_arg( self::QUERY_ARG, $payload . '.' . $this->sign( $payload ), PageRoutes::Apply->url() );
	}

	/**
	 * Данные для заполнения формы по токену из адреса.
	 *
	 * @return array{subject_key: string, last_name: string, first_name: string, middle_name: string, phone: string, school: string, grade: string}|null
	 *         null — токен неверный, просрочен или гость обезличен.
	 */
	public function resolve( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->sign( $parts[0] . '.' . $parts[1] ), $parts[2] ) || (int) $parts[1] < time() ) {
			return null;
		}

		$participant = $this->participants->find( (int) $parts[0] );
		if ( null === $participant || null !== $participant->anonymizedAt || null !== $participant->personId ) {
			return null;
		}

		try {
			$name  = preg_split( '/\s+/u', trim( $this->guestData->decryptName( $participant ) ), 3 ) ?: array();
			$phone = $this->guestData->decryptContacts( $participant )['phone'];
		} catch ( \Throwable ) {
			return null;
		}

		return array(
			'subject_key' => $this->participants->guestEventSubjectKey( $participant->id ),
			'last_name'   => (string) ( $name[0] ?? '' ),
			'first_name'  => (string) ( $name[1] ?? '' ),
			'middle_name' => (string) ( $name[2] ?? '' ),
			'phone'       => $phone,
			'school'      => (string) ( $participant->schoolName ?? '' ),
			'grade'       => null !== $participant->grade ? (string) $participant->grade : '',
		);
	}

	/** Подпись части токена. */
	private function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', 'apply-prefill|' . $payload, wp_salt( 'auth' ) ), 0, 32 );
	}
}
