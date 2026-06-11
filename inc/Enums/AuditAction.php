<?php

namespace Inc\Enums;


enum AuditAction: string {
	// ===== Заявки (Applications) =====

	/** Создание новой заявки */
	case CreateApplication = 'create_application';

	/** Отправка данных родителя */
	case SubmitParentData = 'submit_parent_data';

	/** Администратор скопировал ссылку на вступление */
	case CopyJoinLink = 'copy_join_link';

	/** Администратор назначил родителя ученику */
	case AddParent = 'add_parent_join_link';

	/** Просмотр заявки */
	case ViewApplication = 'view_application';

	/** Истечение срока заявки */
	case ExpireApplication = 'expire_application';

	// ===== Зачисление (Enrollment) =====

	/** Успешное зачисление студента */
	case EnrollStudent = 'enroll_student';

	/** Ошибка при зачислении студента */
	case EnrollStudentFailed = 'enroll_student_failed';

	/** Отчисление студента из группы */
	case StudentExpelled = 'student_expelled';

	// ===== Личные данные (Person) =====

	/** Обновление информации о человеке */
	case UpdatePerson = 'update_person';

	// ===== Согласия (Consent) =====

	/** Подписание согласия */
	case ConsentSigned = 'consent_signed';

	/** Отзыв согласия */
	case ConsentWithdrawn = 'consent_withdrawn';

	// ===== Корзина заявок =====

	/** Перемещение заявки в корзину */
	case MoveToTrash = 'move_to_trash';

	/** Восстановление заявки из корзины */
	case RestoreFromTrash = 'restore_from_trash';

	/** Очистка корзины (физическое удаление всех trash-заявок) */
	case EmptyTrash = 'empty_trash';

	/** Редактирование данных заявки администратором */
	case UpdateApplicationData = 'update_application_data';
	case UpdateReviewData      = 'update_review_data';
	case CancelEnrollment      = 'cancel_enrollment';
	case StartEnrollment       = 'start_enrollment';

	/** Восстановление ученика из архива */
	case RestoreFromArchive = 'restore_from_archive';

	/** Восстановление ученика в реестр (активных учеников) */
	case StudentRestored = 'student_restored';

	/** Просмотр JOIN-ссылки */
	case ViewJoinLink = 'view_join_link';

	/** Экспорт записи архива отчисленных */
	case ExpelledArchiveExported = 'expelled_archive_exported';
	case StudentExported         = 'student_exported';
	case ParentExported          = 'parent_exported';

	// ===== Личные данные (Person) — дополнительные =====

	/** Запрос удаления ПД */
	case PiiDeletionRequested = 'pii_deletion_requested';

	// ===== Пароли =====

	case PasswordGenerated = 'password_generated';
	case PasswordSet       = 'password_set';

	// ===== Жёсткое удаление =====

	case HardDeletePerson   = 'hard_delete_person';
	case HardDeleteGroup    = 'hard_delete_group';
	case HardDeleteSubject  = 'hard_delete_subject';
	case HardDeletePeriod   = 'hard_delete_period';


	/**
	 * Возвращает человекочитаемое название действия
	 *
	 * Используется в логах
	 *
	 * @return string Название шаблона
	 */
	public function label(): string {
		return match ( $this ) {
			self::CreateApplication      => 'Создана заявка',
			self::SubmitParentData       => 'Подписано родителем',
			self::CopyJoinLink           => 'Ссылка для родителя скопирована',
			self::AddParent              => 'Назначен родитель',
			self::ViewApplication        => 'Просмотр заявки',
			self::ExpireApplication      => 'Время действие ссылки истекло',

			self::EnrollStudent          => 'Зачислен ученик',
			self::EnrollStudentFailed    => 'Ошибка зачисления',
			self::StudentExpelled        => 'Отчислен ученик',

			self::UpdatePerson           => 'Обновлены данные',

			self::ConsentSigned          => 'Согласие подписано',
			self::ConsentWithdrawn       => 'Согласие отозвано',

			self::MoveToTrash            => 'Перемещение в корзину',
			self::RestoreFromTrash       => 'Восстановление из корзины',
			self::EmptyTrash             => 'Очистка корзины',

			self::UpdateApplicationData  => 'Обновление данных заявки',
			self::UpdateReviewData       => 'Обновление данных проверки',
			self::CancelEnrollment       => 'Зачисление не завершено',
			self::StartEnrollment        => 'Начато зачисление',

			self::RestoreFromArchive     => 'Восстановление из архива',
			self::StudentRestored        => 'Ученик восстановлен',
			self::ViewJoinLink           => 'Просмотр JOIN-ссылки',

			self::ExpelledArchiveExported => 'Экспортированы записи архива',
			self::StudentExported         => 'Экспортированы данные ученика(ов)',
			self::ParentExported          => 'Экспортированы данные родителя(ей)',

			self::PiiDeletionRequested   => 'Запрос удаления ПД',
			self::PasswordGenerated      => 'Пароль сгенерирован',
			self::PasswordSet            => 'Пароль установлен',

			self::HardDeletePerson       => 'Жёсткое удаление пользователя',
			self::HardDeleteGroup        => 'Жёсткое удаление группы',
			self::HardDeleteSubject      => 'Жёсткое удаление предмета',
			self::HardDeletePeriod       => 'Жёсткое удаление периода',
		};
	}
}
