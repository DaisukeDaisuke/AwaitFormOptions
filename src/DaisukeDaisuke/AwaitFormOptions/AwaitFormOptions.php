<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use cosmicpe\awaitform\AwaitForm;
use cosmicpe\awaitform\AwaitFormException;
use cosmicpe\awaitform\Button;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsChildException;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsExpectedCrashException;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsInvalidValueException;
use DaisukeDaisuke\AwaitFormOptions\exception\AwaitFormOptionsParentException;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use SOFe\AwaitGenerator\Await;
use function array_combine;
use function array_is_list;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function is_array;
use function is_object;
use function is_scalar;

class AwaitFormOptions{
	final private function __construct(){

	}

	/**
	 * @param array<FormOptions> $options
	 * @throws \Throwable
	 */
	public static function sendForm(Player $player, string $title, array $options) : void{
		Await::f2c(function() use ($options, $title, $player){
			try{
				yield from self::sendFormAsync($player, $title, $options);
			}catch(FormValidationException|AwaitFormOptionsParentException){
			}
		});
	}

	/**
	 * @param array<FormOptions> $options Awaitable form option providers
	 * @throws FormValidationException|AwaitFormOptionsParentException|AwaitFormOptionsInvalidValueException I don't write \throwable because it's enough to piss off phpstan :<
	 */
	public static function sendFormAsync(Player $player, string $title, array $options) : \Generator{
		$bridge = new RequestResponseBridge();
		try{
			Utils::validateArrayValueType($options, static function(FormOptions $value){
			});
		}catch(\TypeError $exception){
			throw new AwaitFormOptionsInvalidValueException("Options must be an array of FormOptions instances: " . $exception->getMessage(), 0, $exception);
		}

		$needDispose = [];
		try{
			$options_keys = [];
			$counter = 0;
			foreach($options as $key => $option){
				$needDispose[] = $option;
				$option->setBridge($bridge);
				$forms = $option->getOptions();
				try{
					Utils::validateArrayValueType($forms, static function(\Generator|FormOptions $value) : void{
					});
				}catch(\TypeError){
					throw new AwaitFormOptionsInvalidValueException($option::class . "::getOptions() must return an array(list) of \Generator, see also AwaitFormOptions::sendFromAsync()");
				}

				foreach($forms as $key1 => $item){
					if($item instanceof FormOptions){
						$item->setBridge($bridge);
						$needDispose[] = $item;
						$value = $item->getOptions();
						try{
							Utils::validateArrayValueType($value, static function(\Generator $value) : void{
							});
						}catch(\TypeError){
							throw new AwaitFormOptionsInvalidValueException($option::class . "::getOptions(): Doubly nested form options cannot be expanded");
						}
						$bridge->all($counter, $key1, $value, array_keys($value));
					}else{
						$bridge->one($counter, $key1, $item);
					}
				}
				$options_keys[] = $key;
				$counter++;
			}

			$counter = 0;
			$index = [];
			$options = [];
			foreach(yield from $bridge->getAllExpected() as $id => $array){
				$keys = [];
				foreach($array as $key => $item){
					if(is_array($item)){
						if(!(array_is_list($item) && count($item) === 2)){
							if(array_is_list($item) && count($item) !== 2){
								$bridge->reject(
									$id,
									new AwaitFormOptionsExpectedCrashException(
										"The request value must be a 2-element list array [Button, key], but an array with " . count($item) . " element(s) was given. \n" .
										" (key: " . $key . "). " .
										"Ensure that your form returns an array like [Button, SelectedKey]. " .
										"See also: AwaitFormOptions::sendFormAsync()"
									)
								);
							}else{
								$item = array_values($item);
							}
						}
						[$item, $key] = $item;
					}
					// is_object check is required: Player can be scalar-converted, but keys must be strictly scalar
					if(!is_scalar($key) || is_object($key)){
						//HACK: Making backtraces useful
						$bridge->reject($id, new AwaitFormOptionsExpectedCrashException("key must be scalar, see also AwaitFormOptions::sendFormAsync()"));
						return [];
					}
					$keys[] = $key;
					$options[] = $item;
				}
				$index[$id] = [$counter, count($array), $keys];
				$counter += count($array);
			}

			try{
				$menu = AwaitForm::form($title, $options);
				$result = yield from $menu->request($player);

				foreach($index as $id => [$start, $length, $keys]){
					$values = array_slice($result, $start, $length);
					$bridge->solve($id, array_combine($keys, $values));
				}

				$bridge->tryFinalize();

				return array_combine($options_keys, $bridge->getReturns());
			}catch(AwaitFormException $awaitFormException){
				try{
					$bridge->rejectsAll(new AwaitFormOptionsChildException("", $awaitFormException->getCode()));
				}catch(AwaitFormOptionsChildException $exception){
					throw new AwaitFormOptionsExpectedCrashException($exception->getMessage(), $exception->getCode(), $exception);
				}
				throw new AwaitFormOptionsParentException("Unhandled AwaitFormOptionsParentException", $awaitFormException->getCode());
			}catch(FormValidationException $formValidationException){
				throw new AwaitFormOptionsParentException("Invalid data was received:" . $formValidationException->getMessage(), AwaitFormOptionsParentException::ERR_VERIFICATION_FAILED);
			}catch(AwaitFormOptionsChildException $exception){
				throw new AwaitFormOptionsExpectedCrashException("Unhandled AwaitFormOptionsChildException", $exception->getCode(), $exception);
			}
		}finally{
			foreach($needDispose as $item){
				$item->dispose();
			}
			$bridge->dispose();
			unset($bridge, $needDispose);
		}
		//This code path should be unreachable :(
	}

