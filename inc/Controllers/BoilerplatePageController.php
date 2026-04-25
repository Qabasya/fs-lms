<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

class BoilerplatePageController extends BaseController implements ServiceInterface {
	use TemplateRenderer;
	
	public function __construct(
		private readonly BoilerplateRepository $boilerplates,
		private readonly MetaBoxRepository $metaboxes,
		private readonly SubjectRepository $subjects,
	) {
		parent::__construct();
	}
	
	public function register(): void {
		// TODO: Implement register() method.
	}
	
	/**
	 * Главная точка входа для отрисовки страницы (вызывается из AdminCallbacks).
	 */
	public function displayPage(): void {
		$subject_key = sanitize_text_field( wp_unslash( $_GET['subject'] ?? '' ) );
		$term_slug   = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );
		$action      = sanitize_text_field( wp_unslash( $_GET['action'] ?? 'list' ) );
		
		if ( empty( $subject_key ) || empty( $term_slug ) ) {
			echo '<div class="notice notice-error"><p>Ошибка: недостаточно данных.</p></div>';
			return;
		}
		
		match ( $action ) {
			'new', 'edit' => $this->renderEditor( $subject_key, $term_slug ),
			default       => $this->renderList( $subject_key, $term_slug ),
		};
	}
	
	/**
	 * Отрисовывает список шаблонов.
	 */
	private function renderList( string $subject_key, string $term_slug ): void {
		$boilerplates = $this->boilerplates->getBoilerplates( $subject_key, $term_slug );
		$taxonomy     = $subject_key . '_task_number';
		$term_object  = get_term_by( 'slug', $term_slug, $taxonomy );
		
		$display_name = ( $term_object && ! empty( $term_object->description ) )
			? $term_object->description
			: $term_slug;
		
		$subject_dto = $this->subjects->getByKey( $subject_key );
		
		$this->render( 'boilerplate-list', [
			'subject'              => $subject_key,
			'term'                 => $term_slug,
			'boilerplates'         => $boilerplates,
			'display_name'         => $display_name,
			'subject_display_name' => $subject_dto ? $subject_dto->name : $subject_key,
			'back_url'             => admin_url( "admin.php?page=fs_subject_{$subject_key}&tab=tab-5" ),
		]);
	}
	
	/**
	 * Отрисовывает редактор.
	 */
	private function renderEditor( string $subject_key, string $term_slug ): void {
		$uid = sanitize_text_field( wp_unslash( $_GET['uid'] ?? '' ) );
		$boilerplate = $uid ? $this->boilerplates->findBoilerplate( $subject_key, $term_slug, $uid ) : null;
		$assignment  = $this->metaboxes->getAssignment( $subject_key, $term_slug );
		
		$template_id = ( $assignment && ! empty( $assignment->template_id ) )
			? ( $assignment->template_id instanceof \UnitEnum ? $assignment->template_id->name : $assignment->template_id )
			: 'standard_task';
		
		$is_edit = null !== $boilerplate;
		
		$this->render( 'boilerplate-editor', [
			'subject'        => $subject_key,
			'term'           => $term_slug,
			'template_id'    => $template_id,
			'is_edit'        => $is_edit,
			'page_title'     => $is_edit ? 'Редактировать условие' : 'Добавить условие',
			'bp_uid'         => $is_edit ? $boilerplate->uid : uniqid( 'bp_' ),
			'bp_title'       => $is_edit ? $boilerplate->title : '',
			'content_fields' => $boilerplate ? $this->decodeContent( $boilerplate->content ) : [],
			'fields'         => $this->getConditionFields( $template_id ),
		]);
	}
	
	private function getConditionFields( string $template_id ): array {
		$templates = apply_filters( 'fs_lms_get_templates', [] );
		foreach ( $templates as $tpl ) {
			if ( isset( $tpl->id ) && $tpl->id === $template_id ) {
				$cond_fields = array_filter( $tpl->fields, fn($k) => str_contains($k, '_condition'), ARRAY_FILTER_USE_KEY );
				return !empty($cond_fields) ? $cond_fields : [ 'task_condition' => [ 'label' => 'Условие' ] ];
			}
		}
		return [ 'task_condition' => [ 'label' => 'Условие задания' ] ];
	}
	
	private function decodeContent( string $raw ): array {
		if ( empty( $raw ) ) return [];
		$decoded = json_decode( $raw, true );
		return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $decoded : [ 'task_condition' => $raw ];
	}
}