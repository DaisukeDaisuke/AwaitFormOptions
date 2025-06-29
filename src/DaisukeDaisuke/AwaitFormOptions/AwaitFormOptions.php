<?php

namespace DaisukeDaisuke\AwaitFormOptions;

use cosmicpe\awaitform\AwaitForm;
use pocketmine\utils\Utils;
use pocketmine\player\Player;
use SOFe\AwaitGenerator\Await;
use cosmicpe\awaitform\Button;
use pocketmine\form\FormValidationException;
use cosmicpe\awaitform\AwaitFormException;

class AwaitFormOptions{
	final private function __construct(){

	}

	/**
	 * @param Player $player
	 * @param string $title
	 * @param array<FormOptions> $options
	 * @param bool $neverRejects
	 * @return void
	 * @throws FormValidationException|AwaitFormException
	 */
	public static function sendForm(Player $player, string $title, array $options, bool $neverRejects = false) : void{
		Await::f2c(function() use ($neverRejects, $options, $title, $player){
			try{
				yield from self::sendFormAsync($player, $title, $options, $neverRejects, false);
			}catch(FormValidationException|AwaitFormException){
			}
		});
	}

	/**
	 * @param Player $player
	 * @param string $title
	 * @param array<FormOptions> $options await fun
	 * @param bool $neverRejects
	 * @param bool $throwExceptionInCaller
	 * @return \Generator
	 * @throws FormValidationException|AwaitFormException
	 */
	public static function sendFormAsync(Player $player, string $title, array $options, bool $neverRejects = false, bool $throwExceptionInCaller = false) : \Generator{
		$bridge = new RequestResponseBridge();
		Utils::validateArrayValueType($options, static function(FormOptions $value){
		});

		foreach($options as $option){
			$option->setBridge($bridge);
			RequestResponseBridge::all($option->getOptions());
		}


		$counter = 0;
		$index = [];
		$options = [];
		foreach(yield from $bridge->getAllExpected() as $id => $array){
			$keys = [];
			foreach($array as $key => $item){
				if(is_array($item)){
					[$item, $key] = $item;
				}
				if(!is_scalar($key)||is_object($key)){
					//HACK: Making backtraces useful
					$bridge->reject($id, new \InvalidArgumentException("key must be scalar"));
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
			return $result;
		}catch(AwaitFormException $awaitFormException){
			if(!$neverRejects){
				$bridge->rejectsAll($awaitFormException);
			}
			if($throwExceptionInCaller){
				throw $awaitFormException;
			}
		}
		return [];
	}

	/**
	 * @param Player $player
	 * @param string $title
	 * @param string $content
	 * @param array<MenuOptions> $buttons
	 * @param bool $neverRejects
	 * @return void
	 * @throws FormValidationException|AwaitFormException
	 */
	public static function sendMenu(Player $player, string $title, string $content, array $buttons, bool $neverRejects = false) : void{
		Await::f2c(function() use ($neverRejects, $content, $buttons, $title, $player){
			try{
				yield from self::sendMenuAsync($player, $title, $content, $buttons, $neverRejects, false);
			}catch(FormValidationException|AwaitFormException){
			}
		});
	}

	/**
	 * @param Player $player
	 * @param string $title
	 * @param string $content
	 * @param array<MenuOptions> $buttons
	 * @param bool $neverRejects
	 * @param bool $throwExceptionInCaller
	 * @return \Generator<mixed>
	 * @throws FormValidationException|AwaitFormException
	 */
	public static function sendMenuAsync(Player $player, string $title, string $content, array $buttons, bool $neverRejects = false, bool $throwExceptionInCaller = false) : \Generator{
		$bridge = new RequestResponseBridge();

		// バリデーション：すべて MenuOptions を期待
		Utils::validateArrayValueType($buttons, static function(MenuOptions $value) : void{
		});

		// Bridge注入とオプション構築呼び出し
		foreach($buttons as $option){
			$option->setBridge($bridge);
			RequestResponseBridge::all($option->getOptions());
		}

		// 各 MenuOptions に紐づくボタン群を構築
		$counter = 0;
		$index = []; // id => [start, end, keys]
		$flatButtons = []; // 表示用に flatten された Button[]

		foreach(yield from $bridge->getAllExpected() as $id => $array){
			$keys = [];
			$count = 0;
			foreach($array as $key => $item){
				if(is_array($item)){
					[$item, $key] = $item;
				}
				if(!$item instanceof Button){
					//HACK: Making backtraces useful
					$bridge->reject($id, new \InvalidArgumentException("Button is required"));
				}
				$flatButtons[$counter++] = $item;
				$keys[$count++] = $key;
			}
			$index[$id] = [$counter - count($array), $counter - 1, $keys];
		}

		try{
			// フォーム送信
			$menu = AwaitForm::menu($title, $content, $flatButtons);
			$selected = yield from $menu->request($player); // 0-based indexが返る

			// 選択されたボタンがどの範囲に属するか判定
			foreach($index as $id => [$start, $end, $keys]){
				if($selected >= $start&&$selected <= $end){
					$keyIndex = $selected - $start;
					$key = $keys[$keyIndex] ?? null;

					// 該当オプションへ解決通知
					$bridge->solve($id, $key);
					return $key;
				}
			}
		}catch(AwaitFormException $awaitFormException){
			if(!$neverRejects){
				$bridge->rejectsAll($awaitFormException);
			}
			if($throwExceptionInCaller){
				throw $awaitFormException;
			}
			return null;
		}
		// 該当しなかった場合はフォーム不正とみなす
		throw new FormValidationException("An invalid button selection was made");
	}
}