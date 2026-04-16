<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Services\TaxonomySeeder;

/**
 * Class SubjectSettingsCallbacks
 *
 * AJAX-обработчики для CRUD-операций с предметами,
 * а также экспорт/импорт полных данных предмета.
 *
 * @package Inc\Callbacks
 */
class SubjectSettingsCallbacks {
	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 * @param TaxonomySeeder $seeder Сервис заполнения таксономий
	 * @param TaxonomyRepository $taxonomies Репозиторий таксономий
	 * @param MetaBoxRepository $metaboxes Репозиторий метабоксов (привязка шаблонов)
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TermManager $terms Менеджер терминов
	 * @param PostManager $posts Менеджер постов
	 */
	public function __construct(
		private SubjectRepository $subjects,
		private TaxonomySeeder $seeder,
		private TaxonomyRepository $taxonomies,
		private MetaBoxRepository $metaboxes,
		private BoilerplateRepository $boilerplates,
		private TermManager $terms,
		private PostManager $posts,
	) {
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Создаёт новый предмет и засевает таксономию номеров заданий.
	 *
	 * @return void
	 */
	public function ajaxStoreSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа и названия предмета
		[ $key, $name ] = $this->requireKeyAndName();

		// Получение количества заданий (по умолчанию 0)
		$count = absint( wp_unslash( $_POST['tasks_count'] ?? 0 ) );

		// Сохранение предмета через репозиторий
		$success = $this->subjects->update( [ 'key' => $key, 'name' => $name ] );

		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при создании предмета' );

			return;
		}

		// Засев таксономии номерами заданий
		$this->seeder->seedTaskNumbers( "{$key}_task_number", $count, $key );

		// Сброс правил перезаписи для активации новых CPT
		flush_rewrite_rules();

