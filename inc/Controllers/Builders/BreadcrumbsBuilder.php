<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

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

	/**
	 * Крошки архива заданий: предмет / Тренажёр.
	 *
	 * Собственной страницы у предмета нет, поэтому крошка предмета ведёт на
	 * архив заданий — так же, как на странице одного задания. Текущая крошка
	 * («Тренажёр») ссылкой не становится: это текущая страница.
	 *
	 * @param string $subject_name Название предмета.
	 * @param string $archive_url  Ссылка на архив заданий предмета.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forArchive( string $subject_name, string $archive_url = '' ): array {
		$crumbs = array();

		if ( '' !== $subject_name ) {
			$crumbs[] = $this->crumb( $subject_name, $archive_url );
		}

		$crumbs[] = $this->crumb( self::TRAINER_LABEL, '', true );

		return $crumbs;
	}

	/**
	 * Крошки страницы задания: предмет / Тренажёр / тип задания / задание.
	 *
	 * @param string $subject_name Название предмета.
	 * @param string $archive_url  Ссылка на архив заданий предмета.
	 * @param string $type_label   Подпись типа задания (пустая — крошка пропускается).
	 * @param string $type_url     Ссылка на архив типа задания.
	 * @param string $task_label   Заголовок задания (текущая крошка).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forTask(
		string $subject_name,
		string $archive_url,
		string $type_label,
		string $type_url,
		string $task_label
	): array {
		$crumbs = array();

		if ( '' !== $subject_name ) {
			$crumbs[] = $this->crumb( $subject_name, $archive_url );
		}

		$crumbs[] = $this->crumb( self::TRAINER_LABEL, $archive_url );

		if ( '' !== $type_label ) {
			$crumbs[] = $this->crumb( $type_label, $type_url );
		}

		if ( '' !== $task_label ) {
			$crumbs[] = $this->crumb( $task_label, '', true );
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