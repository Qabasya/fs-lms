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

class StudentsExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly PersonRepository          $persons,
		private readonly StudentRecordRepository   $studentRecords,
		private readonly PersonDocumentsRepository $personDocuments,
		private readonly GroupsRepository          $groups,
		private readonly SubjectRepository         $subjects,
		private readonly UserRepository            $userRepository,
		private readonly PiiCryptoService          $crypto,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID ученика',   fn( $r ) => $r['person_id'] ),
			new CsvColumn( 'Фамилия',      fn( $r ) => $r['last_name'] ),
			new CsvColumn( 'Имя',          fn( $r ) => $r['first_name'] ),
			new CsvColumn( 'Отчество',     fn( $r ) => $r['middle_name'] ),
			new CsvColumn( 'Дата рожд.',   fn( $r ) => $r['birth_date'] ),
			new CsvColumn( 'Класс',        fn( $r ) => $r['grade'] ),
			new CsvColumn( 'Школа',        fn( $r ) => $r['school'] ),
			new CsvColumn( 'Email',        fn( $r ) => $r['email'] ),
			new CsvColumn( 'Телефон',      fn( $r ) => $r['phone'] ),
			new CsvColumn( 'Логин',        fn( $r ) => $r['login'] ),
			new CsvColumn( 'Пароль',       fn( $r ) => $r['password'] ),
			new CsvColumn( 'Группы',       fn( $r ) => $r['groups'] ),
			new CsvColumn( 'Предметы',     fn( $r ) => $r['subjects'] ),
		);
	}

	public function rows( array $context ): iterable {
		$ids = $context['ids'] ?? array();
		$persons = $ids
			? array_filter( array_map( fn( int $id ) => $this->persons->find( $id ), $ids ) )
			: $this->persons->findByIsStudent( true );

		foreach ( $persons as $person ) {
			$docs     = $this->personDocuments->findByPersonId( $person->id );
			$records  = $this->studentRecords->findActiveByStudent( $person->id );
			$wpUser   = $person->wpUserId ? get_userdata( $person->wpUserId ) : null;

			$groupNames   = array();
			$subjectNames = array();
			foreach ( $records as $rec ) {
				if ( $rec->groupId ) {
					$group = $this->groups->findById( $rec->groupId );
					if ( $group ) {
						$groupNames[]   = $group->name;
						$subjectNames[] = $this->subjects->getByKey( $group->subject_key )?->name ?? $group->subject_key;
					}
				}
			}

			$password = '';
			if ( $person->wpUserId ) {
				$enc = $this->userRepository->getMeta( $person->wpUserId, MetaKeys::EncPassword->value );
				if ( $enc ) {
					try {
						$password = $this->crypto->decrypt( (string) base64_decode( $enc ) );
					} catch ( \Throwable ) {}
				}
			}

			yield array(
				'person_id'   => $person->id,
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

	public function filename(): string {
		return 'students';
	}

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
