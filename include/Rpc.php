<?php declare(strict_types=1);

namespace Indexer;

use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use JsonException;

class Rpc
{
    protected array|null $gRpcList;
    protected string $command;

    public const string TX_SCANNER_BIN = __DIR__ . '/../resources/rust_tx_scanner';

    /**
     * The scanner invocation, with the viewing key and the network both supplied.
     *
     * --network is passed explicitly rather than relying on the scanner's default: the
     * network selects the consensus branch id used to parse transactions, and getting it
     * wrong does not fail loudly - it makes every scan return nothing, which is
     * indistinguishable from "no payments arrived". A testnet viewing key against the
     * default mainnet scanner is the exact mismatch this prevents.
     *
     * COIN_NETWORK's values ('mainnet' / 'testnet') are the same vocabulary the scanner's
     * parse_network() accepts, so the constant is passed through unchanged.
     */
    public static function txScannerPath(): string
    {
        return self::TX_SCANNER_BIN .
            ' --ivk ' . escapeshellarg(INCOME_WALLET_VIEW_KEY) .
            ' --network ' . escapeshellarg(COIN_NETWORK);
    }

    /**
     * @param string $gRpcNode Explicit node, e.g. "host:port". Defaults to the configured
     *                         GRPC_NODE; only when that is blank does the public node-list
     *                         service get consulted.
     */
    public function __construct(
        protected string $gRpcNode = ''
    ) {
    }

    /**
     * @return string
     * @throws GuzzleException|RuntimeException|JsonException
     */
    protected function getPublicGRPCNode(): string
    {
        $this->gRpcList = doHttpRequest(
            'https://nodus.alexxiy.top/api/v1.0/nodes',
            [
                'chain' => 'main',
                'count' => 5,
                'order_uptime' => '7d',
                'order_latency' => true,
            ],
            'JSON'
        );
        if ($this->gRpcList === null) {
            throw new RuntimeException('RPC: Can\'t get gRPC node list from list provider');
        }
        $this->gRpcList = $this->gRpcList['nodes'];
        $gRPCRandomNode = $this->gRpcList[array_rand($this->gRpcList)];
        return $gRPCRandomNode['host'] . ':' . $gRPCRandomNode['port'];
    }

    /**
     * @return void
     * @throws GuzzleException|RuntimeException|JsonException
     */
    public function setGRpcNode(): void
    {
        // Select the GRPC node to fetch data from, get the most reliable from Nodus if default node is not configured
        if ($this->gRpcNode === '') {
            $this->gRpcNode = $this->getPublicGRPCNode();
        }
    }

    /**
     * @return string
     * @throws GuzzleException|RuntimeException|JsonException
     */
    public function getGRpcNode(): string
    {
        $this->setGRpcNode();
        return $this->gRpcNode;
    }

    /**
     * @param string $command
     * @return string
     * @throws GuzzleException|RuntimeException|JsonException
     */
    protected function execCommand(string $command): string
    {
        if (!isset($this->command)) {
            $this->setGRpcNode();
            $this->command = self::txScannerPath() . ' --grpc ' . escapeshellarg($this->gRpcNode);
        }

        return self::execRawCommand($this->command . ' ' . $command);
    }

    /**
     * @param string $command
     * @return string
     * @throws RuntimeException
     */
    protected static function execRawCommand(string $command): string
    {
        $exitCode = 0;
        $output = [];
        exec($command . ' 2>&1', $output, $exitCode);

        $outputStr = implode(PHP_EOL, $output);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'RPC: Command: ' . $command . ', Output: ' . $outputStr
            );
        }

        return $outputStr;
    }

    /**
     * @param int $startBlock
     * @param int $blockCount
     * @param bool $all
     * @return array
     * @throws GuzzleException|RuntimeException|JsonException
     */
    public function scanTXRange(int $startBlock, int $blockCount, bool $all = false): array
    {
        return json_decode(
            $this->execCommand('scan ' . $startBlock . ' ' . $blockCount . ($all ? ' 1' : '')),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param string $address
     * @return bool
     * @throws RuntimeException
     */
    public static function validateAddress(string $address): bool
    {
        // --network is REQUIRED here, not optional: `validateaddress` compares the address's
        // own network against it, so without it the scanner falls back to mainnet and a
        // testnet deployment rejects every valid testnet address. The viewing key is not
        // passed - this subcommand does not decrypt anything, it only parses the address.
        //
        // escapeshellarg is MANDATORY here: $address arrives from a decrypted memo, i.e.
        // fully attacker-controlled chain data, and execRawCommand hands the string to a
        // shell. Interpolating it raw would be remote command execution.
        return match ($result = self::execRawCommand(
            self::TX_SCANNER_BIN .
            ' --network ' . escapeshellarg(COIN_NETWORK) .
            ' validateaddress ' . escapeshellarg($address)
        )) {
            'true' => true,
            'false' => false,
            default => throw new RuntimeException('RPC ValidateAddress: ' . $result),
        };
    }
}
