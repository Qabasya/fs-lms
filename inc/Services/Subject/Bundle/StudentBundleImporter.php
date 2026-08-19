<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Enrollment\AccountProvisioningService;
use Inc\Services\Import\StudentRecordWriter;
use Inc\Shared\PluginLogger;
use RuntimeException;

/**
 * Class StudentBundleImporter
 *
 * Восстанавливает раздел `students` пакета: группы, лица, зачисление, учётки (Этап 5).
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Переиспользование, а не второй механизм
 *
 * Создание учёток и лиц идёт через те же {@see StudentRecordWriter} и
 * {@see AccountProvisioningService}, что и CSV-импорт учеников. Отличается
 * только источник входных данных: не строка CSV, а объект манифеста. Свой
 * механизм создания пользователей здесь заводить нельзя — он разъедется с
 * основным по правилам ролей, генерации паролей и привязке person↔user.
 *
 * ### Что не переносится
 *
 * Прогресс обучения. Ученик на целевом сайте зачислен в группу, видит
 * назначенный ей курс — и проходит его с нуля.
 *
 * ### Учебный период
 *
 * ID периода с сайта-источника на целевом сайте может не существовать: это
 * независимая настройка. Такие группы привязываются к текущему периоду
 * целевого сайта с предупреждением — иначе группа окажется в несуществующем
 * периоде и просто не отобразится в интерфейсе.
 *
 * ### Пароли
 *
 * Пароли переносятся вместе с учётками: на сайте-источнике они хранятся
 * зашифрованными и расшифровываются при сборке пакета, поэтому на целевом сайте
 * восстанавливаются как есть. Ученик и родитель входят по прежним логину и
 * паролю — рассылать новые креды не нужно.
 *
 * Новый пароль генерируется только там, где в пакете его нет (учётка без
 * сохранённой копии пароля); такие случаи попадают в предупреждения отчёта.
 */
class StudentBundleImporter {

	/**
	 * Конструктор.
	 *
	 * @param GroupsRepository           $groups        Репозиторий групп
	 * @param StudentRecordRepository    $records       Репозиторий записей об обучении
	 * @param StudentRecordWriter        $writer        Резолв/создание группы и лиц (общий с CSV-импортом)
	 * @param AccountProvisioningService $provisioning  Создание WP-учёток (общий с CSV-импортом)
	 * @param AcademicPeriodRepository   $periods       Репозиторий учебных периодов
	 * @param PostManager                $posts         Менеджер записей (проверка курса)
	 * @param ClockInterface             $clock         Текущее время
	 * @param CourseAssignmentService    $courseAssignment Снапшот уроков курса в КТП группы
	 */
	public function __construct(
		private readonly GroupsRepository           $groups,
		private readonly StudentRecordRepository    $records,
		private readonly StudentRecordWriter        $writer,
		private readonly AccountProvisioningService $provisioning,
		private readonly AcademicPeriodRepository   $periods,
		private readonly PostManager                $posts,
		private readonly ClockInterface             $clock,
		private readonly CourseAssignmentService     $courseAssignment,
	) {}

	/**
	 * Восстанавливает группы, лица и зачисление.
	 *
	 * @param array               $students   Раздел `students` манифеста
	 * @param string              $subjectKey Ключ импортированного предмета
	 * @param ExportIdMapper      $mapper     Карта `_export_id → новый WP ID` (для курса группы)
	 * @param ImportedEntitiesCollector $created    Журнал созданного (для отката)
	 *
	 * @return array{count: int, warnings: string[], credentials: array<int, array<string, string>>}
	 *
	 * @throws RuntimeException При ошибке создания группы
	 */
	public function restore( array $students, string $subjectKey, ExportIdMapper $mapper, ImportedEntitiesCollector $created ): array {
		$warnings    = array();
		$credentials = array();

		$periodId = $this->resolvePeriodId( $warnings );
		$groupMap = $this->restoreGroups(
			(array) ( $students['groups'] ?? array() ),
			$subjectKey,
			$periodId,
			$mapper,
			$created,
			$warnings
		);

		$personMap = $this->restorePersons(
			(array) ( $students['persons'] ?? array() ),
			$created,
			$credentials,
			$warnings
		);

		$enrolled = $this->restoreRecords(
			(array) ( $students['records'] ?? array() ),
			$groupMap,
			$personMap,
			$warnings
		);

		return array(
			'count'       => $enrolled,
			'warnings'    => $warnings,
			'credentials' => $credentials,
		);
	}

