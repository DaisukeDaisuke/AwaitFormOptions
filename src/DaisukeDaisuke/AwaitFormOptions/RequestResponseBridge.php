<?php

namespace DaisukeDaisuke\AwaitFormOptions;

use SOFe\AwaitGenerator\Channel;
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\AwaitException;

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
	 * @var array<int, array>
	 */
	private array $returns = [];

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
	 * もしrequestが中途半端だった場合デットロックする
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
			throw new \LogicException("未登録のIDです: $id");
		}
		($this->pendingSend[$id])($value);

		unset($this->pendingRequest[$id], $this->pendingSend[$id]);
	}

	public function rejectsAll(\Throwable $throwable) : void{
		foreach($this->rejects as $id => $reject){
			$this->reject($id, $throwable);
		}
	}

	public function reject(int $id, \Throwable $throwable) : void{
		try{
			if(isset($this->rejects[$id])){
				($this->rejects[$id])($throwable);
				unset($this->rejects[$id], $this->pendingSend[$id], $this->pendingRequest[$id]);
			}
		}catch(AwaitException $exception){
			/**
			 * HACK: Workaround to suppress nested AwaitException wrapping
			 * Normally, SOFe\AwaitGenerator wraps thrown exceptions in AwaitException at every yield-from level.
			 * This leads to deep nesting like AwaitException → AwaitException → OriginalException,
			 * which makes debugging more difficult.
			 *
			 * By catching the outer AwaitException and re-throwing only its $previous,
			 * we intentionally skip one layer of wrapping to improve clarity in error messages.
			 *
			 * ⚠ WARNING: This assumes that the caught AwaitException always has a valid ->getPrevious()
			 * and that ignoring one layer of wrapping does not break Await's internal logic.
			 * Use with caution and test thoroughly if changes are made to AwaitGenerator internals.
			 *
			 * @See Await::reject() for reference.
			 */
			throw $exception->getPrevious() ?? $exception;
		}
	}

	/**
	 * @param int $id
	 * @param array<\Generator<mixed>> $array
	 * @param array|null $keys
	 * @return void
	 */
	public function all(int $id, array $array,  ?array $keys = []) : void{
		Await::f2c(function() use ($id, $array, $keys){
			$return = yield from Await::All($array);
			if($keys !== null){
				$this->returns[$id] = array_combine($keys, $return);
			}else{
				$this->returns[$id] = $return;
			}
		});
	}

	/**
	 * @param int $id
	 * @param int $key
	 * @param array<\Generator<mixed>> $array
	 * @return void
	 * @throws \Throwable
	 */
	public function race(int $id, array $array) : void{
		Await::f2c(function() use ($id, $array){
			[$which, $return] = yield from Await::safeRace($array);
			$this->returns[$id + $which] = $return;
		});
	}

	public function count() : int{
		return count($this->pendingRequest);
	}

	public function getReturns() : array{
		return $this->returns;
	}
}
