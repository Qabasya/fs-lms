<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Subject\BundleSection;
use Inc\Enums\Wp\MetaKeys;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class StudentBundleExporter
 *
 * Собирает раздел `students` пакета: учётки, зачисление и группы (Этап 5).
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Что переносится
 *
 * - `groups` — группы предмета (с ссылкой на курс, если курс в этом же пакете);
 * - `persons` — ученики и их представители (ФИО + контакты и документы);
 * - `records` — факт зачисления: ученик, родитель, группа, договор, приказ.
 *
 * ### Чего НЕ переносится (зафиксированное решение)
 *
 * Прогресс обучения: `group_lessons`, `lesson_progress`, `submissions`,
 * `assessment_attempts/answers`, `task_attempts`, `attendance`,
 * `learning_events`. На целевом сайте ученик зачислен и видит курс, но
 * начинает прохождение с нуля.
 *
 * Отчисленные записи тоже не переносятся: архив прошлых лет — это история
 * обучения на старом сайте, а не состав групп на новом.
 *
 * ### Персональные данные и пароли
 *
 * Контакты, документы и пароли учётных записей лежат в БД зашифрованными
 * ключом сайта. В пакет они попадают расшифрованными — иначе на целевом сайте
 * с другим `FS_LMS_ENC_KEY` их невозможно прочитать.
 *
 * Пароли переносятся намеренно: смысл переноса в том, чтобы ученик и родитель
 * вошли на новом сайте по прежним логину и паролю. Генерация новых паролей при
 * импорте означала бы ручную рассылку кредов всем перенесённым семьям.
 *
 * Отсюда прямое следствие: **архив с включённым разделом учеников содержит ПД
 * и пароли открытым текстом**. UI обязан об этом предупредить
 * ({@see \Inc\Services\Subject\Bundle\SubjectBundlePackager}), а сам файл —
 * жить ровно до первого скачивания.
 */
class StudentBundleExporter {

	/**
	 * Конструктор.
	 *
	 * @param GroupsRepository          $groups          Репозиторий групп
	 * @param StudentRecordRepository   $records         Репозиторий записей об обучении
	 * @param PersonRepository          $persons         Репозиторий лиц
	 * @param PersonDocumentsRepository $documents       Репозиторий документов и контактов
	 * @param UserRepository            $users           Репозиторий пользователей WP (пароли)
	 * @param PiiCryptoService          $crypto          Шифрование ПД
	 */
	public function __construct(
		private readonly GroupsRepository          $groups,
		private readonly StudentRecordRepository   $records,
		private readonly PersonRepository          $persons,
		private readonly PersonDocumentsRepository $documents,
		private readonly UserRepository            $users,
		private readonly PiiCryptoService          $crypto,
	) {}

