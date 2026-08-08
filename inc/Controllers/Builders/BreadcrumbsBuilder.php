<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\DTO\Subject\SubjectLinksDTO;

/**
 * Class BreadcrumbsBuilder
 *
 * Единый источник хлебных крошек публичных страниц предмета: архива заданий
 * («Все задания») и страницы одного задания. Отдаёт плоский список элементов —
 * рендерит его общий партиал `templates/frontend/partials/breadcrumbs.php`.
 *
 * Элемент списка: `['label' => string, 'url' => string, 'current' => bool]`.
 * Пустой `url` или `current => true` рендерятся текстом, а не ссылкой.
 *
 * @package Inc\Controllers\Builders
 */
readonly class BreadcrumbsBuilder {

	/** Общая для обеих страниц подпись раздела. */
	public const TRAINER_LABEL = 'Тренажёр';

	/** Подпись раздела статей в крошках. */
	public const MATERIALS_LABEL = 'Учебник';

	/**
	 * Крошки тренажёра: предмет / Тренажёр.
	 *
	 * Крошка предмета ведёт на его корневую страницу. Текущая крошка
	 * («Тренажёр») ссылкой не становится: это текущая страница.
	 *
	 * @param string           $subject_name Название предмета.
	 * @param SubjectLinksDTO  $links        Ссылки на разделы предмета.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forArchive( string $subject_name, SubjectLinksDTO $links = new SubjectLinksDTO() ): array {
		$crumbs = array();

		if ( '' !== $subject_name ) {
			$crumbs[] = $this->crumb( $subject_name, $links->subject );
		}

		$crumbs[] = $this->crumb( self::TRAINER_LABEL, '', true );

		return $crumbs;
	}

	/**
	 * Крошки страницы задания: предмет / Тренажёр / тип задания / задание.
	 *
	 * @param string          $subject_name Название предмета.
	 * @param SubjectLinksDTO $links        Ссылки на разделы предмета.
	 * @param string          $type_label   Подпись типа задания (пустая — крошка пропускается).
	 * @param string          $type_url     Тренажёр с предвыбранным типом задания.
	 * @param string          $task_label   Заголовок задания (текущая крошка).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forTask(
		string $subject_name,
		SubjectLinksDTO $links,
		string $type_label,
		string $type_url,
		string $task_label
	): array {
		$crumbs = array();

		if ( '' !== $subject_name ) {
			$crumbs[] = $this->crumb( $subject_name, $links->subject );
		}

		$crumbs[] = $this->crumb( self::TRAINER_LABEL, $links->trainer );

		if ( '' !== $type_label ) {
			$crumbs[] = $this->crumb( $type_label, $type_url );
		}

		if ( '' !== $task_label ) {
			$crumbs[] = $this->crumb( $task_label, '', true );
		}

		return $crumbs;
	}

	/**
	 * Крошки страницы статьи: предмет / Учебник / заголовок.
	 *
	 * @param string          $subject_name  Название предмета.
	 * @param SubjectLinksDTO $links         Ссылки на разделы предмета.
	 * @param string          $article_label Заголовок статьи (текущая крошка).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forArticle( string $subject_name, SubjectLinksDTO $links, string $article_label ): array {
		$crumbs = array();

		if ( '' !== $subject_name ) {
			$crumbs[] = $this->crumb( $subject_name, $links->subject );
		}

		$crumbs[] = $this->crumb( self::MATERIALS_LABEL, $links->textbook );

		if ( '' !== $article_label ) {
			$crumbs[] = $this->crumb( $article_label, '', true );
		}

		return $crumbs;
	}

	/**
	 * Собирает один элемент цепочки.
	 *
	 * @param string $label   Подпись.
	 * @param string $url     Ссылка (пустая — крошка-текст).
	 * @param bool   $current Текущая страница.
	 *
	 * @return array<string, mixed>
	 */
	private function crumb( string $label, string $url = '', bool $current = false ): array {
		return array(
			'label'   => $label,
			'url'     => $url,
			'current' => $current,
		);
	}
}