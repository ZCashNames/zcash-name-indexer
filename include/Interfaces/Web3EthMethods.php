<?php declare(strict_types=1);

namespace Indexer\Interfaces;

/**
 * IDE helper for Web3\Eth dynamic methods.
 *
 * @method void protocolVersion(callable $callback)
 * @method void syncing(callable $callback)
 * @method void coinbase(callable $callback)
 * @method void mining(callable $callback)
 * @method void hashrate(callable $callback)
 * @method void gasPrice(callable $callback)
 * @method void accounts(callable $callback)
 * @method void blockNumber(callable $callback)
 * @method void getBalance(string $address, string $defaultBlock, callable $callback)
 * @method void getStorageAt(string $address, string $position, string $defaultBlock, callable $callback)
 * @method void getTransactionCount(string $address, string $defaultBlock, callable $callback)
 * @method void getBlockTransactionCountByHash(string $blockHash, callable $callback)
 * @method void getBlockTransactionCountByNumber(string|int $defaultBlock, callable $callback)
 * @method void getUncleCountByBlockHash(string $blockHash, callable $callback)
 * @method void getUncleCountByBlockNumber(string|int $defaultBlock, callable $callback)
 * @method void getUncleByBlockHashAndIndex(string $blockHash, string|int $uncleIndex, callable $callback)
 * @method void getUncleByBlockNumberAndIndex(string|int $defaultBlock, string|int $uncleIndex, callable $callback)
 * @method void getCode(string $address, string $defaultBlock, callable $callback)
 * @method void sign(string $address, string $message, callable $callback)
 * @method void sendTransaction(array $transaction, callable $callback)
 * @method void sendRawTransaction(string $data, callable $callback)
 * @method void call(array $transaction, string $defaultBlock, callable $callback)
 * @method void estimateGas(array $transaction, callable $callback)
 * @method void getBlockByHash(string $blockHash, bool $returnTransactionObjects, callable $callback)
 * @method void getBlockByNumber(string|int $defaultBlock, bool $returnTransactionObjects, callable $callback)
 * @method void getTransactionByHash(string $transactionHash, callable $callback)
 * @method void getTransactionByBlockHashAndIndex(string $blockHash, string|int $transactionIndex, callable $callback)
 * @method void getTransactionByBlockNumberAndIndex(string|int $defaultBlock, string|int $transactionIndex, callable $callback)
 * @method void getTransactionReceipt(string $transactionHash, callable $callback)
 * @method void compileSolidity(string $code, callable $callback)
 * @method void compileLLL(string $code, callable $callback)
 * @method void compileSerpent(string $code, callable $callback)
 * @method void getWork(callable $callback)
 * @method void newFilter(array $filterOptions, callable $callback)
 * @method void newBlockFilter(callable $callback)
 * @method void newPendingTransactionFilter(callable $callback)
 * @method void uninstallFilter(string $filterId, callable $callback)
 * @method void getFilterChanges(string $filterId, callable $callback)
 * @method void getFilterLogs(string $filterId, callable $callback)
 * @method void getLogs(array $filterOptions, callable $callback)
 * @method void submitWork(string $nonce, string $powHash, string $mixDigest, callable $callback)
 * @method void submitHashrate(string $hashrate, string $clientId, callable $callback)
 * @method void feeHistory(int|string $blockCount, string $newestBlock, array $rewardPercentiles, callable $callback)
 */
interface Web3EthMethods
{
}