	/**
	 * Собирает раздел учеников.
	 *
	 * @param string $subjectKey Ключ предмета
	 * @param array  $manifest   Уже собранный манифест (нужен для карты курсов)
	 *
	 * @return array{data: array<string, mixed>, count: int, warnings: string[]}
	 */
	public function collect( string $subjectKey, array $manifest ): array {
		$courseExportIds = $this->courseExportIds( $manifest );

		$groups   = array();
		$records  = array();
		$personIds = array();
		$warnings = array();

		foreach ( $this->groups->findBySubjectKey( $subjectKey ) as $group ) {
			$groupId   = (int) $group->id;
			$courseId  = (int) ( $group->course_id ?? 0 );
			$courseRef = $courseExportIds[ $courseId ] ?? null;

			if ( $courseId > 0 && null === $courseRef ) {
				$warnings[] = sprintf(
					'Группа «%s» назначена на курс, которого нет в пакете — на целевом сайте она приедет без программы.',
					(string) $group->name
				);
			}

			$groups[] = array(
				'source_id'   => $groupId,
				'name'        => (string) $group->name,
				'period_id'   => (string) ( $group->academic_period_id ?? '' ),
				'course_ref'  => $courseRef,
			);

			foreach ( $this->records->findActiveByGroupId( $groupId ) as $record ) {
				if ( EnrollmentStatus::Active !== $record->status ) {
					continue;
				}

				$personIds[ $record->studentPersonId ] = true;
				$personIds[ $record->parentPersonId ]  = true;

				$records[] = array(
					'group_source_id'     => $groupId,
					'student_person_ref'  => $record->studentPersonId,
					'parent_person_ref'   => $record->parentPersonId,
					'contract_no'         => $record->contractNo,
					'contract_date'       => $record->contractDate,
					'order_no'            => $record->orderNo,
					'order_date'          => $record->orderDate,
					'enrolled_at'         => $record->enrolledAt,
					'snapshot_last_name'  => $record->snapshotLastName,
					'snapshot_first_name' => $record->snapshotFirstName,
					'snapshot_middle_name'=> $record->snapshotMiddleName,
					'snapshot_school'     => $record->snapshotSchool,
					'snapshot_grade'      => $record->snapshotGrade,
				);
			}
		}

		$persons = array();
		foreach ( array_keys( $personIds ) as $personId ) {
			$snapshot = $this->person( (int) $personId );
			if ( null !== $snapshot ) {
				$persons[] = $snapshot;
			}
		}

		return array(
			'data'     => array(
				'groups'  => $groups,
				'persons' => $persons,
				'records' => $records,
			),
			'count'    => count( $records ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Карта «WP ID курса → `_export_id`» из уже собранного манифеста.
	 *
	 * Курсы в манифесте уже лишены `source_id` (он снят при ремапе), поэтому
	 * карта строится из `_export_id`: его вторая часть и есть исходный ID.
	 *
	 * @param array $manifest Манифест пакета
	 *
	 * @return array<int, string>
	 */
	private function courseExportIds( array $manifest ): array {
		$map = array();

		foreach ( (array) ( $manifest['posts'][ BundleSection::Courses->value ] ?? array() ) as $course ) {
			$exportId = (string) ( $course[ BundleSchema::EXPORT_ID ] ?? '' );
			$parts    = explode( ':', $exportId );

			if ( 2 === count( $parts ) && ctype_digit( $parts[1] ) ) {
				$map[ (int) $parts[1] ] = $exportId;
			}
		}

		return $map;
	}

	/**
	 * Снимок лица с расшифрованными контактами и документами.
	 *
	 * @param int $personId ID лица
	 *
	 * @return array<string, mixed>|null null — лицо не найдено
	 */
	private function person( int $personId ): ?array {
		$person = $this->persons->find( $personId );
		if ( null === $person ) {
			return null;
		}

		$docs   = $this->documents->findByPersonId( $personId );
		$wpUser = $person->wpUserId ? get_userdata( $person->wpUserId ) : null;

		return array(
			'source_id'       => $person->id,
			'last_name'       => $person->lastName,
			'first_name'      => $person->firstName,
			'middle_name'     => $person->middleName ?? '',
			'birth_date'      => $person->birthDate ?? '',
			'is_student'      => $person->isStudent,
			'school'          => $person->school ?? '',
			'grade'           => $person->grade ?? '',
			'login'           => $wpUser ? $wpUser->user_login : '',
			'password'        => $this->password( $person->wpUserId ),
			'email'           => $docs ? $this->decrypt( $docs->emailEnc ) : '',
			'phone'           => $docs ? $this->decrypt( $docs->phoneEnc ) : '',
			'doc_type'        => $docs->docType ?? '',
			'doc_number'      => $docs ? $this->decrypt( $docs->docNumberEnc ) : '',
			'doc_issued_by'   => $docs ? $this->decrypt( $docs->docIssuedByEnc ) : '',
			'doc_issued_date' => $docs->docIssuedDate ?? '',
			'inn'             => $docs ? $this->decrypt( $docs->innEnc ) : '',
			'address'         => $docs ? $this->decrypt( $docs->addressEnc ) : '',
		);
	}

	/**
	 * Пароль учётной записи в открытом виде.
	 *
	 * Хранится в мете пользователя зашифрованным (`fs_lms_enc_password`,
	 * дополнительно в base64) — тем же способом, что читает CSV-экспорт
	 * учеников. Переносится, чтобы семья вошла на новом сайте по прежним данным.
	 *
	 * @param int|null $wpUserId ID пользователя WP
	 *
	 * @return string Пустая строка, если учётки нет или пароль не сохранён
	 */
	private function password( ?int $wpUserId ): string {
		if ( ! $wpUserId ) {
			return '';
		}

		$enc = $this->users->getMeta( $wpUserId, MetaKeys::EncPassword->value );
		if ( ! $enc ) {
			return '';
		}

		try {
			return $this->crypto->decrypt( (string) base64_decode( (string) $enc ) );
		} catch ( \Throwable ) {
			// Пароль не читается (сменился ключ) — учётка приедет с новым паролем.
			return '';
		}
	}

	/**
	 * Расшифровывает поле ПД, не роняя экспорт на одной битой записи.
	 *
	 * @param string|null $enc Зашифрованное значение
	 *
	 * @return string
	 */
	private function decrypt( ?string $enc ): string {
		if ( ! $enc ) {
			return '';
		}

		try {
			return $this->crypto->decrypt( $enc );
		} catch ( \Throwable ) {
			return '';
		}
	}
}
