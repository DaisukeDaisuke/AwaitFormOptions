<?php

namespace DaisukeDaisuke\AwaitFormOptions;

use cosmicpe\awaitform\AwaitForm;
use pocketmine\utils\Utils;
use pocketmine\player\Player;
use SOFe\AwaitGenerator\Await;
use cosmicpe\awaitform\Button;
use pocketmine\form\FormValidationException;

class FormOptionsProcessor{
	final private function __construct(){

	}

	public static function sendForm(Player $player, string $title, array $options) : void{
		Await::g2c(self::senFormAsync($player, $title, $options));
	}

	/**
	 * @param Player $player
	 * @param string $title
	 * @param array<FormOptions> $options await fun
	 * @param RequestResponseBridge $bridge
	 * @return \Generator
	 */
	public static function senFormAsync(Player $player, string $title, array $options) : \Generator{
		$bridge = new RequestResponseBridge();
		Utils::validateArrayValueType($options, static function(FormOptions $value){});

		foreach($options as $option){
			$option->setBridge($bridge);
			RequestResponseBridge::all($option->getOptions());
		}


		$counter = 0;
		$index = [];
		$options = [];
		foreach(yield from $bridge->getAllExpected() as $id => $array){
			$index[$id] = [$counter, count($array)];
			$counter += count($array);
			foreach($array as $item){
				$options[] = $item;
			}
		}

		$menu = AwaitForm::form($title, $options);
		$result = yield from $menu->request($player);

		foreach($index as $id => [$start, $length]){
			$bridge->solve($id, array_slice($result, $start, $length));
		}
		return $result;
	}

	public static function sendMenu(Player $player, string $title, string $content, array $buttons) : void{
		Await::g2c(self::sendMenuAsync($player, $title, $content, $buttons));
	}

	public static function sendMenuAsync(Player $player, string $title, string $content, array $buttons): \Generator {
		$bridge = new RequestResponseBridge();

		// バリデーション：すべて MenuOptions を期待
		Utils::validateArrayValueType($buttons, static function(MenuOptions $value): void {});

		// Bridge注入とオプション構築呼び出し
		foreach ($buttons as $option) {
			$option->setBridge($bridge);
			RequestResponseBridge::all($option->getOptions());
		}

		// 各 MenuOptions に紐づくボタン群を構築
		$counter = 0;
		$index = []; // id => [start, end, keys]
		$flatButtons = []; // 表示用に flatten された Button[]

		foreach (yield from $bridge->getAllExpected() as $id => $array) {
			$keys = [];
			$count = 0;
			foreach ($array as $key => $item) {
				if (is_array($item)) {
					[$item, $key] = $item;
				}
				if (!$item instanceof Button) {
					throw new \InvalidArgumentException("Button is required");
				}
				if (!is_int($key) && !is_string($key)) {
					throw new \InvalidArgumentException("key must be int or string");
				}
				$flatButtons[$counter++] = $item;
				$keys[$count++] = $key;
			}
			$index[$id] = [$counter - count($array), $counter - 1, $keys];
		}

		// フォーム送信
		$menu = AwaitForm::menu($title, $content, $flatButtons);
		$selected = yield from $menu->request($player); // 0-based indexが返る

		// 選択されたボタンがどの範囲に属するか判定
		foreach ($index as $id => [$start, $end, $keys]) {
			if ($selected >= $start && $selected <= $end) {
				$keyIndex = $selected - $start;
				$key = $keys[$keyIndex] ?? null;

				// 該当オプションへ解決通知
				$bridge->solve($id, $key);
				return;
			}
		}

		// 該当しなかった場合はフォーム不正とみなす
		throw new FormValidationException("無効なボタン選択がされました");
	}
}