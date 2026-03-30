<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\TaxonomyRepository;

class TaxonomySettingsCallbacks extends BaseController {
	protected TaxonomyRepository $taxonomies;

	public function __construct( TaxonomyRepository $taxonomies ) {
		parent::__construct();
		$this->taxonomies = $taxonomies;

		// Регистрация AJAX
		add_action( 'wp_ajax_fs_store_taxonomy', [ $this, 'storeTaxonomy' ] );
		add_action( 'wp_ajax_fs_update_taxonomy', [ $this, 'updateTaxonomy' ] );
		add_action( 'wp_ajax_fs_delete_taxonomy', [ $this, 'deleteTaxonomy' ] );
	}

	/**
	 * Ядро обработки запроса
	 */
	protected function executeOperation( string $operation ): void {
		check_ajax_referer( 'fs_subject_nonce', 'security' );

		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( 'Нет прав' );
		}

		$subject_key = sanitize_title( $_POST['subject_key'] ?? '' );
		$tax_slug    = sanitize_title( $_POST['tax_slug'] ?? '' );
		$tax_name    = sanitize_text_field( $_POST['tax_name'] ?? '' );

		if ( empty( $subject_key ) || empty( $tax_slug ) ) {
			wp_send_json_error( 'Недостаточно данных для операции' );
		}

		// --- ЗАЩИТА СИСТЕМНОЙ ТАКСОНОМИИ ---
		if ( $tax_slug === "{$subject_key}_task_number" ) {
			wp_send_json_error( 'Эту таксономию нельзя изменять или удалять!' );
		}

		$success = false;
		$message = '';

		switch ( $operation ) {
			case 'store':
				if ( empty( $tax_name ) ) {
					wp_send_json_error( 'Укажите название!' );
				}
				$success = $this->taxonomies->add( $subject_key, $tax_slug, $tax_name );
				$message = "Таксономия «{$tax_name}» добавлена";
				break;

			case 'update':
				$success = $this->taxonomies->update( $subject_key, $tax_slug, $tax_name );
				$message = "Таксономия обновлена";
				break;

			case 'delete':
				$success = $this->taxonomies->delete( $subject_key, $tax_slug );
				$message = "Таксономия удалена";
				break;
		}

		if ( $success ) {
			// Важно: таксономии требуют сброса правил, чтобы заработали URL
			flush_rewrite_rules();
			wp_send_json_success( $message );
		} else {
			wp_send_json_error( 'Ошибка репозитория при выполнении операции' );
		}
	}

	/**
	 * Создание новой таксономии
	 */
	public function storeTaxonomy(): void {
		$this->executeOperation( 'store' );
	}

	/**
	 * Обновление (Quick Edit)
	 */
	public function updateTaxonomy(): void {
		$this->executeOperation( 'update' );
	}

	/**
	 * Удаление
	 */
	public function deleteTaxonomy(): void {
		$this->executeOperation( 'delete' );
	}
}