	/**
	 * Создаёт группы и возвращает карту «ID источника → новый ID».
	 *
	 * @param array               $groups     Группы из манифеста
	 * @param string              $subjectKey Ключ предмета
	 * @param string              $periodId   ID учебного периода целевого сайта
	 * @param ExportIdMapper      $mapper     Карта записей (для резолва курса)
	 * @param ImportedEntitiesCollector $created    Журнал созданного
	 * @param string[]            $warnings   Аккумулятор предупреждений (по ссылке)
	 *
	 * @return array<int, int>
	 *
	 * @throws RuntimeException При ошибке создания группы
	 */
	private function restoreGroups(
		array $groups,
		string $subjectKey,
		string $periodId,
		ExportIdMapper $mapper,
		ImportedEntitiesCollector $created,
		array &$warnings
	): array {
		$map = array();
		$now = $this->clock->now( 'mysql', true );

		foreach ( $groups as $group ) {
			$sourceId = (int) ( $group['source_id'] ?? 0 );
			$name     = sanitize_text_field( (string) ( $group['name'] ?? '' ) );

			if ( $sourceId <= 0 || '' === $name ) {
				continue;
			}

			// Идемпотентность: повторный импорт в тот же период не задваивает группы.
			$existing = $this->groups->findByNameSubjectPeriod( $name, $subjectKey, $periodId );
			if ( $existing ) {
				$map[ $sourceId ] = (int) $existing->id;
				$warnings[]       = "Группа «{$name}» уже существует в текущем периоде — ученики зачислены в неё.";
				continue;
			}

			$data = array(
				'name'               => $name,
				'subject_key'        => $subjectKey,
				'academic_period_id' => $periodId,
				'teacher_id'         => null,
				'meetings'           => null,
				'created_at'         => $now,
				'updated_at'         => $now,
			);

			$courseId = $this->resolveCourseId( (string) ( $group['course_ref'] ?? '' ), $mapper );
			if ( $courseId > 0 ) {
				$data['course_id'] = $courseId;
			}

			$groupId = $this->groups->create( $data );
			if ( $groupId <= 0 ) {
				throw new RuntimeException( "Не удалось создать группу «{$name}»." );
			}

			$created->addGroup( $groupId );
			$map[ $sourceId ] = $groupId;

			// `course_id` сам по себе КТП не наполняет — group_lessons снапшотится
			// только через CourseAssignmentService::assign(). Без этого шага группа
			// приезжает с назначенным курсом, но без единого урока в программе, и
			// учителю пришлось бы самому открыть КТП и назначить курс заново — то
			// же действие, которое импорт может сделать сам.
			if ( $courseId > 0 ) {
				try {
					$this->courseAssignment->assign( $groupId, $courseId, get_current_user_id() ?: 0 );
				} catch ( \Throwable $e ) {
					PluginLogger::exception( 'SUBJECT_BUNDLE', $e, array( 'group_id' => $groupId, 'course_id' => $courseId ), true );
					$warnings[] = sprintf(
						'Группе «%s» назначен курс, но КТП не удалось заполнить уроками: %s. Назначьте курс вручную в КТП.',
						$name,
						$e->getMessage()
					);
				}
			}
		}

		return $map;
	}

	/**
	 * Создаёт (или находит) лица и учётки, возвращает карту «ID источника → новый ID».
	 *
	 * @param array               $persons     Лица из манифеста
	 * @param ImportedEntitiesCollector $created     Журнал созданного
	 * @param array               $credentials Аккумулятор новых учётных данных (по ссылке)
	 * @param string[]            $warnings    Аккумулятор предупреждений (по ссылке)
	 *
	 * @return array<int, int>
	 */
	private function restorePersons(
		array $persons,
		ImportedEntitiesCollector $created,
		array &$credentials,
		array &$warnings
	): array {
		$map = array();

		foreach ( $persons as $person ) {
			$sourceId = (int) ( $person['source_id'] ?? 0 );
			if ( $sourceId <= 0 ) {
				continue;
			}

			$input = $this->toPersonInput( $person );

			// Лицо могло уже быть на целевом сайте (тот же ребёнок на другом
			// предмете) — тогда переиспользуем и в журнал отката не пишем.
			$personId = $this->writer->resolvePersonId( $input );
			$isNew    = null === $personId;

			if ( $isNew ) {
				$personId = $this->writer->createPerson( $input );
				$created->addPerson( $personId );
			}

			$map[ $sourceId ] = $personId;

			$this->provisionAccount( $person, $input, $personId, $isNew, $created, $credentials, $warnings );
		}

		return $map;
	}

