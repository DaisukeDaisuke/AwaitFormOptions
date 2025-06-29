<?php

namespace DaisukeDaisuke\AwaitFormOptions;

use SOFe\AwaitGenerator\Channel;
use SOFe\AwaitGenerator\Await;

class RequestResponseBridge{

	private int $nextId = 0;

	/**
	 * @var array<int, Channel> 各リクエストごとの入力チャネル
	 */
	private array $pendingRequest = [];

	/**
	 * @var array<int, \Closure> 各リクエストに対する応答チャネル
	 */
	private array $pendingSend = [];
	/**
	 * @var array<\Closure>
	 */
	private array $rejects = [];

	/**
	 * クライアントから値を送り、応答を待つ
	 *
	 * @param mixed $value 要求する値
	 * @return \Generator<mixed> 応答値
	 */
	public function request(mixed $value) : \Generator{
		$id = $this->nextId++;

		$this->pendingRequest[$id] = new Channel();
		$this->pendingRequest[$id]->sendWithoutWait($value);
		return yield from Await::promise(function(\Closure $resolve, \Closure $reject) use ($id){
			$this->pendingSend[$id] = $resolve;
			$this->rejects[$id] = $reject;
		});
	}

	/**
	 * 要求を全て取得する。要求が未完の場合はそれまで待つ
	 * 返すのは普通のジェネレータ
	 *
	 * @return \Generator<int, mixed>
	 */
	public function getAllExpected() : \Generator{
		$result = [];
		foreach($this->pendingRequest as $id => $channel){
			$result[$id] = yield from $channel->receive();
		}
		return $result;
	}

	/**
	 * 特定のリクエストIDに対して応答を送る
	 *
	 * @param int $id 応答先ID
	 * @param mixed $value 応答する値
	 */
	public function solve(int $id, mixed $value) : void{
		if(!isset($this->pendingSend[$id])){
			throw new \RuntimeException("未登録のIDです: $id");
		}
		($this->pendingSend[$id])($value);

		unset($this->pendingRequest[$id], $this->pendingSend[$id]);
	}

	public function rejectsAll(\Throwable $throwable) : void{
		foreach($this->rejects as $reject){
			($reject)($throwable);
		}
	}

	public function reject(int $id, \Throwable $throwable) : void{
		if(isset($this->rejects[$id])){
			($this->rejects[$id])($throwable);
		}
	}

	/**
	 * @param array<\Generator<mixed>> $array
	 * @return void
	 */
	public static function all(array $array) : void{
		Await::f2c(function() use ($array) : \Generator{
			yield from Await::all($array);
		});
	}

	public function count() : int{
		return count($this->pendingRequest);
	}
}
