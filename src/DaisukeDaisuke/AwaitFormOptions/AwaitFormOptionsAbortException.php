<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use cosmicpe\awaitform\AwaitFormException;

/**
 * Exceptions to clean up coroutines that are no longer in use
 *
 * @internal If possible, don't catch it, consider catching AwaitFormException
 * @see AwaitFormException
 */
class AwaitFormOptionsAbortException extends \RuntimeException{

}
