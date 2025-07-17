<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use BadFunctionCallException;
use cosmicpe\awaitform\AwaitFormException;
use cosmicpe\awaitform\Button;
use cosmicpe\awaitform\FormControl;
use InvalidArgumentException;
use pocketmine\utils\Utils;
use function array_key_first;
use function array_key_last;
use function count;
use function debug_backtrace;
use const DEBUG_BACKTRACE_IGNORE_ARGS;

trait FormBridgeTrait{
	private RequestResponseBridge $bridge;
	private bool $requested = false;

	/**
	 * @internal
	 */
	final public function setBridge(RequestResponseBridge $bridge) : void{
		$this->bridge = $bridge;
	}

	/**
	 * @internal
	 */
	final public function dispose() : void{
		unset($this->bridge);
	}

	/**
	 * Wait until all other options are complete
	 */
	final public function finalize() : \Generator{
		yield from $this->bridge->finalize();
	}

	/**
	 * Instruct AwaitFormOptions to add an elements
	 * When this function is awaited, the parent coroutines receives the form response or exception
	 *
	 * @throws AwaitFormException
	 */
	final public function request(array $value) : \Generator{
		$missed = false;
		if(count($value) === 2){
			if($value[array_key_first($value)] instanceof FormControl||$value[array_key_first($value)] instanceof Button){
				if(!$value[array_key_last($value)] instanceof FormControl&&!$value[array_key_last($value)] instanceof Button){
					$missed = true;
				}
			}
		}
		if($missed){
			$value = [$value];
		}

		try{
			Utils::validateArrayValueType($value, static function(FormControl|Button|array $value){
			});

			if($this->requested){
				throw new BadFunctionCallException("Maybe you called \$this->request() twice? This is not allowed to prevent deadlocks");
			}

			return yield from $this->bridge->request($value);
		}catch(InvalidArgumentException|BadFunctionCallException|\TypeError $exception){
			/**
			 * @see AwaitFormOptions::sendMenuAsync()
			 * @see AwaitFormOptions::sendFormAsync()
			 */
			//HACK: Making backtraces useful
			$dbg = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
			throw new AwaitFormOptionsInvalidValueException($exception->getMessage()." in ".($dbg[0]['file'] ?? "null")."(".($dbg[0]['line'] ?? "null")."): ".($dbg[0]['class'] ?? "null")."->".($dbg[0]['function'] ?? "null")."()", 0);
		}finally{
			$this->requested = true;
		}
	}
}
