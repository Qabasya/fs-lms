<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Managers\TermManager;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaxonomySettingsCallbacks
 *
 * AJAX-обработчики для CRUD-операций с таксономиями.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Создание таксономии** — сохранение новой пользовательской таксономии для предмета.
 * 2. **Обновление таксономии** — изменение названия, типа отображения и флага обязательности.
 * 3. **Удаление таксономии** — удаление таксономии и всех её терминов каскадно.
 *
 * ### Архитектурная роль:
 *
 * Делегирует операции с БД репозиторию TaxonomyRepository, а удаление терминов — TermManager.
 */
class TaxonomySettingsCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), requireKey(), respond() и др.
	use Sanitizer;   // Трейт с методами sanitizeText(), sanitizeBool() и др.

	/**
	 * Конструктор.
	 *
	 * @param TaxonomyRepository $taxonomies Репозиторий таксономий
	 * @param TermManager        $terms      Менеджер терминов для удаления
	 */
	public function __construct(
		private readonly TaxonomyRepository $taxonomies,
		private readonly TermManager $terms,
	) {
		parent::__construct();
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Создаёт новую таксономию для предмета.
	 *
	 * @return void
	 */
	public function ajaxStoreTaxonomy(): void {
		$this->authorize( Nonce::Subject );

		// Получение данных из POST
		$subject_key = $this->requireKey( 'subject_key' );
		$tax_suffix  = $this->requireKey( 'tax_slug' );
		$tax_name    = $this->requireText( 'tax_name', error: 'Название таксономии обязательно' );

		$display_type = $this->getValidatedDisplayType();
		// Формирование полного слага: {subject_key}_{suffix} (например, math_author)
		$tax_slug = "{$subject_key}_{$tax_suffix}";

		// strlen() — максимальная длина слага таксономии в WordPress — 32 символа
		if ( strlen( $tax_slug ) > 32 ) {
			$this->error( 'Ярлык слишком длинный (макс. ' . ( 32 - strlen( $subject_key ) - 1 ) . ' символов)' );
		}

		// taxonomy_exists() — проверяет, зарегистрирована ли таксономия в WordPress
		if ( taxonomy_exists( $tax_slug ) ) {
			$this->error( "Таксономия «{$tax_slug}» уже существует в системе", array( 'slug' => $tax_slug ) );
		}

		// sanitizeBool() — преобразует значение в 1 или 0 для БД
		$result = $this->taxonomies->update(
			array(
				'subject_key'  => $subject_key,
				'tax_slug'     => $tax_slug,
				'name'         => $tax_name,
				'display_type' => $display_type,
				'is_required'  => $this->sanitizeBool( 'is_required' ),
			)
		);

		if ( $result ) {
			// flush_rewrite_rules() — перестраивает правила ЧПУ после регистрации новой таксономии
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Не удалось сохранить таксономию',
			success_msg: 'Таксономия создана'
		);
	}

	/**
	 * Обновляет существующую таксономию (название, тип отображения).
	 *
	 * @return void
	 */
	public function ajaxUpdateTaxonomy(): void {
		$this->authorize( Nonce::Subject );

		$subject_key = $this->requireKey( 'subject_key' );
		$tax_slug    = $this->requireKey( 'tax_slug' );
		$tax_name    = $this->requireText( 'tax_name', error: 'Название обязательно' );

		$result = $this->taxonomies->update(
			array(
				'subject_key'  => $subject_key,
				'tax_slug'     => $tax_slug,
				'name'         => $tax_name,
				'display_type' => $this->getValidatedDisplayType(),
				'is_required'  => $this->sanitizeBool( 'is_required' ),
			)
		);

		if ( $result ) {
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Ошибка при обновлении таксономии',
			success_msg: 'Таксономия обновлена'
		);
	}

	/**
	 * Удаляет таксономию предмета и все её термины из базы данных.
	 *
	 * @return void
	 */
	public function ajaxDeleteTaxonomy(): void {
		$this->authorize( Nonce::Subject );

		$subject_key = $this->requireKey( 'subject_key' );
		$tax_slug    = $this->requireKey( 'tax_slug' );

		// deleteAll() — удаляет все термины указанной таксономии из таблиц wp_terms
		$this->terms->deleteAll( $tax_slug );

		$result = $this->taxonomies->delete(
			array(
				'subject_key' => $subject_key,
				'tax_slug'    => $tax_slug,
			)
		);

		if ( $result ) {
			flush_rewrite_rules();
		}

		$this->respond(
			$result,
			error_msg: 'Ошибка при удалении таксономии',
			success_msg: 'Таксономия удалена'
		);
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Валидация типа отображения таксономии.
	 *
	 * @return string
	 */
	private function getValidatedDisplayType(): string {
		$type = $this->sanitizeText( 'display_type' );
		// in_array() — проверяет, что значение входит в список допустимых
		return in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ? $type : 'select';
	}
}
