<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\BoilerplateRepository;
use Inc\Enums\TaskTemplate;

/**
 * Класс-сервис для сложных операций над заданиями.
 * Использует низкоуровневые менеджеры для соблюдения SRP.
 */
class TaskManager {

	public function __construct(
		private readonly PostManager $postManager,
		private readonly TermManager $termManager,
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
	) {}

	/**
	 * Создаёт задание со всей сопутствующей логикой:
	 * генерация номера, применение шаблона, импорт условий.
	 *
	 * @throws \RuntimeException Если создание не удалось.
	 */
	public function createNewTask(
		string $subjectKey,
		int $termId,
		string $title,
		?string $boilerplateUid
	): int {
		$taxonomy = "{$subjectKey}_task_number";

		// 1. Получаем данные типа через TermManager (уходим от get_term)
		$term = $this->termManager->get( $termId, $taxonomy );
		if ( ! $term ) {
			throw new \RuntimeException( "Тип задания (ID: {$termId}) не найден." );
		}

		$termSlug = (string) $term->slug;

		// 2. Получаем контент из Boilerplate (если выбран)
		$taskText = $this->resolveBoilerplateContent( $subjectKey, $termSlug, $boilerplateUid );

		// 3. Генерируем уникальный номер (слаг) для задания
		$customSlug = $this->generateUniqueSlug( $subjectKey, $taxonomy, $termId, $termSlug );

		// 4. Вставка поста через PostManager (уходим от wp_insert_post)
		$postId = $this->postManager->insert(
			array(
				'post_title'   => "№ {$customSlug}. {$title}",
				'post_name'    => $customSlug,
				'post_type'    => "{$subjectKey}_tasks",
				'post_status'  => 'draft',
				'post_content' => $this->prepareContentForEditor( $taskText ),
			)
		);

		if ( ! $postId ) {
			throw new \RuntimeException( 'Не удалось сохранить запись задания в базу данных.' );
		}

		// 5. Привязываем к таксономии через TermManager (уходим от wp_set_object_terms)
		$this->termManager->setPostTerms( $postId, array( $termId ), $taxonomy );

		// 6. Настраиваем мета-данные задания (шаблон и поля)
		$this->syncTaskMetadata( $postId, $subjectKey, $termSlug, $taskText );

		return $postId;
	}

	/**
	 * Генерирует номер задачи на основе префикса из слага и кол-ва существующих записей.
	 */
	private function generateUniqueSlug( string $key, string $tax, int $id, string $slug ): string {
		$prefix = $this->extractNumberFromSlug( $slug ) ?: $id;
		$count  = $this->postManager->countByTerm( "{$key}_tasks", $tax, $id );

		return $prefix . str_pad( (string) $count, 3, '0', STR_PAD_LEFT );
	}

	/**
	 * Синхронизирует мета-поля через PostManager (уходим от update_post_meta).
	 */
	private function syncTaskMetadata( int $postId, string $key, string $slug, string $text ): void {
		$assignment = $this->metaboxes->getAssignment( $key, $slug );
		$templateId = $assignment->template_id ?? TaskTemplate::STANDARD;

		// Превращаем Enum или строку в конечное значение
		$metaValue = ( $templateId instanceof TaskTemplate ) ? $templateId->value : $templateId;

		$this->postManager->updateMeta( $postId, '_fs_lms_template_type', $metaValue );

		if ( ! empty( $text ) ) {
			$this->postManager->updateMeta( $postId, 'fs_lms_meta', $this->parseBoilerplateToMeta( $text ) );
		}
	}

	/**
	 * Вспомогательный метод: Извлечение цифр из слага типа (например 'inf_5' -> 5)
	 */
	private function extractNumberFromSlug( string $slug ): int {
		return preg_match( '/(\d+)$/', $slug, $matches ) ? (int) $matches[1] : 0;
	}

	/**
	 * Вспомогательный метод: Подготовка данных из Boilerplate для мета-поля.
	 */
	private function parseBoilerplateToMeta( string $text ): array {
		$clean   = wp_unslash( $text );
		$decoded = json_decode( $clean, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return $decoded + array( 'task_answer' => '' );
		}

		return array(
			'task_condition' => $clean,
			'task_answer'    => '',
		);
	}

	/**
	 * Вспомогательный метод: Подготовка текста для основного редактора WordPress.
	 */
	private function prepareContentForEditor( string $text ): string {
		if ( empty( $text ) ) {
			return '';
		}

		$clean   = wp_unslash( $text );
		$decoded = json_decode( $clean, true );

		return is_array( $decoded ) ? implode( "\n\n", $decoded ) : $clean;
	}

	/**
	 * Вспомогательный метод: Получение контента из репозитория.
	 */
	private function resolveBoilerplateContent( string $key, string $slug, ?string $uid ): string {
		if ( empty( $uid ) ) {
			return '';
		}

		$bp = $this->boilerplates->findBoilerplate( $key, $slug, $uid );
		return $bp ? $bp->content : '';
	}
}
