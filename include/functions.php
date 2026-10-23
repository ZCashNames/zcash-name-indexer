<?php declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Indexer\Database;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;

function logEvent(string $text, string $tag = '', string $file = 'debug'): void
{
    if (DEBUG || str_contains($file, 'debug') === false) {
        file_put_contents(
            WORKLOGS_PATH . '/' . PROJECT_NAME . '-' . $file . '.log',
            date('Y-m-d H:i:s') . ($tag !== '' ? ' - ' . $tag : '') . ': ' . $text . PHP_EOL,
            FILE_APPEND
        );
    }
}

function apiAnswer(string $head, string|array|int $data): void
{
    if ($head === 'error') {
        $data = [
            'error_message' => $data
        ];
    }

    $out = [$head => $data];

    try {
        echo json_encode($out, JSON_THROW_ON_ERROR);
    } catch (Exception) {
        apiAnswer('error', API_ERROR_INTERNAL);
    }

    exit;
}

function getParamCount(mixed $input, int $def = 20, int $max = 100): int
{
    if (($count = (int)($input ?? 0)) < 0) {
        apiAnswer('error', API_ERROR_WRONG_INCOMING_PARAM_VALUE);
    }

    if ($count === 0) {
        $count = $def;
    } elseif ($count > $max) {
        $count = $max;
    }

    return $count;
}

function getParams(array $params, Database $db): array
{
    $qr = $db->doSelect(
        'params',
        '*',
        ['param' => ['sign' => 'IN', 'value' => $params]]
    );
    if ($qr === false) {
        throw new RuntimeException('DB Error: can\'t get parameters from DB.');
    }
    $out = [];
    if ($qr->num_rows !== 0) {
        while ($r = $qr->fetch_assoc()) {
            $out[$r['param']] = $r['value'];
        }
    }
    return $out;
}

function setParams(array $params, Database $db): void
{
    $ins = [];
    foreach ($params as $param => $value) {
        $ins[] = [
            'param' => $param,
            'value' => $value
        ];
    }
    if ($db->doBulkInsert(
        'params',
        $ins,
        false,
        ['value']
    ) === false) {
        throw new RuntimeException('DB Error: can\'t set parameters to DB.');
    }
}

/**
 * Whether the sync is held by a stop (see PARAM_SYNC_STOPPED).
 *
 * The stop no longer holds once EVM_NETWORK['expected_epoch'] reaches the on-chain epoch it was
 * made at: the next run checks the new value against the chain, and stops again if it is still
 * not the on-chain epoch. Until then the epoch, which never goes down, is still past the reviewed
 * one, so holding needs no chain read. With $lift, such a stop is also removed.
 *
 * @param Database $db
 * @param bool $lift
 * @return bool
 * @throws RuntimeException
 */
function syncStopped(Database $db, bool $lift = false): bool
{
    $stop = getParams([PARAM_SYNC_STOPPED], $db)[PARAM_SYNC_STOPPED] ?? null;
    if ($stop === null) {
        return false;
    }
    if ($stop > EVM_NETWORK['expected_epoch']) {
        return true;
    }
    if ($lift && $db->doDelete('params', ['param' => PARAM_SYNC_STOPPED]) === false) {
        throw new RuntimeException('Failed to lift the epoch stop', INT_EXC_DB);
    }

    return false;
}

/**
 * Resets the node for a resync from genesis: empties its tables and its SMT, and seeds the
 * genesis checkpoint. What `indexer.php clean confirm` does, and what the node does by itself
 * after a rollback its operator has reviewed (see the reconcile in indexer.php). Nothing is
 * lost: all of it is derived from the chains again.
 *
 * @param Database $db
 * @param RustRocksDBProxy $rocksDb
 * @return void
 * @throws RuntimeException
 */
function resetIndexerState(Database $db, RustRocksDBProxy $rocksDb): void
{
    foreach (INDEXER_DB_TABLES as $oneTable) {
        if ($db->query('TRUNCATE TABLE `' . $oneTable . '`') === false) {
            throw new RuntimeException('Can\'t clean the table "' . $oneTable . '"', INT_EXC_DB);
        }
    }
    if ($db->doInsert(
        'checkpoints',
        [
            // idx 0 is the contract's genesis root (rootAt[0]) — the empty-registry root
            // it was deployed with. Discovery resumes from the highest idx, so this row is
            // the anchor the whole ascending walk hangs from.
            'idx' => 0,
            'block_id' => COIN_GENESIS_BLOCK,
            'smt_root' => SMT_GENESIS_ROOT,
        ]
    ) === false) {
        throw new RuntimeException('Can\'t insert Genesis Root to checkpoints', INT_EXC_DB);
    }

    setParams([
        PARAM_LAST_PROCESSED_COIN_BLOCK => COIN_GENESIS_BLOCK,
        // Rebuilt from genesis under the epoch configured now: see PARAM_STATE_EPOCH.
        PARAM_STATE_EPOCH => EVM_NETWORK['expected_epoch'],
    ], $db);

    if ($rocksDb->clear() === false) {
        throw new RuntimeException('Can\'t clean RocksDB database', INT_EXC_DB);
    }
}

