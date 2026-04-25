<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\BoilerplateRepository;
use Inc\Enums\TaskTemplate;

/**
 * Class TaskManager
 *
 * Класс-сервис для сложных операций над заданиями.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **Создание задания** — комплексный процесс создания задания (генерация номера, применение шаблона, импорт условий).
 * 2. **Генерация уникального слага** — автоматическое создание номера задания на основе префикса и счётчика.
 * 3. **Синхронизация мета-данных** — сохранение типа шаблона и контента условий в мета-поля поста.
 *
 * ### Архитектурная роль:
 *
 * Делегирует низкоуровневые операции PostManager (работа с постами) и TermManager (работа с терминами),
 * а получение данных — репозиториям MetaBoxRepository и BoilerplateRepository.
 * Соблюдает принцип единственной ответственности (SRP), инкапсулируя сложную бизнес-логику создания заданий.
 */
class TaskManager {
	
	public function __construct(
		private readonly PostManager $postManager,
		private readonly TermManager $termManager,
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
	) {}
	
	/**
	 * Создаёт задание со всей сопутствующей логикой.
	 *
	 * @param string      $subjectKey      Ключ предмета (например, 'math')
	 * @param int         $termId          ID термина таксономии номеров заданий
	 * @param string      $title           Название задания
	 * @param string|null $boilerplateUid  UID шаблона типового условия (опционально)
	 *
	 * @return int ID созданного поста
	 *
	 * @throws \RuntimeException Если создание не удалось
	 */
	public function createNewTask(
		string $subjectKey,
		int $termId,
		string $title,
		?string $boilerplateUid
	): int {
		$taxonomy = "{$subjectKey}_task_number";
		
		// 1. Получение данных термина через TermManager
		$term = $this->termManager->get( $termId, $taxonomy );
		if ( ! $term ) {
			throw new \RuntimeException( "Тип задания (ID: {$termId}) не найден." );
		}
		
		$termSlug = (string) $term->slug;
		
		// 2. Получение контента из Boilerplate (если выбран)
		$taskText = $this->resolveBoilerplateContent( $subjectKey, $termSlug, $boilerplateUid );
		
		// 3. Генерация уникального номера (слага) для задания
		$customSlug = $this->generateUniqueSlug( $subjectKey, $taxonomy, $termId, $termSlug );
		
		// 4. Вставка поста через PostManager
		$postId = $this->postManager->insert(
			array(
				'post_title'   => "№ {$customSlug}. {$title}",
				'post_name'    => $customSlug,
				'post_type'    => "{$subjectKey}_tasks",
				'post_status'  => 'draft',    // Задание создаётся как черновик
				'post_content' => $this->prepareContentForEditor( $taskText ),
			)
		);
		
		if ( ! $postId ) {
			throw new \RuntimeException( 'Не удалось сохранить запись задания в базу данных.' );
		}
		
		// 5. Привязка к таксономии номеров заданий через TermManager
		$this->termManager->setPostTerms( $postId, array( $termId ), $taxonomy );
		
		// 6. Настройка мета-данных задания (шаблон и поля)
		$this->syncTaskMetadata( $postId, $subjectKey, $termSlug, $taskText );
		
		return $postId;
	}
	
	/**
	 * Генерирует уникальный номер задачи.
	 *
	 * @param string $key      Ключ предмета
	 * @param string $tax      Слаг таксономии
	 * @param int    $id       ID термина
	 * @param string $slug     Слаг термина
	 *
	 * @return string
	 */
	private function generateUniqueSlug( string $key, string $tax, int $id, string $slug ): string {
		// Извлекаем числовой префикс из слага (например 'task_5' → 5)
		$prefix = $this->extractNumberFromSlug( $slug ) ?: $id;
		// Считаем существующие посты в этом термине
		$count = $this->postManager->countByTerm( "{$key}_tasks", $tax, $id );
		
		// str_pad() — дополняет число нулями слева до 3 цифр (1 → 001)
		return $prefix . str_pad( (string) $count, 3, '0', STR_PAD_LEFT );
	}
	
	/**
	 * Синхронизирует мета-поля задания.
	 *
	 * @param int    $postId   ID поста
	 * @param string $key      Ключ предмета
	 * @param string $slug     Слаг термина
	 * @param string $text     Контент из boilerplate
	 *
	 * @return void
	 */
	private function syncTaskMetadata( int $postId, string $key, string $slug, string $text ): void {
		$assignment = $this->metaboxes->getAssignment( $key, $slug );
		$templateId = $assignment->template_id ?? TaskTemplate::STANDARD;
		
		// Преобразование Enum или строки в конечное значение
		$metaValue = ( $templateId instanceof TaskTemplate ) ? $templateId->value : $templateId;
		
		$this->postManager->updateMeta( $postId, '_fs_lms_template_type', $metaValue );
		
		if ( ! empty( $text ) ) {
			$this->postManager->updateMeta( $postId, 'fs_lms_meta', $this->parseBoilerplateToMeta( $text ) );
		}
	}
	
	/**
	 * Извлекает цифры из слага термина (например 'inf_5' → 5).
	 *
	 * @param string $slug Слаг термина
	 *
	 * @return int
	 */
	private function extractNumberFromSlug( string $slug ): int {
		// preg_match() с регулярным выражением для поиска цифр в конце строки
		return preg_match( '/(\d+)$/', $slug, $matches ) ? (int) $matches[1] : 0;
	}
	
	/**
	 * Преобразует JSON из boilerplate в массив для мета-поля.
	 *
	 * @param string $text JSON-строка
	 *
	 * @return array
	 */
	private function parseBoilerplateToMeta( string $text ): array {
		// wp_unslash() — удаляет экранирование слешей
		$clean   = wp_unslash( $text );
		$decoded = json_decode( $clean, true );
		
		// json_last_error() === JSON_ERROR_NONE — проверка успешного декодирования
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			// Оператор + (объединение массивов) с добавлением поля task_answer
			return $decoded + array( 'task_answer' => '' );
		}
		
		return array(
			'task_condition' => $clean,
			'task_answer'    => '',
		);
	}
	
	/**
	 * Подготавливает текст для редактора WordPress.
	 *
	 * @param string $text Исходный текст
	 *
	 * @return string
	 */
	private function prepareContentForEditor( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}
		
		$clean   = wp_unslash( $text );
		$decoded = json_decode( $clean, true );
		
		// Если JSON валидный — объединяем значения через два переноса строки
		return is_array( $decoded ) ? implode( "\n\n", $decoded ) : $clean;
	}
	
	/**
	 * Получает контент boilerplate из репозитория.
	 *
	 * @param string      $key  Ключ предмета
	 * @param string      $slug Слаг термина
	 * @param string|null $uid  UID шаблона
	 *
	 * @return string
	 */
	private function resolveBoilerplateContent( string $key, string $slug, ?string $uid ): string {
		if ( empty( $uid ) ) {
			return '';
		}
		
		$bp = $this->boilerplates->findBoilerplate( $key, $slug, $uid );
		return $bp ? $bp->content : '';
	}
}