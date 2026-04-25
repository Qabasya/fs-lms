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
 * Отвечает за создание, обновление и удаление кастомных таксономий предметов.
 *
 * @package Inc\Callbacks
 */
class TaxonomySettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

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
	 * Итоговый slug = {subject_key}_{suffix}, где suffix — данные от клиента.
	 * Например: math_author, inf_genre и т.д.
	 *
	 * @return void
	 */
	public function ajaxStoreTaxonomy(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		// Получение и валидация данных
		$subject_key = $this->requireKey( 'subject_key' );
		$tax_suffix  = $this->requireKey( 'tax_slug' );
		$tax_name    = $this->requireText( 'tax_name', error: 'Название таксономии обязательно' );

		$display_type = $this->getValidatedDisplayType();
		$tax_slug     = "{$subject_key}_{$tax_suffix}";

		// Проверка длины слага (максимум 32 символа в WordPress)
		if ( strlen( $tax_slug ) > 32 ) {
			$this->error( 'Ярлык слишком длинный (макс. ' . ( 32 - strlen( $subject_key ) - 1 ) . ' символов)' );
		}

		// Проверка существования
		if ( taxonomy_exists( $tax_slug ) ) {
			$this->error( "Таксономия «{$tax_slug}» уже существует в системе", array( 'slug' => $tax_slug ) );
		}

		// Сохранение через репозиторий
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
	 * Slug не меняется — передаётся полный slug из клиента.
	 *
	 * @return void
	 */
	public function ajaxUpdateTaxonomy(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		// Получение и валидация данных таксономии
		$subject_key = $this->requireKey( 'subject_key' );
		$tax_slug    = $this->requireKey( 'tax_slug' );
		$tax_name    = $this->requireText( 'tax_name', error: 'Название обязательно' );

		// Обновление через репозиторий
		$result = $this->taxonomies->update([
			'subject_key'  => $subject_key,
			'tax_slug'     => $tax_slug,
			'name'         => $tax_name,
			'display_type' => $this->getValidatedDisplayType(),
			'is_required'  => $this->sanitizeBool( 'is_required' ),
		]);
		
		if ( $result ) {
			flush_rewrite_rules();
		}
		
		$this->respond( $result, 'Ошибка при обновлении таксономии', 'Таксономия обновлена' );
	}

	/**
	 * Удаляет таксономию предмета и все её термины из базы данных.
	 *
	 * @return void
	 */
	public function ajaxDeleteTaxonomy(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		// Получение и валидация ключа предмета и слага таксономии
		$subject_key = $this->requireKey( 'subject_key' );
		$tax_slug    = $this->requireKey( 'tax_slug' );

		// Удаление всех терминов таксономии через менеджер
		$this->terms->deleteAll( $tax_slug );

		// Удаление записи о таксономии из репозитория
		$result = $this->taxonomies->delete([
			'subject_key' => $subject_key,
			'tax_slug'    => $tax_slug,
		]);
		
		if ( $result ) {
			flush_rewrite_rules();
		}
		
		$this->respond( $result, 'Ошибка при удалении таксономии', 'Таксономия удалена' );
	}
	


	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Валидация типа отображения.
	 * Оставляем как маленький хелпер, так как это специфичная бизнес-логика.
	 *
	 * @return string Валидный тип отображения ('select', 'radio', 'checkbox')
	 */
	private function getValidatedDisplayType(): string {
		$type = $this->sanitizeText( 'display_type' );
		return in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ? $type : 'select';
	}
	
}
