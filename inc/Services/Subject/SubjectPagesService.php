<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\DTO\Subject\SubjectDTO;
use Inc\DTO\Subject\SubjectLinksDTO;
use Inc\Enums\Wp\SubjectPageType;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectPagesRepository;
use Inc\Shared\PluginLogger;

/**
 * Class SubjectPagesService
 *
 * Публичный лендинг предмета: WP-страницы разделов (описание, тренажёр,
 * учебник, курсы), живущие вместе с предметом.
 *
 * @package Inc\Services\Subject
 *
 * ### Основные обязанности:
 *
 * 1. **Создание** — страницы заводятся при создании предмета (в т.ч. импортом).
 * 2. **Переименование** — заголовки следуют за названием предмета.
 * 3. **Снос** — вместе с предметом страницы уезжают в корзину.
 *
 * ### Архитектурная роль:
 *
 * Единственная точка, создающая страницы предмета: вызывается из
 * SubjectCrudCallbacks, импортёров предмета и Activate (добор для предметов,
 * заведённых до появления лендинга). Идемпотентен — повторный вызов
 * дубликатов не плодит, а восстанавливает недостающее.
 */
readonly class SubjectPagesService {

	/** Тип записи страниц лендинга — штатные страницы WP. */
	private const POST_TYPE = 'page';

	public function __construct(
		private PostManager            $posts,
		private SubjectPagesRepository $pages,
	) {}

	/**
	 * Заводит недостающие страницы предмета.
	 *
	 * Порядок обязателен: корневая страница — родитель остальных, без неё
	 * разделы разных предметов делили бы один слаг (`/trainer/`).
	 *
	 * @param SubjectDTO $subject Предмет.
	 *
	 * @return void
	 */
	public function ensureForSubject( SubjectDTO $subject ): void {
		$stored  = $this->pages->getBySubject( $subject->key );
		$pageIds = array();
		$parent  = 0;

		foreach ( SubjectPageType::forSubject( $subject->hasBank ) as $type ) {
			$pageId = $this->resolvePage( $subject, $type, $stored[ $type->value ] ?? 0, $parent );

			if ( 0 === $pageId ) {
				PluginLogger::warning(
					'SubjectPages',
					'Не удалось создать страницу предмета',
					array( 'subject' => $subject->key, 'page' => $type->value )
				);

				// Корневой страницы нет — вешать разделы не на что.
				if ( SubjectPageType::Overview === $type ) {
					break;
				}

				continue;
			}

			$pageIds[ $type->value ] = $pageId;

			if ( SubjectPageType::Overview === $type ) {
				$parent = $pageId;
			}
		}

		$this->pages->saveBySubject( $subject->key, $pageIds );
	}

	/**
	 * Приводит заголовки страниц к новому названию предмета.
	 *
	 * Слаги остаются прежними: адреса разделов уже разошлись по ссылкам.
	 *
	 * @param SubjectDTO $subject Предмет с новым названием.
	 *
	 * @return void
	 */
	public function renameForSubject( SubjectDTO $subject ): void {
		foreach ( $this->pages->getBySubject( $subject->key ) as $type => $pageId ) {
			$pageType = SubjectPageType::tryFrom( (string) $type );

			if ( null !== $pageType ) {
				$this->posts->update( $pageId, array( 'post_title' => $pageType->title( $subject->name ) ) );
			}
		}
	}

	/**
	 * Отправляет страницы предмета в корзину и забывает их.
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return void
	 */
	public function trashForSubject( string $subjectKey ): void {
		foreach ( $this->pages->getBySubject( $subjectKey ) as $pageId ) {
			$this->posts->trash( $pageId );
		}

		$this->pages->removeSubject( $subjectKey );
	}

	/**
	 * Ссылка на раздел лендинга; '' — раздела у предмета нет.
	 *
	 * @param string          $subjectKey Ключ предмета.
	 * @param SubjectPageType $type       Раздел лендинга.
	 *
	 * @return string
	 */
	public function url( string $subjectKey, SubjectPageType $type ): string {
		$pageId = $this->pages->getId( $subjectKey, $type );

		return $pageId > 0 ? (string) get_permalink( $pageId ) : '';
	}

	/**
	 * Ссылки на разделы предмета для публичных страниц.
	 *
	 * Страницы могут отсутствовать (раздел удалили вручную) — тогда ссылка
	 * пуста и элемент навигации не выводится вовсе; исключение — крошка
	 * предмета, она подстраховывается тренажёром.
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return SubjectLinksDTO
	 */
	public function links( string $subjectKey ): SubjectLinksDTO {
		if ( '' === $subjectKey ) {
			return new SubjectLinksDTO();
		}

		$trainer = $this->url( $subjectKey, SubjectPageType::Trainer );

		return new SubjectLinksDTO(
			subject:  $this->url( $subjectKey, SubjectPageType::Overview ) ?: $trainer,
			trainer:  $trainer,
			textbook: $this->url( $subjectKey, SubjectPageType::Textbook ),
			courses:  $this->url( $subjectKey, SubjectPageType::Courses ),
		);
	}

	/**
	 * ID живой страницы раздела: сохранённая, найденная по адресу или новая.
	 *
	 * @param SubjectDTO      $subject  Предмет.
	 * @param SubjectPageType $type     Раздел лендинга.
	 * @param int             $storedId Ранее сохранённый ID.
	 * @param int             $parentId ID корневой страницы предмета.
	 *
	 * @return int ID страницы или 0, если создать не удалось.
	 */
	private function resolvePage( SubjectDTO $subject, SubjectPageType $type, int $storedId, int $parentId ): int {
		$page = $storedId > 0
			? $this->posts->get( $storedId )
			: $this->posts->findByPath( $type->path( $subject->key ), self::POST_TYPE );

		// Страница в корзине для нас не существует: WP переименовал её слаг,
		// раздел по своему адресу недоступен — заводим взамен новую.
		if ( $page instanceof \WP_Post && 'trash' !== $page->post_status ) {
			return $page->ID;
		}

		return $this->posts->insert( array(
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $type->title( $subject->name ),
			'post_name'    => $type->slug( $subject->key ),
			'post_parent'  => $parentId,
			'post_content' => $type->shortcode()?->tag( array( 'subject' => $subject->key ) ) ?? '',
		) );
	}
}