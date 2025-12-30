<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions\exception;

use cosmicpe\awaitform\AwaitFormException;

/**
 * @see AwaitFormException
 */
final class AwaitFormOptionsChildException extends AwaitFormOptionsExcption{
	final public const ERR_COROUTINE_ABORTED = 300001;
	final public const ERR_VERIFICATION_FAILED = 300002;
}
