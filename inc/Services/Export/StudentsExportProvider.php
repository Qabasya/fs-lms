<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\DTO\Export\CsvColumn;

/**
 * Class StudentsExportProvider
 *
 * Провайдер экспорта студентов в CSV.
 *
 * @package Inc\Services\Export
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для студентов.
 * 2. **Генерация строк** — итеративная выгрузка данных студентов из БД.
 * 3. **Обогащение данных** — подстановка email, телефона, логина, пароля,
 *    групп и предметов.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Поддерживает экспорт всех студентов или только выбранных по ID.
 *
 * ### Данные в CSV:
 *
 * - Ключи связи: ID ученика и ID родителя (для ВПР со сводной таблицей)
 * - Личные данные: ФИО, дата рождения, класс, школа
 * - Контакты: email, телефон
 * - Учётные данные: логин, пароль
 * - Группы и предметы, в которых студент обучается
 *
 * ### Примечания:
 *
 * - Email и телефон расшифровываются из person_documents (если есть)
 * - Пароль расшифровывается из мета-поля пользователя (если сохранён)
 * - Расшифровка выполняется только для администраторов (экспорт)
 *
 * ### Колонка «Пароль» (A2)
 *
 * Пароль — самое чувствительное поле выгрузки, поэтому колонка появляется
 * в файле **только** при `$context['include_passwords'] === true` (явный
 * чекбокс в UI). По умолчанию файл содержит лишь контактные данные, и
 * расшифровка пароля вообще не выполняется.
 */
class StudentsExportProvider extends AbstractPersonExportProvider {

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @param array<string, mixed> $context Контекст экспорта (include_passwords)
	 *
	 * @return CsvColumn[]
	 */
	public function columns( array $context = array() ): array {
		$columns = array(
			new CsvColumn( 'ID ученика',   fn( $r ) => $r['person_id'] ),
			// Ключ для ВПР со сводной таблицей: совпадает с «ID родителя» в экспорте родителей.
			new CsvColumn( 'ID родителя',  fn( $r ) => $r['parent_ids'] ),
			new CsvColumn( 'Фамилия',      fn( $r ) => $r['last_name'] ),
			new CsvColumn( 'Имя',          fn( $r ) => $r['first_name'] ),
			new CsvColumn( 'Отчество',     fn( $r ) => $r['middle_name'] ),
			new CsvColumn( 'Дата рожд.',   fn( $r ) => $r['birth_date'] ),
			new CsvColumn( 'Класс',        fn( $r ) => $r['grade'] ),
			new CsvColumn( 'Школа',        fn( $r ) => $r['school'] ),
			new CsvColumn( 'Email',        fn( $r ) => $r['email'] ),
			new CsvColumn( 'Телефон',      fn( $r ) => $r['phone'] ),
			new CsvColumn( 'Логин',        fn( $r ) => $r['login'] ),
		);

		if ( self::wantsPasswords( $context ) ) {
			$columns[] = new CsvColumn( 'Пароль', fn( $r ) => $r['password'] );
		}

		$columns[] = new CsvColumn( 'Группы',   fn( $r ) => $r['groups'] );
		$columns[] = new CsvColumn( 'Предметы', fn( $r ) => $r['subjects'] );

		return $columns;
	}

	/**
	 * Запрошена ли выгрузка паролей.
	 *
	 * Единый предикат для {@see columns()} и {@see rows()} — колонка и её
	 * значение обязаны включаться/выключаться синхронно.
	 *
	 * @param array<string, mixed> $context Контекст экспорта
	 *
	 * @return bool
	 */
	public static function wantsPasswords( array $context ): bool {
		return true === ( $context['include_passwords'] ?? false );
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает экспорт выбранных студентов или всех.
	 *
	 * @param array $context Контекст экспорта (ids — массив ID студентов,
	 *                       include_passwords — выгружать ли пароли)
	 *
	 * @return iterable
	 */
	public function rows( array $context ): iterable {
		$ids            = $context['ids'] ?? array();
		$withPasswords  = self::wantsPasswords( $context );

		// Получение списка студентов (is_student = true)
		$persons = $this->selectPersons( $ids, true );

		foreach ( $persons as $person ) {
			$docs     = $this->personDocuments->findByPersonId( $person->id );
			// Активные записи студента (текущее зачисление)
			$records  = $this->studentRecords->findActiveByStudent( $person->id );
			$wpUser   = $person->wpUserId ? get_userdata( $person->wpUserId ) : null;

			// Сбор групп и предметов, в которых студент обучается
			$groupNames   = array();
			$subjectNames = array();
			$parentIds    = array();
			foreach ( $records as $rec ) {
				if ( $rec->parentPersonId ) {
					$parentIds[ $rec->parentPersonId ] = $rec->parentPersonId;
				}
				$names = $this->groupAndSubject( $rec->groupId );
				if ( $names ) {
					$groupNames[]   = $names['group'];
					$subjectNames[] = $names['subject'];
				}
			}

			// Расшифровка пароля — только если пароли реально попадут в файл
			$password = $withPasswords ? $this->decryptedPassword( $person->wpUserId ) : '';

			yield array(
				'person_id'   => $person->id,
				'parent_ids'  => implode( '; ', $parentIds ),
				'last_name'   => $person->lastName,
				'first_name'  => $person->firstName,
				'middle_name' => $person->middleName ?? '',
				'birth_date'  => $person->birthDate ?? '',
				'grade'       => $person->grade ?? '',
				'school'      => $person->school ?? '',
				'email'       => $docs ? $this->decrypt( $docs->emailEnc ) : '',
				'phone'       => $docs ? $this->decrypt( $docs->phoneEnc ) : '',
				'login'       => $wpUser?->user_login ?? '',
				'password'    => $password,
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
		return 'students';
	}
}