	/**
	 * Создаёт WP-учётку для лица, если её ещё нет.
	 *
	 * Провизия отделена от создания лица и не роняет импорт: коллизия логина —
	 * ситуация целевого сайта, а не ошибка пакета. Такой ученик приедет
	 * зачисленным, но без учётки, и это видно в предупреждениях.
	 *
	 * @param array               $person      Сырые данные лица из манифеста
	 * @param PersonInputDTO      $input       Подготовленный ввод
	 * @param int                 $personId    ID лица на целевом сайте
	 * @param bool                $isNew       Лицо создано этим импортом
	 * @param ImportedEntitiesCollector $created     Журнал созданного
	 * @param array               $credentials Аккумулятор учётных данных (по ссылке)
	 * @param string[]            $warnings    Аккумулятор предупреждений (по ссылке)
	 *
	 * @return void
	 */
	private function provisionAccount(
		array $person,
		PersonInputDTO $input,
		int $personId,
		bool $isNew,
		ImportedEntitiesCollector $created,
		array &$credentials,
		array &$warnings
	): void {
		if ( ! $isNew ) {
			return;
		}

		// Пароль приезжает в пакете и сохраняется как есть: смысл переноса в том,
		// чтобы семья вошла на новом сайте по прежним логину и паролю. Пустой
		// пароль (учётка без сохранённой копии) — единственный случай генерации.
		$password = (string) ( $person['password'] ?? '' );

		try {
			if ( $input->isStudent ) {
				$login = sanitize_user( (string) ( $person['login'] ?? '' ), true );
				if ( '' === $login ) {
					$warnings[] = sprintf( 'У ученика «%s» в пакете нет логина — учётка не создана.', $input->fullName() );
					return;
				}

				if ( '' === $password ) {
					$password   = wp_generate_password( 12 );
					$warnings[] = sprintf(
						'У ученика «%s» в пакете нет пароля — выдан новый, его нужно передать вручную.',
						$input->fullName()
					);
				}

				$account = $this->provisioning->provisionStudent( $personId, $input, $login, $password );
			} else {
				if ( null === $input->email || '' === $input->email ) {
					$warnings[] = sprintf( 'У представителя «%s» в пакете нет email — учётка не создана.', $input->fullName() );
					return;
				}

				if ( '' === $password ) {
					$warnings[] = sprintf(
						'У представителя «%s» в пакете нет пароля — выдан новый, его нужно передать вручную.',
						$input->fullName()
					);
				}

				// null → сгенерировать; непустая строка → поставить как есть.
				$account = $this->provisioning->provisionParent( $personId, $input, '' !== $password ? $password : null );
			}
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'SUBJECT_BUNDLE', $e, array( 'person_id' => $personId ), true );
			$warnings[] = sprintf( 'Не удалось создать учётку для «%s»: %s', $input->fullName(), $e->getMessage() );
			return;
		}

		// В журнал отката попадает только по-настоящему созданная учётка:
		// привязанного существующего пользователя целевого сайта удалять нельзя.
		if ( $account->created ) {
			$created->addUser( $account->userId );
		}

