<?php

declare(strict_types=1);

namespace DaisukeDaisuke\AwaitFormOptions;

use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\AwaitException;
use SOFe\AwaitGenerator\Channel;
use function array_combine;
use function count;
use function krsort;

class RequestResponseBridge{

	private int $nextId = 0;

	private int $reservesId = 0;

	/** @var array<int, Channel> 各リクエストごとの入力チャネル */
	private array $pendingRequest = [];

	/** @var array<int, \Closure> 各リクエストに対する応答チャネル */
	private array $pendingSend = [];
	/** @var array<\Closure> */
	private array $rejects = [];

	/** @var array<int, array> */
	private array $returns = [];
	/** @var array<int, list<Channel>> */
	private array $finalizeList = [];
	/** @var list<Channel> */
	private array $reserves = [];

	/**
	 * クライアントから値を送り、応答を待つ
	 *
	 * @param mixed $value    要求する値
	 * @param ?int  $reserved 予約id
	 * @return \Generator<mixed> 応答値
	 */
	public function request(mixed $value, int $reserved = null) : \Generator{
		$id = $this->nextId++;

		$this->pendingRequest[$id] = new Channel();
		$this->pendingRequest[$id]->sendWithoutWait($value);

		return yield from Await::promise(function(\Closure $resolve, \Closure $reject) use ($reserved, $id){
			$this->pendingSend[$id] = $resolve;
			$this->rejects[$id] = $reject;

			if($reserved !== null && isset($this->reserves[$reserved])){
				$this->reserves[$reserved]->sendWithoutWait($id);
				unset($this->reserves[$reserved]);
			}
		});
	}

	/**
	 * 将来のrequestを予約する。
	 */
	public function schedule() : int{
		$id = $this->reservesId++;
		$this->reserves[$id] = new Channel();
		return $id;
	}

	/**
	 * 要求を全て取得する。要求が未完の場合はそれまで待つ
	 * もしrequestが中途半端だった場合デットロックする
	 *
	 * @return \Generator<int, mixed>
	 */
	public function getAllExpected() : \Generator{
		$result = [];

		//予約を待つ
		foreach($this->reserves as $reserve){
			yield from $reserve->receive();
		}

		foreach($this->pendingRequest as $id => $channel){
			$result[$id] = yield from $channel->receive();
		}
		return $result;
	}

	/**
	 * 特定のリクエストIDに対して応答を送る
	 *
	 * @param int   $id    応答先ID
	 * @param mixed $value 応答する値
	 */
	public function solve(int $id, mixed $value) : void{
		if(!isset($this->pendingSend[$id])){
			throw new \LogicException("未登録のIDです: $id");
		}
		($this->pendingSend[$id])($value);

		unset($this->pendingRequest[$id], $this->pendingSend[$id]);
	}

	/**
	 * Calls reject() on all managed generators with the given exception.
	 *
	 * ⚠ If any rejection results in an uncaught exception, the loop will terminate immediately.
	 * This can leave remaining generators in an unrejected state, causing memory leaks due to
	 * uncollected resources in still-pending generators.
	 *
	 * This behavior is by design in fatal environments, where an uncaught exception from a child
	 * generator is considered a crash condition and should propagate immediately. However, in
	 * non-fatal environments, such early termination can prevent proper cleanup.
	 *
	 * To ensure all generators are properly cleaned up regardless of crash behavior,
	 * use abortAll() instead.
	 *
	 * @param \Throwable $throwable The exception to pass to each reject handler.
	 * @throws \Throwable
	 */
	public function rejectsAll(\Throwable $throwable) : void{
		foreach($this->rejects as $id => $reject){
			$this->reject($id, $throwable);
		}
	}

	/**
	 * Safely aborts all managed generators by sending them an AwaitFormOptionsAbortException.
	 *
	 * Unlike rejectsAll(), this method guarantees that all reject() calls are attempted,
	 * even if some of them throw exceptions. This ensures proper cleanup of all pending
	 * generators, avoiding memory leaks or stuck resources.
	 *
	 * This is intended for controlled shutdowns where safe cleanup is preferred over crash behavior.
	 *
	 * @throws \Throwable
	 */
	public function abortAll() : void{
		$counter = 0;
		$cont = true;
		do{
			try{
				$cont = $this->reject($counter++, new AwaitFormOptionsAbortException());
			}catch(AwaitFormOptionsAbortException){
				continue;
			}
		}while($cont);
	}


