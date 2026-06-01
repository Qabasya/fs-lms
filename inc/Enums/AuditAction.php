<?php

namespace Inc\Enums;

enum AuditAction: string {
	// ===== Заявки (Applications) =====

	/** Создание новой заявки */
	case CreateApplication = 'create_application';

	/** Отправка данных родителя */
	case SubmitParentData = 'submit_parent_data';

	/** Просмотр ссылки на вступление */
	case ViewJoinLink = 'view_join_link';

	/** Просмотр заявки */
	case ViewApplication = 'view_application';

	/** Истечение срока заявки */
	case ExpireApplication = 'expire_application';

	// ===== Зачисление (Enrollment) =====

	/** Успешное зачисление студента */
	case EnrollStudent = 'enroll_student';

	/** Ошибка при зачислении студента */
	case EnrollStudentFailed = 'enroll_student_failed';

	/** Прекращение обучения студента */
	case TerminateEnrollment = 'terminate_enrollment';

	// ===== Связи родитель-студент =====

	/** Создание связи (родитель → студент) */
	case CreateRelationship = 'create_relationship';

	/** Замена связи (изменение родителя) */
	case ReplaceRelationship = 'replace_relationship';

	/** Прекращение связи */
	case TerminateRelationship = 'terminate_relationship';

	// ===== Личные данные (Person) =====

	/** Обновление информации о человеке */
	case UpdatePerson = 'update_person';

	// ===== Согласия (Consent) =====

	/** Подписание согласия */
	case ConsentSigned = 'consent_signed';

	/** Отзыв согласия */
	case ConsentWithdrawn = 'consent_withdrawn';

	// ===== Аутентификация и пароли =====

	/** Генерация ссылки для установки пароля */
	case PasswordLinkGenerated = 'password_link_generated';

	/** Генерация и установка пароля администратором при зачислении */
	case PasswordGenerated = 'password_generated';

	/** Установка пароля пользователем */
	case PasswordSet = 'password_set';

	// ===== PII (Персональные данные) =====

	/** Запрос на удаление персональных данных */
	case PiiDeletionRequested = 'pii_deletion_requested';

	/** Экспорт персональных данных пользователя */
	case PiiExported = 'pii_exported';

	// ===== Списки и просмотры =====

	/** Просмотр списка заявок */
	case ViewApplicationsList = 'view_applications_list';

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
	case StartEnrollment       = 'start_enrollment';
}
