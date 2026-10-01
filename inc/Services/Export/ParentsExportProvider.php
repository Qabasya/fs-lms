<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Enums\Wp\MetaKeys;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class ParentsExportProvider
 *
 * Провайдер экспорта родителей (законных представителей) в CSV.
 *
 * @package Inc\Services\Export
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для родителей.
 * 2. **Генерация строк** — итеративная выгрузка данных родителей из БД.
 * 3. **Обогащение данных** — подстановка email, телефона, логина, пароля,
 *    списка детей, групп и предметов.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Поддерживает экспорт всех родителей или только выбранных по ID.
 *
 * ### Данные в CSV:
 *
 * - Личные данные: ФИО, email, телефон, логин, пароль
 * - Ключи связи: ID родителя и ID ученика (для ВПР со сводной таблицей)
 * - Связанные ученики (дети)
 * - По галочке `include_documents`: дата рождения, документ, ИНН и адрес родителя и его детей
 * - Группы и предметы, в которых обучаются дети
 *
 * ### Примечания:
 *
 * - Email и телефон расшифровываются из person_documents (если есть)
 * - Пароль расшифровывается из мета-поля пользователя (если сохранён)
 * - Расшифровка выполняется только для администраторов (экспорт)
 */
class ParentsExportProvider implements CsvExportProviderInterface {

	/**
	 * Колонки блока «документы и ИНН» (ключ данных => заголовок). Один набор на родителя
	 * и на его детей — у детей заголовки получают префикс «Ученик: ».
	 */
	private const DOCUMENT_FIELDS = array(
		'birth_date'      => 'Дата рожд.',
		'doc_type'        => 'Документ',
		'doc_number'      => 'Номер документа',
		'doc_issued_by'   => 'Кем выдан',
		'doc_issued_date' => 'Дата выдачи',
		'inn'             => 'ИНН',
		'address'         => 'Адрес',
	);

