<?php

declare( strict_types=1 );

namespace Inc\Cli;

/** Сигнал `exam selftest`: проверки закончены — транзакция должна откатиться (данные самопроверки в базе не остаются). */
final class SelfTestRollback extends \RuntimeException {
}