	/**
	 * @param array<MenuOptions> $buttons
	 * @throws \Throwable
	 */
	public static function sendMenu(Player $player, string $title, string $content, array $buttons) : void{
		Await::f2c(function() use ($content, $buttons, $title, $player){
			try{
				yield from self::sendMenuAsync($player, $title, $content, $buttons);
			}catch(FormValidationException|AwaitFormOptionsParentException){
			}
		});
	}

	/**
	 * @param array<MenuOptions> $buttons Awaitable menu option providers
	 * @return \Generator<mixed>
	 * @throws FormValidationException|AwaitFormOptionsExpectedCrashException|AwaitFormOptionsParentException I don't write \throwable because it's enough to piss off phpstan :<
	 */
	public static function sendMenuAsync(Player $player, string $title, string $content, array $buttons) : \Generator{
		$bridge = new RequestResponseBridge();

		try{
			Utils::validateArrayValueType($buttons, static function(MenuOptions $value) : void{
			});
		}catch(\TypeError $exception){
			throw new AwaitFormOptionsInvalidValueException("Buttons must be an array of MenuOptions instances: " . $exception->getMessage(), 0, $exception);
		}

		$needDispose = [];
		try{

			$flatOptions = [];
			foreach($buttons as $key1 => $option){
				$needDispose[] = $option;
				$option->setBridge($bridge);

				$array = $option->getOptions();
				try{
					Utils::validateArrayValueType($array, static function(\Generator|MenuOptions $value) : void{
					});
				}catch(\TypeError){
					throw new AwaitFormOptionsInvalidValueException($option::class . "::getOptions() must return an array(list) of \Generator, see also AwaitFormOptions::sendMenuAsync()");
				}

				foreach($array as $key2 => $item){
					if($item instanceof MenuOptions){
						$item->setBridge($bridge);
						$needDispose[] = $item;
						$value = $item->getOptions();
						try{
							Utils::validateArrayValueType($value, static function(\Generator $value) : void{
							});
						}catch(\TypeError){
							throw new AwaitFormOptionsInvalidValueException($option::class . "::getOptions(): Doubly nested form options cannot be expanded");
						}
						foreach($value as $sub){
							$flatOptions[] = $sub;
						}
					}else{
						$flatOptions[] = $item;
					}
				}
			}

			if(count($flatOptions) !== 0){
				$bridge->race(0, $flatOptions);
			}

			// 各 MenuOptions に紐づくボタン群を構築
			$counter = 0;
			$index = []; // id => [start, end, keys]
			$flatButtons = []; // 表示用に flatten された Button[]

			foreach(yield from $bridge->getAllExpected() as $id => $array){
				$keys = [];
				$count = 0;
				$start = $counter;
				foreach($array as $key => $item){
					if(is_array($item)){
						if(!(array_is_list($item) && count($item) === 2)){
							if(array_is_list($item) && count($item) !== 2){
								$bridge->reject(
									$id,
									new AwaitFormOptionsExpectedCrashException(
										"The request value must be a 2-element list array [Button, key], but an array with " . count($item) . " element(s) was given. \n" .
										" (key: " . $key . "). " .
										"Ensure that your form returns an array like [Button, SelectedKey]. " .
										"See also: AwaitFormOptions::sendMenuAsync()"
									)
								);
							}else{
								$item = array_values($item);
							}
						}
						[$item, $key] = $item;
					}
					if(!$item instanceof Button){
						//HACK: Making backtraces useful
						$bridge->reject($id, new AwaitFormOptionsExpectedCrashException("Button is required, see also AwaitFormOptions::sendMenuAsync()"));
					}
					$flatButtons[$counter++] = $item;
					$keys[$count++] = $key;
				}
				$index[$id] = [$start, $counter - 1, $keys];
			}

			try{
				// フォーム送信
				$menu = AwaitForm::menu($title, $content, $flatButtons);
				$selected = yield from $menu->request($player); // 0-based indexが返る

				// 選択されたボタンがどの範囲に属するか判定
				foreach($index as $id => [$start, $end, $keys]){
					if($selected >= $start && $selected <= $end){
						$keyIndex = $selected - $start;
						$key = $keys[$keyIndex] ?? null;

						// 該当オプションへ解決通知
						$bridge->solve($id, $key);
						$bridge->tryFinalize();
						$returns = $bridge->getReturns();
						return $returns[$id] ?? null;
					}
				}
			}catch(AwaitFormException $awaitFormException){
				try{
					$bridge->rejectsAll(new AwaitFormOptionsChildException("", $awaitFormException->getCode()));
				}catch(AwaitFormOptionsChildException $exception){
					throw new AwaitFormOptionsExpectedCrashException($exception->getMessage(), $exception->getCode(), $exception);
				}
				throw new AwaitFormOptionsParentException("Unhandled AwaitFormOptionsParentException", $awaitFormException->getCode());
			}catch(FormValidationException $formValidationException){
				throw new AwaitFormOptionsParentException("Invalid data was received:" . $formValidationException->getMessage(), AwaitFormOptionsParentException::ERR_VERIFICATION_FAILED);
			}catch(AwaitFormOptionsChildException $exception){
				throw new AwaitFormOptionsExpectedCrashException("Unhandled AwaitFormOptionsChildException", $exception->getCode(), $exception);
			}
			// 該当しなかった場合はフォーム不正とみなす
			throw new AwaitFormOptionsParentException("An invalid button selection was made", AwaitFormOptionsParentException::ERR_VERIFICATION_FAILED);
		}finally{
			foreach($needDispose as $item){
				$item->dispose();
			}
			$bridge->dispose();
			unset($bridge, $needDispose, $buttons, $flatButtons, $flatOptions, $index, $keys, $returns);
		}
	}
}