		$credentials[] = array(
			'name'     => $input->fullName(),
			'login'    => $account->login,
			'password' => $account->password,
		);
	}

	/**
	 * Создаёт записи о зачислении.
	 *
	 * @param array           $records   Записи из манифеста
	 * @param array<int, int> $groupMap  Карта групп
	 * @param array<int, int> $personMap Карта лиц
	 * @param string[]        $warnings  Аккумулятор предупреждений (по ссылке)
	 *
	 * @return int Количество созданных зачислений
	 */
	private function restoreRecords( array $records, array $groupMap, array $personMap, array &$warnings ): int {
		$now     = $this->clock->now( 'mysql', true );
		$created = 0;

		foreach ( $records as $record ) {
			$groupId   = $groupMap[ (int) ( $record['group_source_id'] ?? 0 ) ] ?? 0;
			$studentId = $personMap[ (int) ( $record['student_person_ref'] ?? 0 ) ] ?? 0;
			$parentId  = $personMap[ (int) ( $record['parent_person_ref'] ?? 0 ) ] ?? 0;

			if ( $groupId <= 0 || $studentId <= 0 || $parentId <= 0 ) {
				$warnings[] = 'Пропущено зачисление: в пакете нет связанной группы, ученика или представителя.';
				continue;
			}

			$contractNo = (string) ( $record['contract_no'] ?? '' );

			// Дедуп: повторный импорт того же пакета не задваивает зачисление.
			if ( '' !== $contractNo && $this->records->existsByContract( $studentId, $groupId, $contractNo ) ) {
				continue;
			}

			$this->records->create( new StudentRecordInputDTO(
				studentPersonId:    $studentId,
				parentPersonId:     $parentId,
				status:             EnrollmentStatus::Active->value,
				enrolledAt:         (string) ( $record['enrolled_at'] ?? $now ),
				createdAt:          $now,
				updatedAt:          $now,
				groupId:            $groupId,
				snapshotLastName:   sanitize_text_field( (string) ( $record['snapshot_last_name'] ?? '' ) ),
				snapshotFirstName:  sanitize_text_field( (string) ( $record['snapshot_first_name'] ?? '' ) ),
				snapshotMiddleName: $this->nullable( $record['snapshot_middle_name'] ?? null ),
				snapshotSchool:     $this->nullable( $record['snapshot_school'] ?? null ),
				snapshotGrade:      $this->nullable( $record['snapshot_grade'] ?? null ),
				contractNo:         '' !== $contractNo ? $contractNo : null,
				contractDate:       $this->nullable( $record['contract_date'] ?? null ),
				orderNo:            $this->nullable( $record['order_no'] ?? null ),
				orderDate:          $this->nullable( $record['order_date'] ?? null ),
				enrolledByUserId:   get_current_user_id() ?: null,
			) );

			++$created;
		}

		return $created;
	}

	/**
	 * Переводит запись манифеста в ввод для создания лица.
	 *
	 * @param array $person Данные лица
	 *
	 * @return PersonInputDTO
	 */
	private function toPersonInput( array $person ): PersonInputDTO {
		$email = trim( (string) ( $person['email'] ?? '' ) );

		return new PersonInputDTO(
			lastName:      sanitize_text_field( (string) ( $person['last_name'] ?? '' ) ),
			firstName:     sanitize_text_field( (string) ( $person['first_name'] ?? '' ) ),
			docNumber:     sanitize_text_field( (string) ( $person['doc_number'] ?? '' ) ),
			isStudent:     (bool) ( $person['is_student'] ?? false ),
			middleName:    sanitize_text_field( (string) ( $person['middle_name'] ?? '' ) ),
			docType:       sanitize_text_field( (string) ( $person['doc_type'] ?? '' ) ),
			birthDate:     sanitize_text_field( (string) ( $person['birth_date'] ?? '' ) ),
			inn:           sanitize_text_field( (string) ( $person['inn'] ?? '' ) ),
			address:       sanitize_text_field( (string) ( $person['address'] ?? '' ) ),
			phone:         sanitize_text_field( (string) ( $person['phone'] ?? '' ) ),
			school:        sanitize_text_field( (string) ( $person['school'] ?? '' ) ),
			grade:         sanitize_text_field( (string) ( $person['grade'] ?? '' ) ),
			docIssuedBy:   sanitize_text_field( (string) ( $person['doc_issued_by'] ?? '' ) ),
			docIssuedDate: sanitize_text_field( (string) ( $person['doc_issued_date'] ?? '' ) ),
			email:         '' !== $email ? sanitize_email( $email ) : null,
		);
	}

	/**
	 * Разрешает ID курса группы через карту импорта.
	 *
	 * @param string         $courseRef `_export_id` курса или пустая строка
	 * @param ExportIdMapper $mapper    Карта импорта
	 *
	 * @return int ID курса на целевом сайте; 0 — курс не переносился
	 */
	private function resolveCourseId( string $courseRef, ExportIdMapper $mapper ): int {
		if ( '' === $courseRef ) {
			return 0;
		}

		$courseId = $mapper->toPostId( $courseRef );

		return ( null !== $courseId && null !== $this->posts->get( $courseId ) ) ? $courseId : 0;
	}

	/**
	 * Учебный период целевого сайта, в который приезжают группы.
	 *
	 * @param string[] $warnings Аккумулятор предупреждений (по ссылке)
	 *
	 * @return string ID периода
	 *
	 * @throws RuntimeException Если на целевом сайте нет ни одного периода
	 */
	private function resolvePeriodId( array &$warnings ): string {
		$current = $this->periods->getCurrentPeriod();

		if ( null === $current ) {
			throw new RuntimeException(
				'На этом сайте не задан текущий учебный период — создайте его перед импортом учеников.'
			);
		}

		$warnings[] = sprintf(
			'Группы зачислены в текущий учебный период «%s»: период сайта-источника здесь не воспроизводится.',
			$current->name
		);

		return $current->id;
	}

	/**
	 * Пустую строку приводит к null (в БД такие поля nullable).
	 *
	 * @param mixed $value Значение из манифеста
	 *
	 * @return string|null
	 */
	private function nullable( mixed $value ): ?string {
		$string = trim( (string) $value );

		return '' !== $string ? sanitize_text_field( $string ) : null;
	}
}