		wp_send_json_success( "Предмет «{$name}» успешно создан!" );
	}

	/**
	 * Обновляет название существующего предмета.
	 *
	 * @return void
	 */
	public function ajaxUpdateSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа и названия предмета
		[ $key, $name ] = $this->requireKeyAndName();

		// Проверка существования предмета
		$this->requireExists( $key );

		// Обновление предмета через репозиторий
		$success = $this->subjects->update( [ 'key' => $key, 'name' => $name ] );

		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при обновлении предмета' );

			return;
		}

		wp_send_json_success( "Предмет «{$name}» обновлён" );
	}

	/**
	 * Удаляет предмет из базы данных каскадно (все связанные данные).
	 *
	 * @return void
	 */
	public function ajaxDeleteSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа предмета
		$key = $this->requireKey();

		// Проверка существования предмета
		$this->requireExists( $key );

		// Каскадное удаление всех связанных данных
		$this->cascadeDelete( $key );

		// Удаление предмета через репозиторий
		$success = $this->subjects->delete( [ 'key' => $key ] );

		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при удалении предмета' );

			return;
		}

		// Сброс правил перезаписи после удаления CPT
		flush_rewrite_rules();

		wp_send_json_success( 'Предмет удалён' );
	}

	/**
	 * Экспортирует все данные предмета в JSON.
	 *
	 * @return void
	 */
	public function ajaxExportSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и валидация ключа предмета
		$key     = $this->requireKey();
		$subject = $this->subjects->getByKey( $key );

		if ( ! $subject ) {
			wp_send_json_error( 'Предмет не найден' );

			return;
		}

		wp_send_json_success( [
			'subject'      => [ 'key' => $subject->key, 'name' => $subject->name ],
			'taxonomies'   => $this->taxonomies->getRawForSubject( $key ),
			'metaboxes'    => $this->metaboxes->getRawForSubject( $key ),
			'boilerplates' => $this->boilerplates->getRawForSubject( $key ),
			'terms'        => $this->collectTerms( $key ),
			'posts'        => $this->collectPosts( $key ),
		] );
	}

	/**
	 * Импортирует полные данные предмета из JSON.
	 *
	 * @return void
	 */
	public function ajaxImportSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize();

		// Получение и декодирование JSON
		$raw = wp_unslash( $_POST['json'] ?? '' );

		if ( empty( $raw ) ) {
			wp_send_json_error( 'JSON не передан' );

			return;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['subject']['key'], $data['subject']['name'] ) ) {
			wp_send_json_error( 'Неверный формат файла' );

			return;
		}

		$key  = sanitize_title( $data['subject']['key'] );
		$name = sanitize_text_field( $data['subject']['name'] );

		if ( empty( $key ) || empty( $name ) ) {
			wp_send_json_error( 'Ключ или название предмета пусты' );

			return;
		}

		// Проверка на существование предмета
		if ( $this->subjects->getByKey( $key ) ) {
			wp_send_json_error( "Предмет с ключом «{$key}» уже существует" );

			return;
		}

		// Создание предмета
		$this->subjects->update( [ 'key' => $key, 'name' => $name ] );

		// Импорт таксономий
		foreach ( $data['taxonomies'] ?? [] as $tax_slug => $tax_data ) {
			$this->taxonomies->update( [
				'subject_key'  => $key,
				'tax_slug'     => sanitize_title( (string) $tax_slug ),
				'name'         => sanitize_text_field( $tax_data['name'] ?? '' ),
				'display_type' => sanitize_text_field( $tax_data['display_type'] ?? 'select' ),
			] );
		}

		// Импорт привязок метабоксов (шаблонов)
		foreach ( $data['metaboxes'] ?? [] as $task_number => $template_id ) {
			$this->metaboxes->update( [
				'subject'     => $key,
				'task_number' => sanitize_text_field( (string) $task_number ),
				'template_id' => sanitize_text_field( (string) $template_id ),
			] );
		}

		// Импорт типовых условий (boilerplate)
		foreach ( $data['boilerplates'] ?? [] as $term_slug => $bp_list ) {
			foreach ( (array) $bp_list as $bp ) {
				$this->boilerplates->updateBoilerplate( new TaskTypeBoilerplateDTO(
					uid: sanitize_text_field( $bp['uid'] ?? uniqid( 'bp_', true ) ),
					subject_key: $key,
					term_slug: sanitize_text_field( (string) $term_slug ),
					title: sanitize_text_field( $bp['title'] ?? '' ),
					content: wp_kses_post( $bp['content'] ?? '' ),
					is_default: (bool) ( $bp['is_default'] ?? false ),
				) );
			}
		}

		// Импорт терминов
		foreach ( $data['terms'] ?? [] as $tax_slug => $term_list ) {
			$this->importTerms( sanitize_title( (string) $tax_slug ), (array) $term_list );
		}

		// Импорт постов (заданий и статей)
		$this->importPosts( $data['posts'] ?? [] );

		// Сброс правил перезаписи для активации новых CPT
		flush_rewrite_rules();

		wp_send_json_success( "Предмет «{$name}» успешно импортирован" );
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет nonce и права администратора.
	 * Завершает выполнение через wp_send_json_error при неудаче.
	 *
	 * @return void
	 */
	private function authorize(): void {
		// Проверка nonce для защиты от CSRF
		Nonce::Subject->verify( 'security' );

		// Проверка прав доступа (только администраторы)
		if ( ! current_user_can( Capability::ADMIN->value ) ) {
			wp_send_json_error( 'У вас недостаточно прав', 403 );
		}
	}

	/**
	 * Читает и валидирует ключ предмета из POST.
	 * Завершает выполнение, если ключ пустой.
	 *
	 * @return string Санированный ключ предмета
	 */
	private function requireKey(): string {
		$key = sanitize_title( wp_unslash( $_POST['key'] ?? '' ) );

		if ( empty( $key ) ) {
			wp_send_json_error( 'ID предмета обязателен' );
		}

		return $key;
	}

	/**
	 * Читает и валидирует ключ + название предмета из POST.
	 * Завершает выполнение, если одно из значений пустое.
	 *
	 * @return array{0: string, 1: string} [key, name]
	 */
	private function requireKeyAndName(): array {
		$key  = $this->requireKey();
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( empty( $name ) ) {
			wp_send_json_error( 'Название предмета обязательно' );
		}

		return [ $key, $name ];
	}

	/**
	 * Проверяет существование предмета в БД.
	 * Завершает выполнение, если предмет не найден.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function requireExists( string $key ): void {
		if ( ! $this->subjects->getByKey( $key ) ) {
			wp_send_json_error( 'Предмет не найден в базе данных' );
		}
	}

	/**
	 * Каскадное удаление всех данных, связанных с предметом.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function cascadeDelete( string $key ): void {
		// Удаление терминов пользовательских таксономий
		foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
			$this->terms->deleteAll( $tax_dto->slug );
		}

		// Удаление терминов системной таксономии номеров заданий
		$this->terms->deleteAll( "{$key}_task_number" );

		// Удаление всех постов заданий и статей
		foreach ( [ "{$key}_tasks", "{$key}_articles" ] as $post_type ) {
			$this->posts->deleteAll( $post_type );
		}

		// Удаление записей из репозиториев
		$this->taxonomies->deleteBySubject( $key );
		$this->metaboxes->deleteBySubject( $key );
		$this->boilerplates->deleteBySubject( $key );
	}

	/**
	 * Собирает все термины для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array> Массив терминов, сгруппированных по таксономиям
	 */
	private function collectTerms( string $subject_key ): array {
		// Список всех таксономий предмета (системная + пользовательские)
		$slugs = array_merge(
			[ "{$subject_key}_task_number" ],
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = [];

		foreach ( $slugs as $tax_slug ) {
			$wpTerms = $this->terms->getAll( $tax_slug );

			$result[ $tax_slug ] = array_map( fn( $t ) => [
				'name'        => $t->name,
				'slug'        => $t->slug,
				'description' => $t->description,
				'parent'      => $t->parent,
			], $wpTerms );
		}

		return $result;
	}

	/**
	 * Собирает все посты (задания и статьи) для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array> Массив постов, сгруппированных по типам
	 */
	private function collectPosts( string $subject_key ): array {
		// Список всех таксономий для получения привязок терминов
		$tax_slugs = array_merge(
			[ "{$subject_key}_task_number" ],
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = [];

		foreach ( [ "{$subject_key}_tasks", "{$subject_key}_articles" ] as $post_type ) {
			$result[ $post_type ] = array_map( function ( $post ) use ( $tax_slugs ) {
				$termMap = [];

				foreach ( $tax_slugs as $tax_slug ) {
					$slugs = $this->terms->getPostSlugs( $post->ID, $tax_slug );
					if ( ! empty( $slugs ) ) {
						$termMap[ $tax_slug ] = $slugs;
					}
				}

				return [
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_status'  => $post->post_status,
					'post_date'    => $post->post_date,
					'menu_order'   => (int) $post->menu_order,
					'meta'         => $this->posts->getAllMeta( $post->ID ),
					'terms'        => $termMap,
				];
			}, $this->posts->getAll( $post_type ) );
		}

		return $result;
	}

	/**
	 * Импортирует термины в указанную таксономию.
	 *
	 * @param string $taxonomy Слаг таксономии
	 * @param array $terms Массив данных терминов
	 *
	 * @return void
	 */
	private function importTerms( string $taxonomy, array $terms ): void {
		// Убеждаемся, что таксономия существует
		$this->terms->ensureTaxonomy( $taxonomy );

		foreach ( $terms as $term_data ) {
			$name = sanitize_text_field( $term_data['name'] ?? '' );

			if ( empty( $name ) ) {
				continue;
			}

			$this->terms->insert( $name, $taxonomy, [
				'slug'        => sanitize_title( $term_data['slug'] ?? $name ),
				'description' => sanitize_text_field( $term_data['description'] ?? '' ),
			] );
		}
	}

	/**
	 * Импортирует посты (задания и статьи) из массива данных.
	 *
	 * @param array $posts_data Массив постов, сгруппированных по типам
	 *
	 * @return void
	 */
	private function importPosts( array $posts_data ): void {
		foreach ( $posts_data as $post_type => $post_list ) {
			foreach ( (array) $post_list as $post_data ) {
				// Создание поста
				$post_id = $this->posts->insert( [
					'post_type'    => sanitize_key( (string) $post_type ),
					'post_title'   => sanitize_text_field( $post_data['post_title'] ?? '' ),
					'post_content' => wp_kses_post( $post_data['post_content'] ?? '' ),
					'post_excerpt' => sanitize_text_field( $post_data['post_excerpt'] ?? '' ),
					'post_status'  => sanitize_text_field( $post_data['post_status'] ?? 'publish' ),
					'post_date'    => sanitize_text_field( $post_data['post_date'] ?? '' ),
					'menu_order'   => absint( $post_data['menu_order'] ?? 0 ),
				] );

				if ( ! $post_id ) {
					continue;
				}

				// Импорт мета-полей
				foreach ( $post_data['meta'] ?? [] as $meta_key => $meta_value ) {
					$this->posts->updateMeta( $post_id, sanitize_key( (string) $meta_key ), $meta_value );
				}

				// Привязка терминов
				foreach ( $post_data['terms'] ?? [] as $tax_slug => $term_slugs ) {
					$this->terms->setPostTerms( $post_id, (array) $term_slugs, sanitize_title( (string) $tax_slug ) );
				}
			}
		}
	}
}