	/**
	 * Rejects a request with a specified identifier by invoking the associated rejection handler
	 * with the provided throwable. Removes the associated handlers upon rejection.
	 *
	 * This method attempts to reject the child generator corresponding to the specified ID
	 * by throwing an exception within its coroutine. If the child generator catches (i.e., swallows)
	 * the exception using a try-catch block, the exception does not leak to the parent coroutine.
	 * However, if the exception is not caught within the child generator, it will propagate (leak)
	 * to the parent coroutine (generator).
	 *
	 * @param int $id The unique identifier for the request to reject.
	 * @param \Throwable $throwable The throwable used to reject the request.
	 * @return bool Returns true if a rejection handler was found and successfully invoked; otherwise, returns false.
	 *
	 * @throws \Throwable Re-throws the previous exception or the caught exception if no previous exists,
	 *                    in cases of nested AwaitException wrapping.
	 */
	public function reject(int $id, \Throwable $throwable) : bool{
		try{
			if(isset($this->rejects[$id])){
				($this->rejects[$id])($throwable);
				unset($this->rejects[$id], $this->pendingSend[$id], $this->pendingRequest[$id]);
				return true;
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
		return false;
	}

	/**
	 * Processes an array of asynchronous tasks and stores the results.
	 *
	 * This method initiates an asynchronous coroutine using `Await::f2c()` to handle the provided
	 * array of generator tasks concurrently. It uses `Await::All()` to await all tasks in parallel.
	 * When an optional array of keys is provided, the returned results are combined with these keys
	 * using `array_combine()`. Otherwise, the raw result array is stored.
	 *
	 * The final results are then stored in the `returns` property, indexed by the specified unique
	 * identifier ($id) and owner identifier ($owenr).
	 *
	 * @param int             $id    Unique identifier for storing the results.
	 * @param int|string      $owenr Identifier for the owner of the result.
	 * @param array<\Generator<mixed>> $array An array of tasks (generators) to be processed asynchronously.
	 * @param array|null      $keys  Optional array of keys for mapping the results.
	 *
	 * @return void
	 */
	public function all(int $id, int|string $owenr, array $array, ?array $keys = []) : void{
		Await::f2c(function() use ($owenr, $id, $array, $keys){
			$return = yield from Await::All($array);
			if($keys !== null){
				$this->returns[$id][$owenr] = array_combine($keys, $return);
			}else{
				$this->returns[$id][$owenr] = $return;
			}
		});
	}

	/**
	 * Executes the given array of child generators concurrently in a race,
	 * expecting exactly one generator to complete while the others receive a RaceLostException.
	 *
	 * The result of the completed generator is stored using a key that combines the provided base identifier ($id)
	 * with the index of the generator that finished first.
	 *
	 * @param int $id The base identifier for storing the result.
	 * @param array<\Generator<mixed>> $array An array of child generators to execute in a race.
	 * @throws \Throwable If an exception occurs within any child generator or during the execution of Await::safeRace.
	 *
	 * @see solve Refer to the `solve` method for additional context on related functionality.
	 */
	public function race(int $id, array $array) : void{
		Await::f2c(function() use ($id, $array){
			[$which, $return] = yield from Await::safeRace($array);
			$this->returns[$id + $which] = $return;
		});
	}

	/**
	 * Executes the given child generator once and stores its result using the specified key.
	 *
	 * This method wraps the execution of a single generator within an asynchronous coroutine.
	 * The generator is run to completion and its returned value is then stored in the results storage,
	 * indexed by the provided unique identifier ($id) and owner key ($owenr).
	 *
	 * @param int $id Unique identifier for storing the result.
	 * @param int|string $owenr The key used to store the generator's return value.
	 * @param \Generator<mixed> $generator The child generator to execute.
	 *
	 * @return void
	 */
	public function one(int $id, int|string $owenr, \Generator $generator) : void{
		Await::f2c(function() use ($owenr, $id, $generator){
			$return = yield from $generator;
			$this->returns[$id][$owenr] = $return;
		});
	}

	public function count() : int{
		return count($this->pendingRequest);
	}

	public function getReturns() : array{
		return $this->returns;
	}

	/**
	 * Registers a finalization request with a specific priority.
	 *
	 * This method schedules finalization within an asynchronous coroutine by
	 * creating a new Channel instance and appending it to the internal finalization list.
	 * The coroutine is then blocked by yielding from the channel's receive method until
	 * a signal is sent to complete the finalization.
	 *
	 * @param int $priority Priority level for the finalization process (default is 0).
	 *                      Higher numbers indicate higher processing priority.
	 *
	 * @return \Generator<mixed> Yields until the finalization process is completed.
	 */
	public function finalize(int $priority = 0) : \Generator{
		$obj = new Channel();
		$this->finalizeList[$priority][] = $obj;
		yield from $obj->receive();
	}

	/**
	 * Resolves all scheduled finalization reservations and unblocks their associated child generators.
	 *
	 * This method processes all finalization channels in descending order based on their priority
	 * (i.e., channels with higher priority values are processed first). For each scheduled finalization,
	 * it sends a null value using non-blocking send to unblock any awaiting generator.
	 * If no finalization reservations have been scheduled, the method performs no actions.
	 *
	 * @return void
	 */
	public function tryFinalize() : void{
		krsort($this->finalizeList); // 高い優先度（数値が大きい）順に処理

		foreach($this->finalizeList as $group){
			foreach($group as $item){
				$item->sendWithoutWait(null);
			}
		}
	}
}
