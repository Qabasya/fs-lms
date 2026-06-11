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

class ParentsExportProvider implements CsvExportProviderInterface {

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

	public function rows( array $context ): iterable {
		$ids = $context['ids'] ?? array();
		$persons = $ids
			? array_filter( array_map( fn( int $id ) => $this->persons->find( $id ), $ids ) )
			: $this->persons->findByIsStudent( false );

		foreach ( $persons as $parent ) {
			$docs   = $this->personDocuments->findByPersonId( $parent->id );
			$wpUser = $parent->wpUserId ? get_userdata( $parent->wpUserId ) : null;

			// Собираем детей и их группы из student_records
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

			$password = '';
			if ( $parent->wpUserId ) {
				$enc = $this->userRepository->getMeta( $parent->wpUserId, MetaKeys::EncPassword->value );
				if ( $enc ) {
					try {
						$password = $this->crypto->decrypt( (string) base64_decode( $enc ) );
					} catch ( \Throwable ) {}
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

	public function filename(): string {
		return 'parents';
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
