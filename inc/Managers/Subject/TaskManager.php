<?php

declare( strict_types=1 );

namespace Inc\Managers\Subject;

use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;

use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskNumberService;
use Inc\Services\Template\TemplateRegistry;
use Inc\Services\Template\TemplateResolver;

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
 *    **Дублирование** — копия задания с новым номером и без эталонного ответа.
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
		private readonly TaskNumberService $numbers,
		private readonly TemplateResolver $resolver,
		private readonly TemplateRegistry $templates,
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

		// 3. Наименьший свободный номер (слаг) задания: 'inf_5' → 5000, 5001, …
		$customSlug = $this->numbers->build(
			PostTypeResolver::tasks( $subjectKey ),
			$this->extractNumberFromSlug( $termSlug ) ?: $termId
		);

		// 4. Вставка поста через PostManager
		$postId = $this->postManager->insert(
			array(
				'post_title'   => "№ {$customSlug}. {$title}",
				'post_name'    => $customSlug,
				'post_type'    => PostTypeResolver::tasks( $subjectKey ),
				'post_status'  => 'draft',    // Задание создаётся как черновик
				// wp_insert_post() сам снимает слэши — без wp_slash() из условия пропал бы `\` (LaTeX, код)
				'post_content' => wp_slash( $this->prepareContentForEditor( $taskText ) ),
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
	 * Дублирует задание: то же условие, те же поля и термы, новый номер в серии — и без
	 * эталонного ответа ({@see \Inc\MetaBoxes\Templates\BaseTemplate::stripAnswer()}).
	 *
	 * Копируется сохранённая версия задания; копия — черновик текущего пользователя.
	 * Мета переносится выборочно (шаблон + поля): служебные ключи вроде списка детей
	 * связки 19–21 привязаны к исходной записи и в копии были бы ложью.
	 *
	 * @param int $taskId ID исходного задания
	 *
	 * @return int ID копии
	 *
	 * @throws \RuntimeException Если задание нельзя продублировать
	 */
	public function duplicate( int $taskId ): int {
		$source = $this->postManager->get( $taskId );
		if ( ! $source || ! PostTypeResolver::isTaskPostType( $source->post_type ) ) {
			throw new \RuntimeException( 'Задание не найдено.' );
		}

		// Часть связки собирается из родителя при каждом его сохранении — своей жизни у неё нет.
		if ( $this->postManager->getMeta( $taskId, PostMetaName::TaskBundleParentId->value ) ) {
			throw new \RuntimeException( 'Это часть связки заданий — дублируйте саму связку.' );
		}

		$subjectKey = PostTypeResolver::subjectFromTaskPostType( $source->post_type );
		$number     = $this->termManager->getPostTerms( $taskId, "{$subjectKey}_task_number" )[0] ?? null;
		if ( null === $number ) {
			throw new \RuntimeException( 'У задания не выбран номер задания — укажите его и сохраните задание.' );
		}

		$customSlug = $this->numbers->build(
			$source->post_type,
			$this->extractNumberFromSlug( (string) $number->slug ) ?: (int) $number->term_id
		);

		// «№ 3001. Авторские задания» → «№ 3002. Авторские задания»
		$title = (string) preg_replace( '/^№\s*\d+\.\s*/u', '', $source->post_title );

		$postId = $this->postManager->insert(
			array(
				// wp_insert_post() сам снимает слэши — значения из базы надо экранировать заново
				'post_title'   => wp_slash( "№ {$customSlug}. {$title}" ),
				'post_name'    => $customSlug,
				'post_type'    => $source->post_type,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
				'post_content' => wp_slash( $source->post_content ),
			)
		);

		if ( ! $postId ) {
			throw new \RuntimeException( 'Не удалось сохранить копию задания в базу данных.' );
		}

		foreach ( $this->termManager->taxonomiesOf( $source->post_type ) as $taxonomy ) {
			$termIds = array_map(
				static fn( \WP_Term $term ): int => (int) $term->term_id,
				$this->termManager->getPostTerms( $taskId, $taxonomy )
			);

			if ( $termIds ) {
				$this->termManager->setPostTerms( $postId, $termIds, $taxonomy );
			}
		}

		$templateType = $this->postManager->getMeta( $taskId, PostMetaName::TemplateType->value );
		if ( ! empty( $templateType ) ) {
			$this->postManager->updateMeta( $postId, PostMetaName::TemplateType->value, $templateType );
		}

		$meta     = $this->postManager->taskMeta( $taskId );
		$template = $this->templates->get( $this->resolver->resolveId( $source ) );

		$this->postManager->updateMeta(
			$postId,
			PostMetaName::Meta->value,
			$template ? $template->stripAnswer( $meta ) : $meta
		);

		return $postId;
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
		$templateId = $assignment->template_id ?? TaskTemplate::Standard;

		// Преобразование Enum или строки в конечное значение
		$metaValue = ( $templateId instanceof TaskTemplate ) ? $templateId->value : $templateId;

		$this->postManager->updateMeta( $postId, PostMetaName::TemplateType->value, $metaValue );

		if ( ! empty( $text ) ) {
			$this->postManager->updateMeta( $postId, PostMetaName::Meta->value, $this->parseBoilerplateToMeta( $text ) );
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
	 * Контент приходит из репозитория уже без WP-слэшей: `wp_unslash()` здесь срезал бы
	 * экранирование самого JSON (`\r\n` → `rn`, `\"` → `"`) и ломал разбор.
	 *
	 * @param string $text JSON-строка
	 *
	 * @return array
	 */
	private function parseBoilerplateToMeta( string $text ): array {
		$decoded = json_decode( $text, true );

		// json_last_error() === JSON_ERROR_NONE — проверка успешного декодирования
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			// Оператор + (объединение массивов) с добавлением поля task_answer
			return $decoded + array( 'task_answer' => '' );
		}

		return array(
			'task_condition' => $text,
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

		$decoded = json_decode( $text, true );

		// Если JSON валидный — объединяем значения через два переноса строки
		return is_array( $decoded ) ? implode( "\n\n", $decoded ) : $text;
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
