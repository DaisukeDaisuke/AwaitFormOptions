<?php

namespace DaisukeDaisuke\AwaitFormOptions;

use pocketmine\utils\Utils;
use cosmicpe\awaitform\FormControl;
use cosmicpe\awaitform\Button;
use cosmicpe\awaitform\AwaitFormException;
use InvalidArgumentException;

trait FormBridgeTrait{
	private RequestResponseBridge $bridge;

	public function setBridge(RequestResponseBridge $bridge): void {
		$this->bridge = $bridge;
	}

	/**
	 * @param array $value
	 * @return \Generator
	 * @throws AwaitFormException
	 */
	public function request(array $value) : \Generator{
		$missed = false;
		foreach($value as $v){
			if(!is_array($v) && count($value) === 2){
				$missed = true;
			}
			break;
		}
		if($missed){
			$value = [$value];
		}

		Utils::validateArrayValueType($value, static function(FormControl|Button|array $value){});
		if(!isset($this->bridge)){
			throw new \BadFunctionCallException("bridge is not set");
		}
		try{
			return yield from $this->bridge->request($value);
		}catch(InvalidArgumentException $exception){
			/**
			 * @see AwaitFormOptions::sendMenuAsync()
			 * @see AwaitFormOptions::sendFormAsync()
			 */
			//HACK: Making backtraces useful
			$dbg = debug_backtrace();
			throw new AwaitFromOptionsInvalidValueException($exception->getMessage()." in ".($dbg[0]['file'] ?? "null")."(".($dbg[0]['line'] ?? "null")."): ".($dbg[0]['class'] ?? "null")."->".($dbg[0]['function'] ?? "null")."()", 0, $exception);
		}finally{
			unset($this->bridge);
		}
	}
}