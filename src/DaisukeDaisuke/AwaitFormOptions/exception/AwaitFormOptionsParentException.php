<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions\exception;

use cosmicpe\awaitform\AwaitFormException;

/**
 * @see AwaitFormException
 */
final class AwaitFormOptionsParentException extends AwaitFormOptionsExcption{
	public const ERR_VERIFICATION_FAILED = 200001;
}
