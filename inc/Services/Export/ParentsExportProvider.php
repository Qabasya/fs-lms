<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Enums\MetaKeys;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\PiiCryptoService;

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
 * - Связанные ученики (дети)
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
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID родителя',  fn( $r ) => $r['person_id'] ),
			new CsvColumn( 'Фамилия',      fn( $r ) => $r['last_name'] ),
			new CsvColumn( 'Имя',          fn( $r ) => $r['first_name'] ),
			new CsvColumn( 'Отчество',     fn( $r ) => $r['middle_name'] ),
			new CsvColumn( 'Email',        fn( $r ) => $r['email'] ),
			new CsvColumn( 'Телефон',      fn( $r ) => $r['phone'] ),
			new CsvColumn( 'Логин',        fn( $r ) => $r['login'] ),
			new CsvColumn( 'Пароль',       fn( $r ) => $r['password'] ),
			new CsvColumn( 'Ученики',      fn( $r ) => $r['students'] ),
			new CsvColumn( 'Группы',       fn( $r ) => $r['groups'] ),
			new CsvColumn( 'Предметы',     fn( $r ) => $r['subjects'] ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает экспорт выбранных родителей или всех.
	 *
	 * @param array $context Контекст экспорта (ids — массив ID родителей)
	 *
	 * @return iterable
	 */
	public function rows( array $context ): iterable {
		$ids = $context['ids'] ?? array();

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
			$groupNames   = array();
			$subjectNames = array();

			foreach ( $records as $rec ) {
				$student = $this->persons->find( $rec->studentPersonId );
				if ( $student ) {
					$studentNames[] = $student->fullName();
				}
				if ( $rec->groupId ) {
					$group = $this->groups->findById( $rec->groupId );
					if ( $group ) {
						$groupNames[]   = $group->name;
						$subjectNames[] = $this->subjects->getByKey( $group->subject_key )?->name ?? $group->subject_key;
					}
				}
			}

			// Расшифровка пароля (если сохранён в мета-поле)
			$password = '';
			if ( $parent->wpUserId ) {
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
			);
		}
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