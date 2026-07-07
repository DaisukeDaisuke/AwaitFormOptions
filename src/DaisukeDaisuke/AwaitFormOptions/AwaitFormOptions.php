<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use cosmicpe\awaitform\AwaitForm;
use cosmicpe\awaitform\AwaitFormException;
use cosmicpe\awaitform\FormControl;
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
use cosmicpe\awaitform\MenuElement;

class AwaitFormOptions{
	final private function __construct(){

	}

	/**
	 * Starts sendFormAsync() as a standalone coroutine.
	 *
	 * Player rejection and parent-level form failures are swallowed. Developer
	 * errors, including invalid option types and invalid request payloads, are not
	 * swallowed.
	 *
	 * @param array<int|string, FormOptions> $options
	 * @throws AwaitFormOptionsExpectedCrashException
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
	 * Sends one custom form assembled from FormOptions child generators.
	 *
	 * Each FormOptions instance contributes zero or more child generators from
	 * getOptions(). Every child must call yield from $this->request(...) once,
	 * unless it was intentionally omitted by returning an empty getOptions() array.
	 * One level of nested FormOptions is supported.
	 *
	 * Form request values must be FormControl instances, or [FormControl, key]
	 * tuples. After the player submits the form, every waiting child generator is
	 * resumed with its keyed response array. The return value preserves both the
	 * top-level $options keys and each FormOptions::getOptions() key. If an option
	 * returns no child generators, its result is an empty array.
	 *
	 * ```php
	 * $result = yield from AwaitFormOptions::sendFormAsync(
	 *     player: $player,
	 *     title: "Profile",
	 *     options: [
	 *         "profile" => new ProfileFormOptions($player),
	 *     ],
	 * );
	 *
	 * // Example shape:
	 * // [
	 * //     "profile" => [
	 * //         "name" => "Steve",
	 * //     ],
	 * // ]
	 * ```
	 *
	 * AwaitForm rejection, logout, or validation failures are reported to the
	 * caller as AwaitFormOptionsParentException. Child generators receive
	 * AwaitFormOptionsChildException through request() for the same failure.
	 * Developer mistakes such as invalid option types or invalid request values
	 * are reported as AwaitFormOptionsExpectedCrashException subclasses.
	 *
	 * @param array<int|string, FormOptions> $options Awaitable form option providers
	 * @return \Generator<mixed, mixed, mixed, array<int|string, array<int|string, mixed>>>
	 * @throws AwaitFormOptionsExpectedCrashException|AwaitFormOptionsParentException
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
					throw new AwaitFormOptionsInvalidValueException($option::class . "::getOptions() must return an array of \Generator or nested FormOptions, see also AwaitFormOptions::sendFormAsync()");
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
								$exception = new AwaitFormOptionsExpectedCrashException(
									"The request value must be a 2-element list array [FormControl, key], but an array with " . count($item) . " element(s) was given. \n" .
									" (key: " . $key . "). " .
									"Ensure that your form returns an array like [FormControl, SelectedKey]. " .
									"See also: AwaitFormOptions::sendFormAsync()"
								);
								$bridge->reject($id, $exception);
								throw $exception;
							}else{
								$item = array_values($item);
							}
						}
						[$item, $key] = $item;
					}
					if(!$item instanceof FormControl){
						$exception = new AwaitFormOptionsExpectedCrashException("FormControl is required, see also AwaitFormOptions::sendFormAsync()");
						$bridge->reject($id, $exception);
						throw $exception;
					}
					// is_object check is required: Player can be scalar-converted, but keys must be strictly scalar
					/** @phpstan-ignore function.impossibleType */
					if(!is_scalar($key) || is_object($key)){
						//HACK: Making backtraces useful
						$exception = new AwaitFormOptionsExpectedCrashException("key must be scalar, see also AwaitFormOptions::sendFormAsync()");
						$bridge->reject($id, $exception);
						throw $exception;
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

				$returns = $bridge->getReturns();
				$result = [];
				foreach($options_keys as $id => $key){
					$result[$key] = $returns[$id] ?? [];
				}
				return $result;
			}catch(AwaitFormException $awaitFormException){
				$bridge->rejectsAll(new AwaitFormOptionsChildException("", $awaitFormException->getCode()));
				throw new AwaitFormOptionsParentException("Unhandled AwaitFormOptionsParentException", $awaitFormException->getCode());
			}catch(FormValidationException $formValidationException){
				$bridge->rejectsAll(new AwaitFormOptionsChildException("", AwaitFormOptionsChildException::ERR_VERIFICATION_FAILED));
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
	 * Starts sendMenuAsync() as a standalone coroutine.
	 *
	 * Player rejection and parent-level form failures are swallowed. Developer
	 * errors, including invalid option types and invalid request payloads, are not
	 * swallowed.
	 *
	 * @param array<int|string, MenuOptions> $buttons
	 * @throws AwaitFormOptionsExpectedCrashException
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
	 * Sends one menu assembled from MenuOptions child generators.
	 *
	 * Every menu child generator is started as part of a race and should call
	 * yield from $this->request(...) once. Menu request values must be MenuElement
	 * instances, or [MenuElement, key] tuples. One level of nested MenuOptions is
	 * supported.
	 *
	 * After the player selects a menu element, only the generator that registered
	 * the selected request is resumed normally. Its return value becomes the return
	 * value of sendMenuAsync(). All other waiting menu generators are aborted with
	 * AwaitFormOptionsChildException::ERR_COROUTINE_ABORTED; their return values
	 * are ignored even if they catch the abort and return normally.
	 *
	 * ```php
	 * $selected = yield from AwaitFormOptions::sendMenuAsync(
	 *     player: $player,
	 *     title: "Food",
	 *     content: "Choose one",
	 *     buttons: [
	 *         new FoodMenuOptions($player),
	 *     ],
	 * );
	 *
	 * // $selected is the return value from the selected MenuOptions generator.
	 * ```
	 *
	 * AwaitForm rejection, logout, or validation failures are reported to the
	 * caller as AwaitFormOptionsParentException. Child generators receive
	 * AwaitFormOptionsChildException through request() for the same failure.
	 * Developer mistakes such as invalid option types or invalid request values
	 * are reported as AwaitFormOptionsExpectedCrashException subclasses.
	 *
	 * @param array<int|string, MenuOptions> $buttons Awaitable menu option providers
	 * @return \Generator<mixed, mixed, mixed, mixed>
	 * @throws AwaitFormOptionsExpectedCrashException|AwaitFormOptionsParentException
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
					throw new AwaitFormOptionsInvalidValueException($option::class . "::getOptions() must return an array of \Generator or nested MenuOptions, see also AwaitFormOptions::sendMenuAsync()");
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
			$flatButtons = []; // 表示用に flatten された MenuElement[]

			foreach(yield from $bridge->getAllExpected() as $id => $array){
				$keys = [];
				$count = 0;
				$start = $counter;
				foreach($array as $key => $item){
					if(is_array($item)){
						if(!(array_is_list($item) && count($item) === 2)){
							if(array_is_list($item) && count($item) !== 2){
								$exception = new AwaitFormOptionsExpectedCrashException(
									"The request value must be a 2-element list array [MenuElement, key], but an array with " . count($item) . " element(s) was given. \n" .
									" (key: " . $key . "). " .
									"Ensure that your menu returns an array like [MenuElement, SelectedValue]. " .
									"See also: AwaitFormOptions::sendMenuAsync()"
								);
								$bridge->reject($id, $exception);
								throw $exception;
							}else{
								$item = array_values($item);
							}
						}
						[$item, $key] = $item;
					}
					if(!$item instanceof MenuElement){
						//HACK: Making backtraces useful
						$exception = new AwaitFormOptionsExpectedCrashException("MenuElement is required, see also AwaitFormOptions::sendMenuAsync()");
						$bridge->reject($id, $exception);
						throw $exception;
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
				$bridge->rejectsAll(new AwaitFormOptionsChildException("", $awaitFormException->getCode()));
				throw new AwaitFormOptionsParentException("Unhandled AwaitFormOptionsParentException", $awaitFormException->getCode());
			}catch(FormValidationException $formValidationException){
				$bridge->rejectsAll(new AwaitFormOptionsChildException("", AwaitFormOptionsChildException::ERR_VERIFICATION_FAILED));
				throw new AwaitFormOptionsParentException("Invalid data was received:" . $formValidationException->getMessage(), AwaitFormOptionsParentException::ERR_VERIFICATION_FAILED);
			}catch(AwaitFormOptionsChildException $exception){
				throw new AwaitFormOptionsExpectedCrashException("Unhandled AwaitFormOptionsChildException", $exception->getCode(), $exception);
			}
			$bridge->rejectsAll(new AwaitFormOptionsChildException("", AwaitFormOptionsChildException::ERR_VERIFICATION_FAILED));
			// 該当しなかった場合はフォーム不正とみなす
			throw new AwaitFormOptionsParentException("An invalid MenuElement selection was made", AwaitFormOptionsParentException::ERR_VERIFICATION_FAILED);
		}finally{
			foreach($needDispose as $item){
				$item->dispose();
			}
			$bridge->dispose();
			unset($bridge, $needDispose, $buttons, $flatButtons, $flatOptions, $index, $keys, $returns);
		}
	}
}
