<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Wp\MetaKeys;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class AbstractPersonExportProvider
 *
 * Общее для выгрузок учеников и родителей: одни и те же зависимости, выбор лиц
 * (выбранные или все одной роли), расшифровка PII и пароля, имена групп и предметов.
 * Колонки и состав строки остаются у наследников.
 *
 * @package Inc\Services\Export
 */
abstract class AbstractPersonExportProvider implements CsvExportProviderInterface {

	/**
	 * @param PersonRepository          $persons         Репозиторий лиц
	 * @param StudentRecordRepository   $studentRecords  Репозиторий записей студентов
	 * @param PersonDocumentsRepository $personDocuments Репозиторий документов лиц
	 * @param GroupsRepository          $groups          Репозиторий групп
	 * @param SubjectRepository         $subjects        Репозиторий предметов
	 * @param UserRepository            $userRepository  Репозиторий пользователей WP
	 * @param PiiCryptoService          $crypto          Сервис шифрования PII
	 */
	public function __construct(
		protected readonly PersonRepository          $persons,
		protected readonly StudentRecordRepository   $studentRecords,
		protected readonly PersonDocumentsRepository $personDocuments,
		protected readonly GroupsRepository          $groups,
		protected readonly SubjectRepository         $subjects,
		protected readonly UserRepository            $userRepository,
		protected readonly PiiCryptoService          $crypto,
	) {}

	/**
	 * Лица для выгрузки: выбранные по ID или все лица одной роли.
	 *
	 * @param int[] $ids       ID лиц (пусто — все)
	 * @param bool  $isStudent Роль для выгрузки «всех»
	 *
	 * @return iterable<PersonDTO>
	 */
	protected function selectPersons( array $ids, bool $isStudent ): iterable {
		return $ids
			? array_filter( array_map( fn( int $id ) => $this->persons->find( $id ), $ids ) )
			: $this->persons->findByIsStudent( $isStudent );
	}

	/**
	 * Название группы и предмета группы; null — группы нет.
	 *
	 * @return array{group:string, subject:string}|null
	 */
	protected function groupAndSubject( ?int $groupId ): ?array {
		$group = $groupId ? $this->groups->findById( $groupId ) : null;
		if ( ! $group ) {
			return null;
		}

		return array(
			'group'   => $group->name,
			'subject' => $this->subjects->getByKey( $group->subject_key )?->name ?? $group->subject_key,
		);
	}

	/**
	 * Пароль аккаунта из мета-поля (расшифрованный); пусто — нет аккаунта, пароля или не расшифровался.
	 */
	protected function decryptedPassword( ?int $wpUserId ): string {
		if ( ! $wpUserId ) {
			return '';
		}

		$enc = $this->userRepository->getMeta( $wpUserId, MetaKeys::EncPassword->value );
		if ( ! $enc ) {
			return '';
		}

		try {
			return $this->crypto->decrypt( (string) base64_decode( $enc ) );
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * Расшифровывает строку из зашифрованного BLOB.
	 *
	 * @param string|null $enc Зашифрованные данные
	 */
	protected function decrypt( ?string $enc ): string {
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
