<?php

declare( strict_types=1 );

namespace Inc\Controllers\Builders;

use Inc\Enums\Wp\SubjectPageType;

/**
 * Class SubjectCptArgsBuilder
 *
 * Собирает конфигурацию CPT предмета: подписи (склонения) и опции регистрации.
 *
 * @package Inc\Controllers\Builders
 *
 * Один источник правды для того, чем банки контента отличаются друг от друга:
 * задания/статьи — публичные банки со своими capability_type, уроки/работы/курсы/
 * экзамены — закрытый банк (`bankOptions()`), доступный только через плеер.
 * Результат прогоняется через фильтр `fs_lms_cpt_args`.
 */
class SubjectCptArgsBuilder {

	/**
	 * Конфигурация CPT указанного типа для предмета.
	 *
	 * @param string $type    Тип банка: tasks|articles|lessons|works|courses|assessments
	 * @param object $subject DTO предмета (передаётся в фильтр)
	 *
	 * @return array{labels: array, options: array} Пустой массив для неизвестного типа
	 */
	public function build( string $type, object $subject ): array {
		$args = match ( $type ) {
			'tasks'       => $this->tasks( (string) ( $subject->key ?? '' ) ),
			'articles'    => $this->articles( (string) ( $subject->key ?? '' ) ),
			'lessons'     => $this->lessons(),
			'works'       => $this->works(),
			'courses'     => $this->courses(),
			'assessments' => $this->assessments(),
			default       => array(),
		};

		/**
		 * apply_filters() — позволяет модифицировать аргументы CPT через сторонние плагины.
		 *
		 * @param array  $args    Массив с labels и options
		 * @param string $type    Тип контента (tasks, articles, lessons, works, courses, assessments)
		 * @param object $subject Объект предмета
		 */
		return apply_filters( 'fs_lms_cpt_args', $args, $type, $subject );
	}

	/**
	 * Общий конфиг банка контента: скрыт из top-level (меню «Обучение»),
	 * права через fs_lms_content, без REST/поиска/архива.
	 *
	 * @return array<string, mixed>
	 */
	private function bankOptions(): array {
		return array(
			// Контент урока/работы/курса не имеет публичного фронта — отдаётся только
			// через гейтнутый плеер урока. Закрываем прямой пермалинк поста,
			// сохраняя admin-редактирование (show_ui). Контрольные переопределяют
			// publicly_queryable ниже (их плеер живёт на singular-пермалинке).
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'capability_type'     => 'fs_lms_content',
			'map_meta_cap'        => true,
			'has_archive'         => false,
		);
	}

	/**
	 * Задания: заголовок; права fs_lms_content (препод публикует задания); скрыты из top-level.
	 *
	 * @param string $subject_key Ключ предмета (для адреса записи).
	 *
	 * @return array{labels: array, options: array}
	 */
	private function tasks( string $subject_key ): array {
		return array(
			'labels'  => array(
				'nom'    => 'Задание',
				'acc'    => 'задание',
				'gen'    => 'задания',
				'gender' => 'neuter',
			),
			'options' => array_merge(
				array(
					'supports'        => array( 'title' ),
					'show_in_menu'    => false,
					'capability_type' => 'fs_lms_content',
					'map_meta_cap'    => true,
				),
				$this->sectionRouting( $subject_key, SubjectPageType::Trainer )
			),
		);
	}

	/**
	 * Статьи: отдельный capability_type 'fs_lms_article' — изолирует права статей
	 * от fs_lms_content (методист): маркетолог (manage_lms_articles) получает
	 * articleCaps в RoleManager, методист — нет.
	 *
	 * @return array{labels: array, options: array}
	 */
	private function articles( string $subject_key ): array {
		return array(
			'labels'  => array(
				'nom'    => 'Статья',
				'acc'    => 'статью',
				'gen'    => 'статьи',
				'gender' => 'feminine',
			),
			'options' => array_merge(
				array(
					'supports'        => array( 'title', 'editor', 'thumbnail' ),
					'show_in_menu'    => false,
					'capability_type' => 'fs_lms_article',
					'map_meta_cap'    => true,
				),
				$this->sectionRouting( $subject_key, SubjectPageType::Textbook )
			),
		);
	}

	/**
	 * Адрес записи внутри раздела лендинга: `/{key}/trainer/{slug}/`,
	 * `/{key}/textbook/{slug}/`.
	 *
	 * Собственный архив CPT при этом не нужен и вреден: его адрес совпал бы с
	 * адресом страницы раздела — списком заданий и статей заведует лендинг
	 * (SubjectLandingController).
	 *
	 * @param string          $subject_key Ключ предмета.
	 * @param SubjectPageType $section     Раздел лендинга.
	 *
	 * @return array<string, mixed>
	 */
	private function sectionRouting( string $subject_key, SubjectPageType $section ): array {
		if ( '' === $subject_key ) {
			return array();
		}

		return array(
			'has_archive' => false,
			'rewrite'     => array(
				'slug'       => $section->path( $subject_key ),
				'with_front' => false,
			),
		);
	}

	/**
	 * Уроки: без 'editor' (контент = шаги) и без 'thumbnail' (нет «Изображения записи»).
	 *
	 * @return array{labels: array, options: array}
	 */
	private function lessons(): array {
		return array(
			'labels'  => array(
				'nom'    => 'Урок',
				'acc'    => 'урок',
				'gen'    => 'урока',
				'gender' => 'masculine',
			),
			'options' => array_merge( $this->bankOptions(), array( 'supports' => array( 'title', 'author' ) ) ),
		);
	}

	/**
	 * @return array{labels: array, options: array}
	 */
	private function works(): array {
		return array(
			'labels'  => array(
				'nom'    => 'Работа',
				'acc'    => 'работу',
				'gen'    => 'работы',
				'gender' => 'feminine',
			),
			'options' => array_merge( $this->bankOptions(), array( 'supports' => array( 'title', 'author' ) ) ),
		);
	}

	/**
	 * @return array{labels: array, options: array}
	 */
	private function courses(): array {
		return array(
			'labels'  => array(
				'nom'    => 'Курс',
				'acc'    => 'курс',
				'gen'    => 'курса',
				'gender' => 'masculine',
			),
			'options' => array_merge( $this->bankOptions(), array( 'supports' => array( 'title', 'author', 'thumbnail' ) ) ),
		);
	}

	/**
	 * Плеер экзамена отдаётся по singular-пермалинку (AssessmentPageController),
	 * поэтому остаётся publicly_queryable; доступ закрывает гард-404 в контроллере.
	 *
	 * @return array{labels: array, options: array}
	 */
	private function assessments(): array {
		return array(
			'labels'  => array(
				'nom'    => 'Экзамен',
				'acc'    => 'экзамен',
				'gen'    => 'экзамена',
				'gender' => 'masculine',
			),
			'options' => array_merge(
				$this->bankOptions(),
				array( 'supports' => array( 'title', 'author' ), 'publicly_queryable' => true )
			),
		);
	}
}
