<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Access\Capability;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Services\Profile\RankingAccessService;
use Inc\Services\Profile\SubjectRankingService;
use Inc\Services\Profile\Learner\LearnerRankingSection;
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
		$subjects=$this->createStub(SubjectRepository::class);
		$subjects->method('readActive')->willReturn([(object)['key'=>'inf_ege','name'=>'Информатика'],(object)['key'=>'python','name'=>'Python']]);
		return new RankingAccessService($groups,$subjects);
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
			$roster[]=['student_person_id'=>$id,'snapshot_last_name'=>$name,'snapshot_first_name'=>'Иван','snapshot_middle_name'=>'','group_id'=>$gid,'group_name'=>$group];
		}
		$repo->method('roster')->willReturn($roster);
		$config=$this->createStub(PluginConfig::class);
		$config->method('rankingSettings')->willReturn(['homework'=>1.0,'attendance_bonus'=>0.0]);
		$ranking=new LearnerRankingSection($repo,new RankingPointsPolicy($config));
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
		self::assertCount(2,$data['rows'][0]['groups']);
		self::assertCount(2,$data['groups']);
	}

	public function test_group_filter_keeps_direction_wide_places(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service()->build(7,'inf_ege',2);
		self::assertSame([1,3,4],array_column($data['rows'],'person_id'));
		self::assertSame([1,2,3],array_column($data['rows'],'rank'));
	}

	public function test_foreign_group_is_rejected(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->build(7,'inf_ege',999);
	}

	public function test_summer_has_no_ranking_and_no_names(): void {
		$GLOBALS['_test_user_can'][7]=[Capability::ManageLmsTeaching->value=>true];
		$data=$this->service('2026-07-20')->build(7,'inf_ege');
		self::assertTrue($data['summer']);
		self::assertNull($data['period']);
		self::assertSame([],$data['rows']);
	}

	public function test_student_without_staff_permission_cannot_request_any_direction(): void {
		self::assertSame([],$this->access()->subjectsFor(7));
		$this->expectException(\DomainException::class);
		$this->service()->build(7,'inf_ege');
	}
}
