<?php

declare( strict_types=1 );

namespace Unit\Services\Template;

use Inc\Enums\Subject\TemplateCategory;
use Inc\MetaBoxes\Templates\CodeTaskTemplate;
use Inc\MetaBoxes\Templates\FileCodeTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\TwoFileCodeTaskTemplate;
use Inc\Services\Template\TemplateRegistry;
use PHPUnit\Framework\TestCase;

class TemplateCategoryTest extends TestCase {

	public function test_code_templates_declare_code_category(): void {
		self::assertSame( TemplateCategory::Code, ( new CodeTaskTemplate() )->get_category() );
		self::assertSame( TemplateCategory::Code, ( new FileCodeTaskTemplate() )->get_category() );
		self::assertSame( TemplateCategory::Code, ( new TwoFileCodeTaskTemplate() )->get_category() );
	}

	public function test_standard_defaults_to_question(): void {
		self::assertSame( TemplateCategory::Question, ( new StandardTaskTemplate() )->get_category() );
	}

	public function test_registry_filters_by_category(): void {
		$registry = new TemplateRegistry();

		foreach ( $registry->getByCategory( TemplateCategory::Code ) as $tpl ) {
			self::assertSame( TemplateCategory::Code, $tpl->get_category() );
		}
		foreach ( $registry->getByCategory( TemplateCategory::Question ) as $tpl ) {
			self::assertSame( TemplateCategory::Question, $tpl->get_category() );
		}
	}

	public function test_default_for_category_matches_category(): void {
		$registry = new TemplateRegistry();

		self::assertSame( TemplateCategory::Code, $registry->defaultForCategory( TemplateCategory::Code )?->get_category() );
		self::assertSame( TemplateCategory::Question, $registry->defaultForCategory( TemplateCategory::Question )?->get_category() );
	}
}