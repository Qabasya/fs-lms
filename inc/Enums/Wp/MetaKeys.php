<?php

declare( strict_types=1 );


namespace Inc\Enums\Wp;

enum MetaKeys: string {

	case EncPassword = "fs_lms_enc_password";
	case PersonID = "fs_lms_person_id";
	case UserStatus = "fs_lms_user_status";
	/** Последний запрос преподавателя к сайту (местное время, mysql) — «Преподавателя нет на месте». */
	case LastSeenAt = "fs_lms_last_seen_at";

}
