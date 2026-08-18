<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Modules\EgeComputer\Callbacks\PreviewResultCallbacks;
use Inc\Modules\EgeComputer\Config\EgeComputerConfig;
use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use Inc\Modules\EgeComputer\Config\OgeScaleConfig;
use Inc\Modules\EgeComputer\EgeComputerModule;
use Inc\Modules\EgeComputer\Services\KegeResultSheetService;
use PHPUnit\Framework\TestCase;

/**
 * EgeComputerModule::applyStationSettings() — подмена time/attempts/passScore/
 * scoreMap значениями StationExamConfig для станций, no-op для Control.
 */
class EgeComputerModuleStationSettingsTest extends TestCase {

	private EgeComputerModule $module;

	protected function setUp(): void {
		parent::setUp();
		$this->module = new EgeComputerModule(
			$this->createMock( EgeComputerConfig::class ),
			$this->createMock( KegeResultSheetService::class ),
			$this->createMock( PreviewResultCallbacks::class ),
		);
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Экзамен', taskIds: [ 10, 20 ],
			timeLimit: 999, attemptsAllowed: 999, passScore: 999.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: [ 10 => 1.0 ], scoreMap: [ 1 => 1 ],
			taskNumbers: [ 10 => '1' ], introHtml: '<p>кастом</p>',
		);
	}

	public function test_control_is_untouched(): void {
		$dto      = $this->assessment( AssessmentKind::Control );
		$result   = $this->module->applyStationSettings( $dto );

		self::assertSame( $dto, $result );
	}

	public function test_ege_computer_gets_station_settings(): void {
		$dto    = $this->assessment( AssessmentKind::EgeComputer );
		$result = $this->module->applyStationSettings( $dto );

		self::assertNotSame( $dto, $result );
		self::assertSame( 235, $result->timeLimit );
		self::assertSame( 1, $result->attemptsAllowed );
		self::assertSame( 6.0, $result->passScore );
		self::assertSame( KegeScaleConfig::scale(), $result->scoreMap );
		// Остальные поля не тронуты.
		self::assertSame( $dto->taskIds, $result->taskIds );
		self::assertSame( $dto->introHtml, $result->introHtml );
	}

	/**
	 * §3.5 (.docs/Tasks.md): баллы за задание больше не берутся из per-assessment
	 * meta (`taskPoints`) — вычисляются заново по номеру: 1 балл для обычной
	 * позиции, 2 для №26/27 (KegeScaleConfig::MULTI_ANSWER), задание без
	 * распознанного номера в карту не попадает.
	 */
	public function test_ege_computer_recomputes_task_points_from_position(): void {
		$dto = new AssessmentDTO(
			id: 1, subjectKey: 'inf_ege', title: 'ЕГЭ', taskIds: [ 10, 20, 30 ],
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::EgeComputer, taskPoints: [ 10 => 99.0 ], scoreMap: [],
			// 10 → обычная позиция «1»; 20 → «26» (два ответа, 2 балла); 30 — без номера.
			taskNumbers: [ 10 => '1', 20 => '26' ],
		);

		$result = $this->module->applyStationSettings( $dto );

		self::assertSame( [ 10 => 1.0, 20 => 2.0 ], $result->taskPoints );
	}

	/** OGE 13/14 — баллы из OgeCriteriaConfig через OgeScaleConfig::pointsForPosition(). */
	public function test_oge_computer_recomputes_task_points_from_position(): void {
		$dto = new AssessmentDTO(
			id: 1, subjectKey: 'inf_oge', title: 'ОГЭ', taskIds: [ 1, 2, 3 ],
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::OgeComputer, taskPoints: [], scoreMap: [],
			taskNumbers: [ 1 => '5', 2 => '13', 3 => '14' ],
		);

		$result = $this->module->applyStationSettings( $dto );

		self::assertSame( [ 1 => 1.0, 2 => 2.0, 3 => 3.0 ], $result->taskPoints );
	}

	public function test_oge_computer_gets_station_settings(): void {
		$dto    = $this->assessment( AssessmentKind::OgeComputer );
		$result = $this->module->applyStationSettings( $dto );

		self::assertSame( 150, $result->timeLimit );
		self::assertSame( 1, $result->attemptsAllowed );
		self::assertSame( 5.0, $result->passScore );
		self::assertSame( OgeScaleConfig::scale(), $result->scoreMap );
	}

	public function test_resolve_oge_rubric_for_known_position(): void {
		$dto = new AssessmentDTO(
			id: 1, subjectKey: 'inf_oge', title: 'ОГЭ', taskIds: [ 501 ],
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::OgeComputer, taskPoints: [], scoreMap: [],
			taskNumbers: [ 501 => '14' ],
		);

		$rubric = $this->module->resolveOgeRubric( null, $dto, 501 );

		self::assertNotNull( $rubric );
		self::assertSame( 3, $rubric['max_points'] );
	}

	public function test_resolve_oge_rubric_null_for_non_oge_kind(): void {
		$dto = $this->assessment( AssessmentKind::EgeComputer );

		self::assertNull( $this->module->resolveOgeRubric( null, $dto, 10 ) );
	}

	public function test_resolve_oge_rubric_null_when_task_has_no_manual_number(): void {
		$dto = new AssessmentDTO(
			id: 1, subjectKey: 'inf_oge', title: 'ОГЭ', taskIds: [ 501 ],
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::OgeComputer, taskPoints: [], scoreMap: [],
			taskNumbers: [],
		);

		self::assertNull( $this->module->resolveOgeRubric( null, $dto, 501 ) );
	}

	public function test_resolve_oge_rubric_null_for_auto_graded_position(): void {
		$dto = new AssessmentDTO(
			id: 1, subjectKey: 'inf_oge', title: 'ОГЭ', taskIds: [ 501 ],
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: AssessmentKind::OgeComputer, taskPoints: [], scoreMap: [],
			taskNumbers: [ 501 => '5' ], // позиция 1-12 — автопроверка, рубрики нет
		);

		self::assertNull( $this->module->resolveOgeRubric( null, $dto, 501 ) );
	}

	/**
	 * §6.4 (.docs/Tasks.md): резолвер станции обязан отдавать станционный шаблон
	 * и для ОГЭ, не только для ЕГЭ — до фикса `resolveRenderer()` возвращал
	 * `$default` (générique-флоу) для любого kind, кроме EgeComputer.
	 */
	public function test_resolve_renderer_resolves_station_template_for_oge_computer(): void {
		$default = '/generic/attempt.php';

		$resolved = $this->module->resolveRenderer( $default, AssessmentKind::OgeComputer->value, 'inf_oge' );

		self::assertNotSame( $default, $resolved );
		self::assertStringEndsWith( 'ege-computer.php', str_replace( '\\', '/', $resolved ) );
	}

	public function test_resolve_renderer_resolves_station_template_for_ege_computer(): void {
		$default = '/generic/attempt.php';

		$resolved = $this->module->resolveRenderer( $default, AssessmentKind::EgeComputer->value, 'inf_ege' );

		self::assertNotSame( $default, $resolved );
		self::assertStringEndsWith( 'ege-computer.php', str_replace( '\\', '/', $resolved ) );
	}

	public function test_resolve_renderer_leaves_control_kind_untouched(): void {
		$default = '/generic/attempt.php';

		self::assertSame( $default, $this->module->resolveRenderer( $default, AssessmentKind::Control->value, 'inf' ) );
	}
}
