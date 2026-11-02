<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Access\Capability;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Profile\RankingAccessService;
use Inc\Services\Profile\SubjectRankingService;
use Inc\Services\Profile\RankingCalculationService;
use Inc\Services\Profile\Learner\RankingPointsPolicy;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class SubjectRankingServiceTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_test_user_can'][7] );
		parent::tearDown();
	}

	private function access(): RankingAccessService {
		$groups=$this->createStub(GroupsRepository::class);
		$groups->method('findByTeacherId')->willReturn([(object)['subject_key'=>'inf_ege']]);
		$groups->method('findByIds')->willReturn([11 => (object)['subject_key'=>'inf_ege']]);
		$subjects=$this->createStub(SubjectRepository::class);
		$subjects->method('readActive')->willReturn([(object)['key'=>'inf_ege','name'=>'Информатика'],(object)['key'=>'python','name'=>'Python']]);
		$records=$this->createStub(StudentRecordRepository::class);
		// Ученик personId=5 состоит в одной активной группе — её предмет и есть его единственное направление.
		$records->method('findActiveByStudent')->willReturn([(object)['groupId'=>11]]);
		return new RankingAccessService($groups,$subjects,$records);
	}

	private function service(string $now='2026-10-31 12:00:00'): SubjectRankingService {
		$repo=$this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1,2,3,4]);
		$repo->method('workEvents')->willReturn([
			['student_person_id'=>1,'source'=>'homework','task_points'=>90.0],
			['student_person_id'=>2,'source'=>'homework','task_points'=>90.0],
			['student_person_id'=>3,'source'=>'homework','task_points'=>89.0],
		]);
		$roster=[];
		foreach ([[2,'Белов',1,'Группа1'],[1,'Андреев',1,'Группа1'],[1,'Андреев',2,'Группа2'],[3,'Волков',2,'Группа2'],[4,'Громов',2,'Группа2']]as[$id,$name,$gid,$group]) {
			$roster[]=['student_person_id'=>$id,'snapshot_last_name'=>$name,'snapshot_first_name'=>'Иван','snapshot_middle_name'=>'Иванович','group_id'=>$gid,'group_name'=>$group];
		}
		$repo->method('roster')->willReturn($roster);
		$config=$this->createStub(PluginConfig::class);
		$config->method('rankingSettings')->willReturn(['homework'=>1.0,'attendance_bonus'=>0.0]);
		$ranking=new RankingCalculationService($repo,new RankingPointsPolicy($config));
		$clock=$this->createStub(ClockInterface::class);
		$clock->method('now')->willReturn($now);
		return new SubjectRankingService($this->access(),$repo,$ranking,$clock);
	}

	public function test_teacher_sees_only_directions_with_own_groups(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		self::assertSame(['inf_ege'],array_column($this->access()->subjectsFor(7),'key'));
		$this->expectException(\DomainException::class);
		$this->service()->build(7,'python');
	}

	public function test_office_and_admin_can_select_all_active_directions(): void {
		foreach ([Capability::ManageLmsPlatform,Capability::Admin]as$capability) {
			$GLOBALS['_test_user_can'][7]=[$capability->value=>true];
			self::assertSame(['inf_ege','python'],array_column($this->access()->subjectsFor(7),'key'));
			self::assertCount(4,$this->service()->build(7,'python')['rows']);
		}
	}

	public function test_table_uses_subject_dense_ranks_and_stable_names_with_multiple_groups(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service()->build(7,'inf_ege');
		self::assertSame([1,2,3,4],array_column($data['rows'],'person_id'));
		self::assertSame([1,1,2,3],array_column($data['rows'],'rank'));
		self::assertSame([90.0,90.0,89.0,0.0],array_column($data['rows'],'score'));
		self::assertSame('Андреев Иван',$data['rows'][0]['name'],'без отчества — как в журнале');
		self::assertCount(2,$data['rows'][0]['groups']);
		self::assertCount(2,$data['groups']);
		self::assertSame('Информатика',$data['groups'][0]['subject'],'у групп есть имя предмета — кнопка выбора как в «Сводке»');
	}

	public function test_group_filter_recalculates_dense_places_within_the_group(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		// Вне группы 1 есть ученик с большим баллом (Громов, 95, группа 2): по направлению
		// места группы 1 — 2 и 3, а внутри своей группы рейтинг пересчитывается: 1 и 2.
		$repo=$this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1,2,4]);
		$repo->method('workEvents')->willReturn([
			['student_person_id'=>4,'source'=>'homework','task_points'=>95.0],
			['student_person_id'=>2,'source'=>'homework','task_points'=>90.0],
			['student_person_id'=>1,'source'=>'homework','task_points'=>89.0],
		]);
		$roster=[];
		foreach ([[2,'Белов',1],[1,'Андреев',1],[4,'Громов',2]] as [$id,$name,$gid]) {
			$roster[]=['student_person_id'=>$id,'snapshot_last_name'=>$name,'snapshot_first_name'=>'Иван','snapshot_middle_name'=>'','group_id'=>$gid,'group_name'=>'Г'.$gid];
		}
		$repo->method('roster')->willReturn($roster);
		$config=$this->createStub(PluginConfig::class);
		$config->method('rankingSettings')->willReturn(['homework'=>1.0,'attendance_bonus'=>0.0]);
		$clock=$this->createStub(ClockInterface::class);
		$clock->method('now')->willReturn('2026-10-31 12:00:00');
		$service=new SubjectRankingService($this->access(),$repo,new RankingCalculationService($repo,new RankingPointsPolicy($config)),$clock);

		$all=$service->build(7,'inf_ege');
		self::assertSame([1,2,3],array_column($all['rows'],'rank'),'все группы — общие места направления (эталон)');

		$group=$service->build(7,'inf_ege',1);
		self::assertSame([2,1],array_column($group['rows'],'person_id'));
		self::assertSame([1,2],array_column($group['rows'],'rank'),'внутри группы места пересчитаны');
	}

	public function test_foreign_group_is_rejected(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->build(7,'inf_ege',999);
	}

	public function test_summer_computes_ranking_with_summer_period(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service('2026-07-20')->build(7,'inf_ege');
		self::assertSame('Июнь — август 2026',$data['period']);
		self::assertSame('2026-06-01 00:00:00',$data['period_from']);
		self::assertCount(4,$data['rows'],'летом рейтинг считается');
		self::assertTrue($data['periods'][0]['current']);
		self::assertArrayNotHasKey('summer',$data);
	}

	public function test_explicit_period_selects_its_bounds_and_label(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service('2027-02-15 12:00:00')->build(7,'inf_ege',0,'2026-09-01 00:00:00');
		self::assertSame('Сентябрь — ноябрь 2026',$data['period']);
		self::assertSame('2026-09-01 00:00:00',$data['period_from']);
	}

	public function test_unknown_period_is_rejected(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->build(7,'inf_ege',0,'2020-01-01 00:00:00');
	}

	public function test_previous_academic_year_period_is_not_offered(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		// Март — май 2026 относится к прошлому учебному году для «сейчас» = октябрь 2026.
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->build(7,'inf_ege',0,'2026-03-01 00:00:00');
	}

	public function test_student_without_staff_permission_cannot_request_any_direction(): void {
		self::assertSame([],$this->access()->subjectsFor(7));
		$this->expectException(\DomainException::class);
		$this->service()->build(7,'inf_ege');
	}

	public function test_learner_directions_are_subjects_of_own_active_groups(): void {
		self::assertSame(['inf_ege'],array_column($this->access()->learnerSubjectsFor(5),'key'));
	}

	public function test_learner_gets_same_table_but_only_for_own_direction(): void {
		$data=$this->service()->buildForLearner(5,'inf_ege');
		self::assertSame([1,2,3,4],array_column($data['rows'],'person_id'));
		self::assertSame([1,1,2,3],array_column($data['rows'],'rank'));
		self::assertCount(2,$data['groups']);
		$this->expectException(\DomainException::class);
		$this->service()->buildForLearner(5,'python');
	}

	public function test_learner_group_filter_and_period_work_like_for_staff(): void {
		$data=$this->service('2027-02-15 12:00:00')->buildForLearner(5,'inf_ege',2,'2026-09-01 00:00:00');
		self::assertSame([1,3,4],array_column($data['rows'],'person_id'));
		self::assertSame('Сентябрь — ноябрь 2026',$data['period']);
	}

	public function test_shared_calculation_matches_tiles_and_group_ranks_with_decimal_ties(): void {
		$GLOBALS['_test_user_can'][7] = [Capability::ManageLmsTeaching->value => true];
		$repo = $this->createStub(LearnerRankingRepository::class);
		$repo->method('studentIds')->willReturn([1, 2, 3]);
		$repo->method('workEvents')->willReturn([
			['student_person_id' => 1, 'source' => 'practice', 'task_points' => 1],
			['student_person_id' => 1, 'source' => 'homework', 'task_points' => 1],
			['student_person_id' => 2, 'source' => 'survey', 'task_points' => 1],
			['student_person_id' => 3, 'source' => 'practice', 'task_points' => 1],
		]);
		$repo->method('roster')->willReturn(array_map(static fn(int $id): array => [
			'student_person_id' => $id, 'snapshot_last_name' => 'Student ' . $id, 'snapshot_first_name' => '',
			'group_id' => 1 === $id ? 1 : 2, 'group_name' => 'Group',
		], [1, 2, 3]));
		$config = $this->createMock(PluginConfig::class);
		$config->expects(self::exactly(3))->method('rankingSettings')->willReturn([
			'practice' => 0.1, 'homework' => 0.2, 'survey' => 0.3, 'attendance_bonus' => 0.0,
		]);
		$ranking = new RankingCalculationService($repo, new RankingPointsPolicy($config));
		$clock = $this->createStub(ClockInterface::class);
		$clock->method('now')->willReturn('2026-11-01 12:00:00');
		$service = new SubjectRankingService($this->access(), $repo, $ranking, $clock);
		$tile = (new \Inc\Services\Profile\Learner\LearnerRankingSection($ranking))->forGroups(2, [['subject_key' => 'inf_ege']], '2026-11-01 12:00:00')[0];
		$all = $service->build(7, 'inf_ege');
		$student = array_column($all['rows'], null, 'person_id')[2];
		self::assertSame($tile['score'], $student['score']);
		self::assertSame($tile['rank'], $student['rank']);
		self::assertSame([1, 1, 2], array_column($all['rows'], 'rank'));
		$group = $service->build(7, 'inf_ege', 2);
		self::assertSame([2, 3], array_column($group['rows'], 'person_id'));
		self::assertSame([1, 2], array_column($group['rows'], 'rank'));
	}
}
