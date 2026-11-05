<?php
declare( strict_types=1 );
namespace Unit\Services\Group;
use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\TeacherGroupResolver;
use Inc\Services\Group\StudentDirectoryService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
#[AllowMockObjectsWithoutExpectations]
class StudentDirectoryServiceTest extends TestCase {
	private TeacherGroupResolver $scope;
	private StudentRecordRepository $records;
	private StudentDirectoryService $directory;
	protected function setUp(): void {
		$this->scope=$this->createMock(TeacherGroupResolver::class);
		$this->records=$this->createMock(StudentRecordRepository::class);
		$groups=$this->createStub(GroupsRepository::class);
		$groups->method('findById')->willReturnCallback(static fn(int $id):object=>(object)['id'=>$id,'name'=>'Group '.$id,'subject_key'=>'inf','self_paced_course_id'=>8===$id?77:null,'course_id'=>77]);
		$courses=$this->createStub(CourseManager::class);
		$courses->method('get')->willReturn(CourseDTO::fromArray(['id'=>77,'title'=>'Курс Python']));
		$this->directory=new StudentDirectoryService($this->scope,$this->records,$groups,$this->createStub(SubjectRepository::class),$courses);
	}
	public function test_office_gets_course_participants_and_counts_in_existing_directory_with_history():void {
		$this->scope->expects(self::once())->method('idsFor')->with(1,true)->willReturn([8,9]);
		$this->records->expects(self::once())->method('findAllByGroup')->with(8)->willReturn([
			StudentRecordDTO::fromArray(['student_person_id'=>10,'snapshot_last_name'=>'One']),
			StudentRecordDTO::fromArray(['student_person_id'=>11,'snapshot_last_name'=>'Two','status'=>'expelled']),
		]);
		$this->records->expects(self::once())->method('findActiveByGroupId')->with(9)->willReturn([StudentRecordDTO::fromArray(['student_person_id'=>12])]);
		$data=$this->directory->forTeacher(1,true);
		$course=array_values(array_filter($data['groups'],static fn($g)=>8===$g['id']))[0];
		self::assertSame('Курс Python',$course['name']);self::assertSame(77,$course['self_paced_course_id']);
		self::assertSame(2,$course['participants_count']);self::assertSame(1,$course['active_participants_count']);
		self::assertCount(3,$data['students']);
	}
	public function test_teacher_roster_scope_is_never_widened_by_course_authorship():void {
		$this->scope->expects(self::exactly(2))->method('idsFor')->with(2,false)->willReturn([9]);
		$this->records->method('findActiveByGroupId')->with(9)->willReturn([StudentRecordDTO::fromArray(['student_person_id'=>12])]);
		$this->records->expects(self::never())->method('findAllByGroup');
		self::assertSame([9],array_column($this->directory->forTeacher(2,false)['groups'],'id'));
		self::assertFalse($this->directory->canViewStudentSummary(8,10,2,false));
	}
	public function test_service_summary_requires_real_participation_even_for_office():void {
		$this->scope->method('idsFor')->with(1,true)->willReturn([8]);
		$this->records->method('findAllByStudentAndGroup')->willReturnCallback(static fn(int $person,int $group):array=>10===$person?[StudentRecordDTO::fromArray(['student_person_id'=>10,'group_id'=>$group])]:[]);
		self::assertTrue($this->directory->canViewStudentSummary(8,10,1,true));
		self::assertFalse($this->directory->canViewStudentSummary(8,11,1,true));
	}
}
