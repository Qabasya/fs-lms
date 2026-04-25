<?php

namespace Inc\Registrars;

use Inc\Managers\CPTManager;

/**
 * Class SubjectCPTRegistrar
 *
 * Фасад для регистрации пользовательских типов записей (CPT) предметов.
 *
 * Предоставляет удобный интерфейс (Builder/Fluent Interface) для
 * накопления конфигураций CPT. Содержит хелпер-методы для быстрого
 * создания стандартных типов записей с предустановленными параметрами.
 *
 * После накопления данных делегирует регистрацию низкоуровневому менеджеру.
 *
 * Паттерны:
 * - Facade — упрощает интерфейс работы с CPTManager
 * - Fluent Interface — позволяет объединять вызовы в цепочку
 * - Builder — накапливает данные перед регистрацией
 *
 * @package Inc\Registrars
 */
class SubjectCPTRegistrar {
	/**
	 * Низкоуровневый менеджер для выполнения регистрации.
	 *
	 * @var CPTManager
	 */
	private CPTManager $manager;

	/**
	 * Массив конфигураций CPT, где ключ — slug типа записи,
	 * значение — аргументы для register_post_type().
	 *
	 * @var array<string, array>
	 */
	private array $post_types = array();

	/**
	 * Конструктор.
	 *
	 * @param CPTManager $manager Менеджер для регистрации CPT
	 */
	public function __construct( CPTManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Добавляет тип записи в очередь на регистрацию.
	 *
	 * Поддерживает цепочку вызовов (Fluent Interface).
	 *
	 * @param string $slug Уникальный идентификатор типа записи
	 * @param array  $args Аргументы для register_post_type()
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addPostType( string $slug, array $args ): self {
		$this->post_types[ $slug ] = $args;

		return $this;
	}

	/**
	 * Хелпер для быстрого создания стандартного типа записи.
	 *
	 * Автоматически заполняет стандартные настройки:
	 * - labels (множественное/единственное число, пункты меню)
	 * - public, has_archive, show_in_menu, show_in_rest, supports, rewrite
	 *
	 * @param string $slug     Уникальный идентификатор типа записи
	 * @param string $plural   Множественное название (для меню и заголовков)
	 * @param string $singular Единственное название
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addStandardType( string $slug, string $plural, array|string $singular, array $args = array() ): self {
		// Нормализация входных данных
		$forms = is_array( $singular ) ? $singular : array(
			'nom'    => $singular,
			'acc'    => $singular,
			'gen'    => $singular,
			'gender' => 'neuter', // fallback
		);

		// Если род не указан, пытаемся определить по окончанию
		if ( empty( $forms['gender'] ) && is_string( $singular ) ) {
			$forms['gender'] = $this->detectGender( $singular );
		}

		// Согласование прилагательного "новый" в винительном падеже
		$new_adj = match ( $forms['gender'] ?? 'neuter' ) {
			'feminine'  => 'новую',
			'masculine' => 'новый', // для неодушевлённых CPT обычно так
			default     => 'новое',
		};

		// Формируем базовые лейблы WordPress
		$defaults = array(
			'labels'       => array(
				'name'               => $plural,
				'singular_name'      => $forms['nom'] ?? $forms['acc'] ?? $plural,
				'menu_name'          => $plural,
				'add_new'            => "Добавить {$forms['acc']}",
				'add_new_item'       => "Добавить {$new_adj} {$forms['acc']}",
				'edit_item'          => "Редактировать {$forms['acc']}",
				'new_item'           => "{$new_adj} {$forms['nom']}",
				'view_item'          => "Просмотреть {$forms['acc']}",
				'search_items'       => "Поиск: {$forms['nom']}",
				'not_found'          => "{$forms['nom']} не найдены",
				'not_found_in_trash' => "{$forms['nom']} не найдены в корзине",
				'all_items'          => "Все {$plural}",
				'archives'           => "Архив: {$forms['gen']}",
				'attributes'         => "Атрибуты: {$forms['gen']}",
			),
			'public'       => true,
			'has_archive'  => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			'supports'     => array( 'title' ),
			'rewrite'      => array(
				'slug'       => $slug,
				'with_front' => false,
			),
		);

		// Рекурсивное слияние: $args['labels'] полностью перезапишет дефолтные, если нужно
		$final_args = array_replace_recursive( $defaults, $args );
		
		return $this->addPostType( $slug, $final_args );
	}

	/**
	 * Эвристическое определение рода по окончанию слова.
	 * Работает для 95% стандартных названий CPT.
	 */
	private function detectGender( string $word ): string {
		$last  = mb_substr( $word, -1 );
		$last2 = mb_substr( $word, -2 );
		$lower = mb_strtolower( $last );

		if ( $lower === 'а' && $last2 !== 'ия' ) {
			return 'feminine';  // статья, задача
		}
		if ( $last2 === 'ия' ) {
			return 'feminine';                    // таксономия, статья
		}
		if ( in_array( $lower, array( 'о', 'е' ), true ) ) {
			return 'neuter'; // задание, поле
		}
		if ( $lower === 'ь' ) {
			return 'masculine'; // fallback, обычно мужской род
		}
		return 'masculine';
	}



	/**
	 * Выполняет регистрацию всех накопленных типов записей.
	 *
	 * Делегирует регистрацию CPTManager.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->manager->register( $this->post_types );
	}
}
