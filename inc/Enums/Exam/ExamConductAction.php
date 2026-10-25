<?php
/**
 * Действия строки доски; серверные команды повторно проверяют доступ.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Enums\Exam;

/** Действия строки доски; серверные команды повторно проверяют доступ. */
enum ExamConductAction: string {
	case Cancel           = 'cancel';
	case DeleteRegistration = 'delete_registration';
	case Transfer         = 'transfer';
	case Extend           = 'extend';
	case Arrival          = 'arrival';
	case OpenWork         = 'open_work';
	case Admit            = 'admit';
	case ConfirmGuest     = 'confirm_guest';
	case EntryLink        = 'entry_link';
	case ResultLink       = 'result_link';
	case Anonymize        = 'anonymize';
	case DeleteGuest      = 'delete_guest';
	case MarkEntryPassed  = 'mark_entry_passed';
	case MarkResultPassed = 'mark_result_passed';
	case RevokeResultLink = 'revoke_result_link';
}