	/**
	 * Конструктор провайдера.
	 *
	 * @param PersonRepository          $persons         Репозиторий лиц
	 * @param StudentRecordRepository   $studentRecords  Репозиторий записей студентов
	 * @param PersonDocumentsRepository $personDocuments Репозиторий документов лиц
	 * @param GroupsRepository          $groups          Репозиторий групп
	 * @param SubjectRepository         $subjects        Репозиторий предметов
	 * @param UserRepository            $userRepository  Репозиторий пользователей WP
	 * @param PiiCryptoService          $crypto          Сервис шифрования PII
	 */
	public function __construct(
		private readonly PersonRepository          $persons,
		private readonly StudentRecordRepository   $studentRecords,
		private readonly PersonDocumentsRepository $personDocuments,
		private readonly GroupsRepository          $groups,
		private readonly SubjectRepository         $subjects,
		private readonly UserRepository            $userRepository,
		private readonly PiiCryptoService          $crypto,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * Колонка «Пароль» включается только по явному запросу
	 * ({@see StudentsExportProvider::wantsPasswords()}, A2).
	 *
	 * @param array<string, mixed> $context Контекст экспорта (include_passwords)
	 *
	 * @return CsvColumn[]
	 */
	public function columns( array $context = array() ): array {
		$columns = array(
			new CsvColumn( 'ID родителя',  fn( $r ) => $r['person_id'] ),
			// Ключ для ВПР со сводной таблицей: совпадает с «ID ученика» в экспорте учеников.
			// Детей несколько — ID через «; », в том же порядке, что и колонка «Ученики».
			new CsvColumn( 'ID ученика',   fn( $r ) => $r['student_ids'] ),
			new CsvColumn( 'Фамилия',      fn( $r ) => $r['last_name'] ),
			new CsvColumn( 'Имя',          fn( $r ) => $r['first_name'] ),
			new CsvColumn( 'Отчество',     fn( $r ) => $r['middle_name'] ),
			new CsvColumn( 'Email',        fn( $r ) => $r['email'] ),
			new CsvColumn( 'Телефон',      fn( $r ) => $r['phone'] ),
			new CsvColumn( 'Логин',        fn( $r ) => $r['login'] ),
		);

		if ( StudentsExportProvider::wantsPasswords( $context ) ) {
			$columns[] = new CsvColumn( 'Пароль', fn( $r ) => $r['password'] );
		}

		$columns[] = new CsvColumn( 'Ученики',  fn( $r ) => $r['students'] );
		$columns[] = new CsvColumn( 'Группы',   fn( $r ) => $r['groups'] );
		$columns[] = new CsvColumn( 'Предметы', fn( $r ) => $r['subjects'] );

		if ( self::wantsDocuments( $context ) ) {
			foreach ( self::DOCUMENT_FIELDS as $key => $header ) {
				$columns[] = new CsvColumn( $header, fn( $r ) => $r['parent_docs'][ $key ] ?? '' );
			}
			foreach ( self::DOCUMENT_FIELDS as $key => $header ) {
				$columns[] = new CsvColumn( 'Ученик: ' . $header, fn( $r ) => $r['student_docs'][ $key ] ?? '' );
			}
		}

		return $columns;
	}

	/**
	 * Запрошена ли выгрузка документов и ИНН (родителя и его детей).
	 *
	 * Единый предикат для {@see columns()} и {@see rows()}; по умолчанию выключен —
	 * паспортные данные и ИНН попадают в файл только по явной галочке в UI.
	 *
	 * @param array<string, mixed> $context Контекст экспорта
	 *
	 * @return bool
	 */
	public static function wantsDocuments( array $context ): bool {
		return true === ( $context['include_documents'] ?? false );
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает экспорт выбранных родителей или всех.
	 *
	 * @param array $context Контекст экспорта (ids — массив ID родителей,
	 *                       include_passwords — выгружать ли пароли)
	 *
	 * @return iterable
	 */
	public function rows( array $context ): iterable {
		$ids           = $context['ids'] ?? array();
		$withPasswords = StudentsExportProvider::wantsPasswords( $context );
		$withDocuments = self::wantsDocuments( $context );

		// Получение списка родителей (is_student = false)
		$persons = $ids
			? array_filter( array_map( fn( int $id ) => $this->persons->find( $id ), $ids ) )
			: $this->persons->findByIsStudent( false );

		foreach ( $persons as $parent ) {
			$docs   = $this->personDocuments->findByPersonId( $parent->id );
			$wpUser = $parent->wpUserId ? get_userdata( $parent->wpUserId ) : null;

			// Сбор данных о детях (учениках) через записи студента
			$records = $this->studentRecords->findAllByParent( $parent->id );

			$studentNames = array();
			$studentIds   = array();
			$students     = array(); // PersonDTO детей в порядке «ID ученика»
			$groupNames   = array();
			$subjectNames = array();

			foreach ( $records as $rec ) {
				$student = $this->persons->find( $rec->studentPersonId );
				if ( $student ) {
					$studentNames[]              = $student->fullName();
					$studentIds[ $student->id ]  = $student->id;
					$students[ $student->id ]    = $student;
				}
				if ( $rec->groupId ) {
					$group = $this->groups->findById( $rec->groupId );
					if ( $group ) {
						$groupNames[]   = $group->name;
						$subjectNames[] = $this->subjects->getByKey( $group->subject_key )?->name ?? $group->subject_key;
					}
				}
			}

			// Расшифровка пароля — только если пароли реально попадут в файл
			$password = '';
			if ( $withPasswords && $parent->wpUserId ) {
				$enc = $this->userRepository->getMeta( $parent->wpUserId, MetaKeys::EncPassword->value );
				if ( $enc ) {
					try {
						$password = $this->crypto->decrypt( (string) base64_decode( $enc ) );
					} catch ( \Throwable ) {
						// Не удалось расшифровать — оставляем пустым
					}
				}
			}

			yield array(
				'person_id'   => $parent->id,
				'student_ids' => implode( '; ', $studentIds ),
				'last_name'   => $parent->lastName,
				'first_name'  => $parent->firstName,
				'middle_name' => $parent->middleName ?? '',
				'email'       => $docs ? $this->decrypt( $docs->emailEnc ) : ( $wpUser?->user_email ?? '' ),
				'phone'       => $docs ? $this->decrypt( $docs->phoneEnc ) : '',
				'login'       => $wpUser?->user_email ?? $wpUser?->user_login ?? '',
				'password'    => $password,
				'students'    => implode( '; ', array_unique( $studentNames ) ),
				'groups'      => implode( '; ', array_unique( $groupNames ) ),
				'subjects'    => implode( '; ', array_unique( $subjectNames ) ),
				'parent_docs'  => $withDocuments ? $this->documentValues( $parent, $docs ) : array(),
				'student_docs' => $withDocuments ? $this->childrenDocumentValues( $students ) : array(),
			);
		}
	}

	/**
	 * Значения блока «документы и ИНН» одного лица (расшифрованные).
	 *
	 * @param \Inc\DTO\Person\PersonDTO               $person Лицо
	 * @param \Inc\DTO\Person\PersonDocumentsDTO|null $docs   Документы лица (null — не заведены)
	 *
	 * @return array<string, string> Ключи — {@see self::DOCUMENT_FIELDS}
	 */
	private function documentValues( \Inc\DTO\Person\PersonDTO $person, ?\Inc\DTO\Person\PersonDocumentsDTO $docs ): array {
		return array(
			'birth_date'      => $person->birthDate ?? '',
			'doc_type'        => $docs && $docs->docType ? ( \Inc\Enums\Person\DocumentType::tryFrom( $docs->docType )?->label() ?? $docs->docType ) : '',
			'doc_number'      => $docs ? $this->decrypt( $docs->docNumberEnc ) : '',
			'doc_issued_by'   => $docs ? $this->decrypt( $docs->docIssuedByEnc ) : '',
			'doc_issued_date' => $docs->docIssuedDate ?? '',
			'inn'             => $docs ? $this->decrypt( $docs->innEnc ) : '',
			'address'         => $docs ? $this->decrypt( $docs->addressEnc ) : '',
		);
	}

	/**
	 * Блок «документы и ИНН» детей родителя: значения каждого поля через «; » в порядке
	 * колонки «ID ученика» (пустое значение сохраняет своё место).
	 *
	 * @param array<int, \Inc\DTO\Person\PersonDTO> $students Дети родителя
	 *
	 * @return array<string, string>
	 */
	private function childrenDocumentValues( array $students ): array {
		$perField = array();
		foreach ( $students as $student ) {
			$values = $this->documentValues( $student, $this->personDocuments->findByPersonId( $student->id ) );
			foreach ( self::DOCUMENT_FIELDS as $key => $_ ) {
				$perField[ $key ][] = $values[ $key ];
			}
		}

		return array_map( static fn( array $list ): string => implode( '; ', $list ), $perField );
	}

	/**
	 * Возвращает базовое имя файла (без расширения).
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'parents';
	}

	/**
	 * Расшифровывает строку из зашифрованного BLOB.
	 *
	 * @param string|null $enc Зашифрованные данные
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