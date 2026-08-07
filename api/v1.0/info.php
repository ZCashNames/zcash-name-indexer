<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\Evm;
use Indexer\RustRocksDBProxy;
use Indexer\SparseMerkleTree;

/**
 * @var array $uri
 * @var Database $db
 */

if (isset($uri[2])) {
    throw new RuntimeException(API_ERROR_WRONG_API_METHOD, INT_EXC_API_ERROR);
}

$extended = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($raw = file_get_contents('php://input')) !== '') {
        $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    }
    $extended = $input['extended'] ?? false;
}

$out = getParams(
    [
        PARAM_LAST_PROCESSED_COIN_BLOCK
    ],
    $db
);

$qr = $db->doSelect(
    'checkpoints',
    '*',
    [],
    'ORDER BY `idx` DESC LIMIT 1',
);
if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows !== 0) {
    $out['last_checkpoint'] = $qr->fetch_assoc();
}

// get latest indexer SMT Root
$rocksDb = new RustRocksDBProxy(INDEXER_ROCKSDB_PROXY_SOCKET_PATH);
$smt = new SparseMerkleTree($rocksDb);
$out['indexer_smt_root'] = $smt->getRootHex();
// Measured against the root the CONTRACT currently attests to, not against the newest
// local checkpoint. Those two go stale together: if discovery stalls, both freeze and
// agree, and the node would report itself synced while falling arbitrarily far behind.
try {
    // Constructed inside the try: the constructor resolves an endpoint from rpc_list and
    // throws when that table holds no row for EVM_NETWORK.chain_id. Outside, that turned a
    // recoverable "chain unreachable" into a failure of the whole endpoint, so a node with
    // a misfiled rpc_list could not even report the state it had computed locally.
    $evm = new Evm(EVM_NETWORK, $db);
    $out['anchored_smt_root'] = $evm->getCurrentRoot();
    $out['indexer_synced'] = $out['indexer_smt_root'] === $out['anchored_smt_root'];
} catch (Throwable) {
    // Unreachable chain is unknown, not synced. Reporting true here would be the same
    // false reassurance this replaced.
    $out['anchored_smt_root'] = null;
    $out['indexer_synced'] = false;
}
$out['checkpoints_behind'] = isset($out['last_checkpoint']['smt_root'])
    && $out['anchored_smt_root'] !== null
    && $out['last_checkpoint']['smt_root'] !== $out['anchored_smt_root'];

$qr = $db->doSelect(
    'domains',
    'COUNT(*) AS `count`',
);
if ($qr === false) {
    throw new RuntimeException(API_ERROR_INTERNAL, INT_EXC_DB);
}
if ($qr->num_rows !== 0) {
    $out['domains_count'] = $qr->fetch_assoc()['count'];
}

if ($extended) {
    $out['registrar_address'] = INCOME_WALLET;
    $out['registrar_viewing_key'] = INCOME_WALLET_VIEW_KEY;
}

apiAnswer('response', $out);
