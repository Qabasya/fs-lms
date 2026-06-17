<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\PostMetaName;
use Inc\Managers\PostManager;
use Inc\Services\PostTypeResolver;
use Inc\Services\Template\TemplateRegistry;

/**
 * Class ProblemsController
 *
 * Регистрирует глобальный CPT `fs_lms_problems` и таксономию `problem_tag`.
 * Добавляет метабокс выбора шаблона редактора (те же шаблоны, что у заданий).
 *
 * @package Inc\Controllers
 */
class ProblemsController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly TemplateRegistry $registry,
		private readonly PostManager      $posts,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'registerCpt' ) );
		add_action( 'init', array( $this, 'registerTaxonomy' ) );
		add_action( 'add_meta_boxes', array( $this, 'addTemplateMetabox' ) );
		add_action( 'save_post_' . PostTypeResolver::problems(), array( $this, 'saveTemplateType' ) );
	}

	public function registerCpt(): void {
		register_post_type( PostTypeResolver::problems(), array(
			'labels'              => array(
				'name'          => 'Задачи',
				'singular_name' => 'Задача',
				'add_new_item'  => 'Добавить задачу',
				'edit_item'     => 'Редактировать задачу',
				'search_items'  => 'Найти задачу',
				'not_found'     => 'Задачи не найдены',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'capability_type'     => 'fs_lms_content',
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'editor' ),
			'rewrite'             => false,
		) );
	}

	public function registerTaxonomy(): void {
		register_taxonomy( 'problem_tag', array( PostTypeResolver::problems() ), array(
			'labels'            => array(
				'name'          => 'Тематика',
				'singular_name' => 'Тема',
				'add_new_item'  => 'Добавить тему',
				'all_items'     => 'Все темы',
			),
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_in_rest'      => false,
			'show_admin_column' => true,
			'rewrite'           => false,
		) );
	}

	public function addTemplateMetabox(): void {
		add_meta_box(
			'fs_lms_problem_template',
			'Тип шаблона',
			array( $this, 'renderTemplateMetabox' ),
			PostTypeResolver::problems(),
			'side',
		);
	}

	public function renderTemplateMetabox( \WP_Post $post ): void {
		$current = (string) get_post_meta( $post->ID, PostMetaName::TemplateType->value, true );
		wp_nonce_field( 'fs_lms_problem_template_save', 'fs_lms_problem_template_nonce' );
		echo '<select name="' . esc_attr( PostMetaName::TemplateType->value ) . '" style="width:100%">';
		foreach ( $this->registry->getAll() as $template ) {
			$selected = selected( $current, $template->get_id(), false );
			echo '<option value="' . esc_attr( $template->get_id() ) . '"' . $selected . '>'
				. esc_html( $template->get_name() ) . '</option>';
		}
		echo '</select>';
	}

	public function saveTemplateType( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = $_POST['fs_lms_problem_template_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'fs_lms_problem_template_save' ) ) {
			return;
		}
		$template_id = sanitize_key( $_POST[ PostMetaName::TemplateType->value ] ?? '' );
		if ( '' !== $template_id ) {
			$this->posts->updateMeta( $post_id, PostMetaName::TemplateType->value, $template_id );
		}
	}
}
