<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use cosmicpe\awaitform\FormControl;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsChildException;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsExpectedCrashException;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsInvalidValueException;
use pocketmine\utils\Utils;
use function array_key_first;
use function array_key_last;
use function count;
use function debug_backtrace;
use const DEBUG_BACKTRACE_IGNORE_ARGS;
use cosmicpe\awaitform\MenuElement;

trait FormBridgeTrait{
	private RequestResponseBridge $bridge;
	private bool $requested = false;
	private ?int $reservesId = null;
	private bool $disposed = false;

	/**
	 * Attaches the shared request/response bridge to this option instance.
	 *
	 * Option instances are single-use. Once dispose() has been called, setting a new
	 * bridge would allow stale coroutine state to be reused, so it is rejected early.
	 *
	 * @internal
	 * @throws AwaitFormOptionsInvalidValueException
	 */
	final public function setBridge(RequestResponseBridge $bridge) : void{
		if($this->isDisposed()){
			throw new AwaitFormOptionsInvalidValueException("Option reuse detected, class: " . static::class);
		}
		$this->bridge = $bridge;
	}

	/**
	 * Marks this option instance as disposed, drops internal bridge references, and
	 * gives the concrete option a chance to release user-owned resources.
	 *
	 * @internal
	 */
	final public function dispose() : void{
		$this->setDisposed(true);
		unset($this->bridge, $this->reservesId);
		$this->userDispose();
	}

	/**
	 * Releases resources owned by the concrete option implementation.
	 *
	 * This hook is called by dispose() after bridge state has been detached.
	 */
	abstract protected function userDispose() : void;

	/**
	 * Suspends this child generator until the parent form/menu has received the
	 * player response and all request() calls have been solved.
	 *
	 * Higher priority values are resumed first when the parent calls tryFinalize().
	 *
	 * @return \Generator<mixed, mixed, mixed, void>
	 */
	final protected function finalize(int $priority = 0) : \Generator{
		yield from $this->bridge->finalize($priority);
	}

	/**
	 * Reserves one future request() call.
	 *
	 * Use this when the generator must await something before reaching request().
	 * The parent will wait for all reserved requests in getAllExpected(); therefore,
	 * a scheduled generator must eventually call request() exactly once.
	 *
	 * @throws AwaitFormOptionsExpectedCrashException
	 */
	final protected function schedule() : void{
		if($this->reservesId !== null){
			throw new AwaitFormOptionsExpectedCrashException("Maybe you called \$this->schedule() twice? This is not allowed to prevent deadlocks, class: " . static::class);
		}
		$this->reservesId = $this->bridge->schedule();
	}

	/**
	 * Registers form controls or menu elements with the parent bridge and suspends
	 * until the parent solves this request.
	 *
	 * A single `[FormControl|MenuElement, key]` tuple is normalized to a one-item
	 * request list. A generator may call request() only once; a second call is
	 * treated as an expected crash because the parent request accounting would no
	 * longer be reliable.
	 *
	 * @param array{FormControl|MenuElement, mixed}|array<int|string, FormControl|MenuElement|array{FormControl|MenuElement, mixed}> $value
	 * @return \Generator<mixed, mixed, mixed, mixed>
	 * @throws AwaitFormOptionsChildException|AwaitFormOptionsExpectedCrashException
	 */
	final protected function request(array $value) : \Generator{
		$missed = false;
		if(count($value) === 2){
			if($value[array_key_first($value)] instanceof FormControl || $value[array_key_first($value)] instanceof MenuElement){
				if(!$value[array_key_last($value)] instanceof FormControl && !$value[array_key_last($value)] instanceof MenuElement){
					$missed = true;
				}
			}
		}
		if($missed){
			$value = [$value];
		}

		try{
			Utils::validateArrayValueType($value, static function(FormControl|MenuElement|array $value){
			});

			if($this->requested){
				throw new AwaitFormOptionsExpectedCrashException("Maybe you called \$this->request() twice? This is not allowed to prevent deadlocks");
			}

			return yield from $this->bridge->request($value, $this->reservesId);
		}catch(AwaitFormOptionsExpectedCrashException|\TypeError $exception){
			/**
			 * @see AwaitFormOptions::sendMenuAsync()
			 * @see AwaitFormOptions::sendFormAsync()
			 */
			//HACK: Making backtraces useful
			$dbg = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
			throw new AwaitFormOptionsExpectedCrashException($exception->getMessage() . " in " . ($dbg[0]['file'] ?? "null") . "(" . ($dbg[0]['line'] ?? "null") . "): " . ($dbg[0]['class'] ?? "null") . "->" . ($dbg[0]['function'] ?? "null") . "()", 0);
		}finally{
			$this->requested = true;
		}
	}

	/**
	 * Returns whether this option instance has already been disposed.
	 */
	public function isDisposed() : bool{
		return $this->disposed;
	}

	/**
	 * Updates the local disposed flag.
	 */
	private function setDisposed(bool $disposed) : void{
		$this->disposed = $disposed;
	}
}