/**
 * @param string $type
 * @param Database $db
 * @return array
 * @throws RuntimeException
 */
// Do not forget to grant required privileges "GRANT XA_RECOVER_ADMIN on *.* to 'user'@'%';"
function getXATXList(string $type, Database $db): array
{
    if (($qr = $db->query('XA RECOVER')) === false) {
        throw new RuntimeException('Can\'t query XA RECOVER', INT_EXC_DB);
    }

    $type = PROJECT_NAME . '-' . $type;
    $result = [];

    while ($r = $qr->fetch_assoc()) {
        $txName = substr($r['data'], 0, $r['gtrid_length']);
        $txType = substr($r['data'], $r['gtrid_length'], $r['bqual_length']);
        if ($txType === $type || str_starts_with($txType, $type . '_')) {
            $result[] = [$txName, $txType];
        }
    }

    return $result;
}

/**
 * @param string $type
 * @param Database $db
 * @param SparseMerkleTree $smt
 * @param string $logfileName
 * @return bool
 * @throws Indexer\RustRocksDBProxyException
 * @throws RuntimeException
 */
function checkUncommitedXATX(string $type, Database $db, SparseMerkleTree $smt, string $logfileName): bool
{
    if (($xaList = getXATXList($type, $db)) !== []) {
        if (count($xaList) === 1) {
            logEvent(
                'Found uncommited TX (' . implode(',', $xaList[0]) . '), checking...',
                'DB Consistency',
                $logfileName
            );

            $xaOldRoot = $xaList[0][0];
            $xaCurrentRoot = $smt->getRootHex();
            $txCommand = $xaOldRoot === $xaCurrentRoot ? 'ROLLBACK' : 'COMMIT';
            if ($db->query('XA ' . $txCommand . ' \'' . implode('\',\'', $xaList[0]) . '\'', retries: 0) === false) {
                throw new RuntimeException('Can\'t commit XA transaction (consistency check)', INT_EXC_DB);
            }

            logEvent(
                'New data from last uncommited TX has been ' . ($txCommand === 'COMMIT' ? ' commited' : 'reverted'),
                'DB Consistency',
                $logfileName
            );

            return $txCommand === 'COMMIT';
        }

        throw new RuntimeException(
            'PANIC! Should never happen! There more than one uncommited XA transactions: ' .
            formatXATXList($xaList),
            INT_EXC_FATAL
        );
    }
    return false;
}

function formatXATXList(array $xaTXes): string
{
    $parts = [];
    foreach ($xaTXes as $index => $subArray) {
        $formattedValues = "'" . implode("', '", $subArray) . "'";
        $parts[] = "$index: $formattedValues";
    }

    return implode(' / ', $parts);
}

/**
 * @param string $url
 * @param array $data
 * @param string $method
 * @param array $headers
 * @return array|null
 * @throws GuzzleException | JsonException
 */
function doHttpRequest(string $url, array $data, string $method = 'POST', array $headers = []): ?array
{
    $client = new Client(
        [
            'timeout' => 15,
            'connect_timeout' => 15,
            'verify' => RELEASE_TYPE !== 'local'
        ]
    );

    if ($method === 'GET') {
        $reqParam = 'query';
    } elseif ($method === 'POST') {
        $reqParam = 'form_params';
    } elseif ($method === 'JSON') {
        $reqParam = 'json';
        $method = 'POST';
        $headers[] = 'Content-type: application/json';
    } else {
        throw new RuntimeException('Unsupported HTTP method: ' . $method);
    }

    $request = [
        $reqParam => $data,
        'version' => '2.0'
    ];
    if ($headers !== []) {
        $request['headers'] = $headers;
    }

    try {
        $res = $client->request(
            $method,
            $url,
            $request
        );

        if ($res->getStatusCode() === 200) {
            $result = json_decode($res->getBody()->getContents(), true, 8, JSON_THROW_ON_ERROR);
            if (is_array($result)) {
                return $result;
            }
        }
    } catch (JsonException $e) {
        throw $e;
    } catch (GuzzleException | Exception $e) {
        if (method_exists($e, 'getResponse') && ($response = $e->getResponse()) &&
            is_object($response) && method_exists($response, 'getBody')) {
            $contents = $response->getBody()->getContents();
            try {
                $contents = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($contents) && $contents !== []) {
                    return $contents;
                }
            } catch (JsonException) {
                return null;
            }
        } else {
            throw $e;
        }
    }

    return null;
}
