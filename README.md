# ZCash Name Indexer

The **ZCash Name Indexer** is a core back-end component of the **ZCash Name Service (zNS)** ecosystem. It reconstructs the entire domain-name state by scanning shielded ZCash transactions, maintains that state in a Sparse Merkle Tree, and cross-checks its own tree against the roots anchored on an EVM contract.

Anyone can run it. That is the point: zNS is secured by *public, deterministic replication*, and this daemon is the thing that replicates.

---

## 📖 Documentation
Detailed installation guides, architecture overview, and API references are available at the official documentation portal:
* **[docs.zcashnames.co](https://docs.zcashnames.co)**

---

## 🔐 Trust Model — read this first

zNS is **not** secured by its ZK proof alone, and it is **not** a trusted-sequencer system.

**How commands reach the protocol.** A user sends a shielded ZCash note to the registrar's protocol address. The **amount is the payment** and the **ZIP-302 memo is the signed command** — both in the same note, so intent and payment are atomically bound with no separate reference needed. Block height, index within the block, and input index give every command a deterministic total order.

**Why anyone can check the registrar.** The registrar publishes the protocol address *and its viewing key* — this indexer serves both from `/v1.0/info` (see below). Any third party can therefore:

1. scan the chain with that key and recover every command and payment from genesis, in canonical order;
2. replay them through this open-source indexer, which implements the same protocol;
3. compute the SMT root independently;
4. compare it against the root anchored on-chain.

A registrar that censors a paid command, invents a transfer, mis-credits, or reorders produces a root that **every** independent indexer contradicts. Deviation is publicly detectable by anyone, without permission or special access.

**What the ZK proof is actually for.** It lets the EVM anchor contract accept a state root without scanning ZCash. It proves the transition rules were followed given the supplied inputs. It does **not** prove the inputs were the real chain command stream — replication does that job, and does it better, because an indexer sees the decrypted amount and memo while a circuit would only see a note commitment.

**Property: fraud-evident, not fraud-preventing.** A bad batch can land on-chain and be contradicted afterwards; recovery is social, not automatic.

---

## 🚀 Key Features

* **Dual-State Synchronizer**: Parses ZCash transactions alongside the checkpoints read from the anchor contract's own state, and refuses to advance when the two disagree. Checkpoints come from `eth_call` against the contract's `tipIndex` and `rootAt`, never from event logs — public RPC endpoints refuse `eth_getLogs` outright, and a log stream would still report a rolled-back root as current.
* **Compact Sparse Merkle Tree**: A 128-level keyspace in which each leaf floats up to the shallowest depth where its key prefix is unique — so a tree holding *n* domains stores roughly *n* nodes, not 128 per key. Backed by RocksDB through the [`rr-proxy`](https://github.com/ZCashNames/zcash-name-rr-proxy) daemon.
* **Database Consistency**: MySQL two-phase commit (XA) with automatic crash recovery, so MySQL and RocksDB cannot drift apart after a failed commit.
* **Domain Resolution APIs**: Forward resolution, reverse resolution, owner lookup, checkpoint registry, per-domain history, and Merkle proofs for any of them.
* **Self-Verification**: `/v1.0/info` reports `indexer_synced` — whether this node's own computed root matches the last root anchored on-chain.

---

## 🌳 The Compact Sparse Merkle Tree

Two properties surprise people coming from a dense SMT, and both are deliberate:

* **An empty subtree is 32 zero bytes at every height.** There is no ladder of precomputed empty-node hashes. Consequently **the root of an empty tree is all zeros** — a freshly started `rr-proxy` reporting `0000…0000` from `/v1.0/getRoot` is correct, not broken.
* **Domain-separation tags are load-bearing.** Leaves are `SHA256(0x00 ‖ …)` and internal nodes `SHA256(0x01 ‖ L ‖ R)`. In a dense tree these were defence in depth; in the compact tree, where a leaf can appear at any depth, they are what prevents leaf/node confusion.

A proof therefore carries a `depth`, the sibling list, and a `terminal` describing what was found at the end of the path: `Occupied` (the key is present), `Vacant` (the subtree is empty), or `Blocked` (a *different* key already occupies that slot — the proof then includes that key's full record, which is required for soundness).

The full specification lives in the prover repository at `docs/compact-smt.md`, together with an independent Python implementation used to generate golden vectors. This indexer, the `rr-proxy` daemon, and the SP1 circuit must all agree byte-for-byte with it.

---

## 📡 Protocol Summary

Commands are ZIP-302 memos of the form:

```
zNS:1:<OP>:<domain>:<args…>:<nonce>:<ed25519-signature>
```

**One command per transaction.** It is carried by the *first* note this viewing key decrypts
whose memo is a well-formed command — its format and, for every OP but `CHG`, its signature.
The notes after it are never looked at, even when that command is then refused or underpaid.
The registrar decides at ingest, before it knows the domain state the command will meet, so
this is the only rule it and every indexer can apply alike.

| OP | Meaning | Signed by |
|:---|:---|:---|
| `REG` | Register a new domain | registrant |
| `UPD` | Update the target address | current owner |
| `CHG` | Transfer the domain to a new owning pubkey | current owner |
| `LST` | List on the marketplace at a price, or change the price of a listing | owner — this signature *is* the standing offer to sell |
| `ULT` | Delist: withdraw a listing, resetting the price to zero | owner |
| `BUY` | Purchase a listed domain | buyer — the seller's `LST` supplies the other half of the agreement |

There is no separate seller signature on a sale: `LST` pins the price into the tree, and the circuit binds a `BUY` to it by asserting the listed price is non-zero and unchanged. Symmetrically, `ULT` is only valid on a domain that *is* listed.

Supported zones: `.zcash`, `.zec`, `.private`, `.secure`, `.safe`.

Prices are length-tiered (1-character labels cost the most) and defined as protocol constants in `include/Protocol.php`; the registrar serves them from its `/v1.0/prices` endpoint.

---

## 🌐 HTTP API

All endpoints live under `/v1.0/` and answer `{"response": …}` or `{"error":{"error_message": …}}`. `GET` returns defaults; `POST` with a JSON body selects options.

### `GET /v1.0/info`
Node status. `POST {"extended": true}` additionally returns the registrar address and viewing key — what anyone needs to replicate independently.

```json
{"response":{
  "last_processed_coin_block": 3422048,
  "last_checkpoint": {"idx": 42, "block_id": 3422048, "smt_root": "b4c9dacf…"},
  "indexer_smt_root": "b4c9dacf…",
  "anchored_smt_root": "b4c9dacf…",
  "indexer_synced": true,
  "checkpoints_behind": false,
  "anchored_epoch": 1,
  "sync_stopped": false,
  "domains_count": 0,
  "registrar_address": "u146xylzx0…",
  "registrar_viewing_key": "uview…"
}}
```

`indexer_synced` is the value to watch: `false` means this node's computed root does not match the last anchored checkpoint — either it is still catching up, or it disagrees with the registrar. `sync_stopped` is `true` while the node waits for its operator (see [Epoch changes and rollbacks](#epoch-changes-and-rollbacks) below); it keeps serving the state it has, but no longer follows the chain.

### `GET /v1.0/getRoot`
The current SMT root as hex. All zeros means the tree is empty.

### `GET /v1.0/resolve/{domain}`
Forward resolution, with the Merkle proof for the answer.

* `GET /v1.0/resolve/reverse/{address}` — every domain pointing at an address.
* `GET /v1.0/resolve/owner/{pubkey}` — every domain held by an Ed25519 pubkey.
* `POST {"extended": true}` adds creation/update block IDs and the originating transaction.
* `POST {"with_checkpoint": true}` adds `checkpoint`, the anchored checkpoint whose root the proof was built against, so a client can verify the proof against a value that exists on-chain. It is `null` while a sync is being committed. `smt_root` is in every answer either way.

Responses carry `merkle_proof`, `proof_depth`, and `proof_terminal`, which are exactly what a client needs to verify inclusion without trusting this server.

### `GET /v1.0/checkpoints`
Anchored checkpoints, newest first. `POST` accepts `order`, `count`, `from_block`.

### `GET /v1.0/history/{domain}`
Every recorded state change for a domain. `POST` accepts `order`, `count`, `from_block_id`.

Sample requests for every endpoint are kept as `.http` files in [`api/dev/`](api/dev/).

---

## 🛠️ System Requirements

* **PHP**: 8.5 (pinned in the Docker image and used in production) with `mysqli`, `sodium`, `bcmath` and `ctype`.
  `sodium` is compiled into the PHP 8.5 binary on Debian/Sury, so there is no separate `php8.5-sodium` package to install.
* **Database**: **MySQL 8.0+** (8.4 LTS in the Docker image), with the `XA_RECOVER_ADMIN` privilege — the indexer inspects and resolves prepared XA transactions on startup.
  **MariaDB will not work.** The schema uses the `utf8mb4_0900_ai_ci` collation, which MariaDB does not implement, and `XA_RECOVER_ADMIN` is a MySQL privilege with no MariaDB equivalent. This is a hard incompatibility, not a preference.
* **Key-Value Store**: RocksDB accessed via the [**rr-proxy**](https://github.com/ZCashNames/zcash-name-rr-proxy) socket daemon, which owns the compact SMT implementation.
* **Transaction scanner**: `resources/rust_tx_scanner`, which decrypts shielded notes with the configured viewing key.
  It is a build artifact of [rust_tx_scanner](https://github.com/ZCashNames/rust_tx_scanner) and is **not** in this repository — the Docker image fetches the pinned release during the build. For a manual install, download it from that project's releases into `resources/` and `chmod +x` it.
* **Nodes**:
  * Light wallet node: Lightwalletd / Zainod (gRPC).
  * EVM RPC node for the chain the anchor contract is deployed on (any Ethereum-compatible JSON-RPC).

---

## ⚙️ Configuration

Runtime configuration lives in `config/<RELEASE_TYPE>.inc.php`; start from [`config/release.inc.php.sample`](config/release.inc.php.sample). Values that matter most:

| Constant | Notes |
|:---|:---|
| `DB_CONFIG` | MySQL connection. |
| `GRPC_NODE` | `host:port` of the lightwalletd/Zainod node. Leave empty to pick a public node from the list service. |
| `EVM_NETWORK` | Chain ID, anchor contract address and ABI path. |
| `INDEXER_ROCKSDB_PROXY_SOCKET_PATH` | Unix socket of the `rr-proxy` daemon. |
| `SMT_GENESIS_ROOT` | **Must equal the genesis root the anchor contract was deployed with.** For a chain anchored from the registrar wallet's birthday this is all zeros — the empty tree. |
| `COIN_GENESIS_BLOCK` | The wallet birthday: the block before which no service transaction can exist. |
| `EVM_THROTTLING` | Microseconds to sleep after each `eth_call`. `0` suits most endpoints; raise it only if one starts answering *limit exceeded*. |
| `EVM_BATCH_SIZE` | Checkpoints fetched and persisted per database transaction, bounding how much an interrupted sync loses. |
| `INCOME_WALLET` / `INCOME_WALLET_VIEW_KEY` | The published protocol address and its viewing key. |
| `NOTIFY_*` | Telegram alerting for fatal conditions. |

> ⚠️ The viewing key and the scanner must target the same network. A testnet key with the scanner defaulting to mainnet fails with *“Viewing key is for network Test but scanner is running with --network Main”*.

Database schema: [`resources/schema.sql`](resources/schema.sql) (tables `checkpoints`, `domains`, `domains_history`, `marketplace`, `params`, `rpc_list`).

At least one row in `rpc_list` for the configured chain is required; the indexer refuses to start without one.

Give each row a **distinct `issue_ts`** (0, 1, 2, …). The indexer selects the endpoint with the lowest value and has no tiebreaker, so equal values leave the choice to the database and make endpoint-specific problems unreproducible. A failing endpoint is stamped with the current unix time, which sorts far behind these seeds — so healthy endpoints are always preferred, and once every endpoint has failed at some point they are tried least-recently-failed first. The Docker entrypoint seeds them this way automatically.

---

## 🖥️ Command Line

```bash
php indexer.php sync       # one synchronization iteration (what the timer runs)
php indexer.php watchdog   # read-only stalled-lock check (what the watchdog timer runs)
php indexer.php clean confirm   # DESTRUCTIVE: truncate all tables, clear RocksDB, reseed genesis
```

`clean` resets the node for a from-scratch resynchronization and requires the literal word `confirm`.

### Epoch changes and rollbacks

The anchor contract's epoch moves on every rules change and every rollback. A node configured for an older one (`EVM_NETWORK['expected_epoch']`) stops following the chain instead of failing — and alerting — on every run: it sends one alert, `/v1.0/info` reports `sync_stopped: true`, and it keeps serving the state it has. Review what changed (the contract's `RulesChanged` and `RootRolledBack` events), then deploy the reviewed `expected_epoch` — a new release, or `EXPECTED_EPOCH` in Docker. The next run resumes by itself:

* **After a rules change** the node simply carries on.
* **After a rollback** the next run finds checkpoints it synced that the contract no longer attests to. The chain confirmed its state under the older epoch, so this is the rollback that was reviewed: the node empties its tables and its SMT, sends one alert, and resyncs from genesis — what `php indexer.php clean confirm` does by hand. Nothing is lost; it all comes from the chains again.

A node updated before the change it was made for comes into force finds the chain at an older epoch than its own. That change is still to come, so the node waits for it instead of stopping: it sends one alert, takes no new checkpoints (so `indexer_synced` turns `false` once the chain anchors a newer one), and carries on by itself once the chain reaches its epoch.

A checkpoint mismatch under the same epoch the chain confirmed the node's state in cannot be a rollback, since a rollback moves the epoch. It is taken for a bad read and retried from another RPC endpoint. The stop, the wait, and the epoch the state was confirmed under are kept in the `params` table, so they outlive a restart or a recreated container.

---

## ⏫ Upgrading from 1.0.x

This release follows the redeployed anchor contract (`EVM_NETWORK` in [`config/release.inc.php.sample`](config/release.inc.php.sample)), and records block times in two tables: `created_ts` and `updated_ts` in `domains`, `domain_ts` in `domains_history`. A 1.0.x database has none of them, and the times of the names it already holds can only come from replaying the chain, so an upgraded node **adds the columns and resyncs from genesis**. Nothing is lost: it all comes from the chains again.

Until it does, the first sync that records a name fails with *Unknown column 'created_ts'*, and so does every one after it. A node left on 1.0.x is no better off: it follows the old contract, which no longer moves, and reports itself synced while the registry has moved on.

### Docker

The image creates its schema only on its first start, on an empty volume, so a container upgraded in place keeps the 1.0.x tables. The update cron in [Keeping the image up to date](#-keeping-the-image-up-to-date) pulls this release by itself, so a node on it needs one of these steps too.

* **Start over** — the simplest. It removes the volumes (database, SMT and logs) and builds them again from genesis. `rpc_list` is seeded from `EVM_RPC_URL` again, so endpoints added to the table by hand must be added again, and a generated `DB_PASSWORD` is generated anew:
  ```bash
  docker compose down -v
  docker compose pull
  docker compose up -d
  ```
* **Keep the volumes** — update the image, then add the columns and reset the node in one command:
  ```bash
  docker compose pull
  docker compose up -d
  docker exec zcash-name-indexer flock -n /opt/zcash-name-indexer/resources/sync.lock sh -c 'mysql --protocol=socket -u root "$DB_NAME" -e "ALTER TABLE domains ADD created_ts int unsigned NOT NULL AFTER updated_block_id, ADD updated_ts int unsigned NOT NULL AFTER created_ts; ALTER TABLE domains_history ADD domain_ts int unsigned NOT NULL AFTER domain_block_id" && /usr/local/bin/indexer clean confirm'
  ```
  It ends with *The database has been successfully cleaned.* `flock` holds the indexer's own sync lock meanwhile, so the cron sync cannot run between the two steps. If a sync holds the lock at that moment, the command exits with status 1 and does nothing — run it again.

### From source (systemd or cron)

Stop the sync (with cron, comment its line out), update the code and the configuration (`EVM_NETWORK` as in the sample), add the columns, reset the node — it ends with *The database has been successfully cleaned.* — and start the sync again:

```bash
sudo systemctl stop zcash-name-indexer.timer
mysql -u <user> -p <database> -e "ALTER TABLE domains ADD created_ts int unsigned NOT NULL AFTER updated_block_id, ADD updated_ts int unsigned NOT NULL AFTER created_ts; ALTER TABLE domains_history ADD domain_ts int unsigned NOT NULL AFTER domain_block_id"
php indexer.php clean confirm
sudo systemctl start zcash-name-indexer.timer
```

Do not import `resources/schema.sql` again instead: it drops and recreates every table, `rpc_list` included, and with it anything else that shares the database.

---

## 🐳 Docker Deployment

The indexer can be deployed easily as a Docker container. There are two ways to spin up the container: **from the GitHub Container Registry (GHCR) image** (recommended for production) or **built from local source files**.

A single self-contained image: MySQL 8.4, the `rr-proxy` SMT daemon, PHP-FPM 8.5, nginx
and cron. That is deliberately not one-process-per-container — the value of an independent
indexer comes from how many people actually run one, and a single `docker compose up`
reaches far more of them than a multi-service topology would.

The usual objection is handled rather than waved away: the entrypoint supervises every
service and **stops the container if any one of them dies**, so it never sits "up" with a
dead indexer inside. Combined with `restart: unless-stopped`, a crashed service becomes a
restart rather than a silent outage.

| Component | Version |
|:---|:---|
| Base | Debian 13 (trixie) slim |
| MySQL | 8.4 LTS |
| PHP | 8.5 (FPM + CLI) |
| nginx | 1.30 stable (pinned; Debian trixie ships 1.26) |
| Scheduler | cron (no init system in the image) |
| Logs | logrotate, covering indexer, rr-proxy, nginx and MySQL |

State lives in three named volumes — `mysql_data`, `rr_proxy_db`, `indexer_logs` — so
replacing the container keeps the synced chain state.

### Prerequisites
Make sure you have [Docker](https://docs.docker.com/get-docker/) and [Docker Compose](https://docs.docker.com/compose/install/) installed on your server.

---

### Method A: Spin up from GitHub Docker Image (Recommended)

To run the indexer using the official pre-built image from GitHub Packages without cloning the full repository:

1. Download the `docker-compose.yml` and `env.sample` files from the repository's `resources/docker/` directory:
   ```bash
   curl -L -o docker-compose.yml https://raw.githubusercontent.com/ZCashNames/zcash-name-indexer/main/resources/docker/docker-compose.yml
   curl -L -o env.sample https://raw.githubusercontent.com/ZCashNames/zcash-name-indexer/main/resources/docker/env.sample
   ```
2. Copy `env.sample` to `.env`:
   ```bash
   cp env.sample .env
   ```
3. Open `.env` and fill in your gRPC and EVM RPC settings:
   ```bash
   nano .env
   ```
4. Start the container in detached mode:
   ```bash
   docker compose up -d
   ```

---

### Method B: Spin up from Local Docker Files

To build the Docker image yourself from the source code:

1. Clone this repository and navigate to the root directory:
   ```bash
   git clone https://github.com/ZCashNames/zcash-name-indexer.git
   cd zcash-name-indexer
   ```
2. Build the Docker image locally:
   ```bash
   docker build -t ghcr.io/zcashnames/zcash-name-indexer:latest -f resources/docker/Dockerfile .
   ```
3. Navigate to the `resources/docker` directory:
   ```bash
   cd resources/docker
   ```
4. Copy `env.sample` to `.env` and configure your settings:
   ```bash
   cp env.sample .env
   nano .env
   ```
5. Start the container:
   ```bash
   docker compose up -d
   ```

---

### 🕹️ Start, Stop, and Management Commands

Use these standard commands from the directory containing your `docker-compose.yml` file:

* **Stop the container**:
  ```bash
  docker compose stop
  ```
* **Start the stopped container**:
  ```bash
  docker compose start
  ```
* **Stop and remove container + network resources**:
  ```bash
  docker compose down
  ```
* **Stop and remove container, network, and ALL persistent volumes (destructive)**:
  ```bash
  docker compose down -v
  ```
* **View container logs**:
  ```bash
  docker compose logs -f
  ```
* **Query indexer status / verify API (from the host machine)**:
  ```bash
  curl -i http://localhost:8080/v1.0/info
  ```
* **Rebuild the node from genesis by hand** (destructive; after a reviewed rollback the node does this by itself — see [Epoch changes and rollbacks](#epoch-changes-and-rollbacks)):
  ```bash
  docker exec zcash-name-indexer indexer clean confirm
  ```

---

### 🔁 Keeping the image up to date

Each release carries the anchor epoch its publisher has reviewed. When the epoch moves — a rules change or a rollback — a node stops following the chain until it runs a release made for the new one, and then carries on by itself (see [Epoch changes and rollbacks](#epoch-changes-and-rollbacks)). Docker does not pull a new `latest` on its own, so without an update step a node stays stopped. A host crontab entry is enough:

```bash
*/30 * * * * cd /path/to/zcash-name-indexer/resources/docker && docker compose pull -q && docker compose up -d
```

`docker compose up -d` recreates the container only when the image changed, and the data volumes are kept, so the node resumes where it was. A release that changes the database schema says so in [Upgrading](#-upgrading-from-10x), with the step it needs.

To follow a rules change or rollback you have reviewed yourself before a release is out, set `EXPECTED_EPOCH` in `.env` to the new epoch and run `docker compose up -d`. Set before the change comes into force, the node waits for it. Clear it again once you run a release made for that epoch, or later epoch changes will stop the node until you update it by hand.

---

### ⚠️ `EVM_CHAIN_ID` must match the anchor contract's chain

`rpc_list` is queried **by chain id**. Endpoints filed under the wrong one are invisible to
the indexer, so it never syncs a checkpoint — and this produces no error, because "no
endpoints for this chain" is indistinguishable from "nothing to do". `EVM_CHAIN_ID`
(default `56`, BNB Smart Chain) must equal `EVM_NETWORK.chain_id` in the configuration.

Endpoints are seeded from `EVM_RPC_URL` on **first start only**; afterwards, edit the
`rpc_list` table directly.

### 🔄 Server Autostart / Restart Policy

The container is configured with the `restart: unless-stopped` policy inside `docker-compose.yml`.

This ensures that:
- The container starts automatically when the host system boots (assuming the Docker daemon starts).
- The container restarts automatically if it crashes or if the Docker daemon restarts.
- If you manually stop the container (using `docker compose stop`), it will not start automatically on boot until you manually start it again.

No additional systemd service configuration is required on the host system, as Docker handles the lifecycle management natively.

---

## ⏱️ Periodic Execution (`resources/contrib/`)

The [`resources/contrib/`](resources/contrib/) directory provides production-ready templates for scheduled synchronization.

### Systemd (recommended)

| Unit | Runs | Cadence |
|:---|:---|:---|
| [`zcash-name-indexer.service`](resources/contrib/zcash-name-indexer.service) + [`.timer`](resources/contrib/zcash-name-indexer.timer) | `php indexer.php sync` | 2 min after boot, then every minute |
| [`zcash-name-indexer-watchdog.service`](resources/contrib/zcash-name-indexer-watchdog.service) + [`.timer`](resources/contrib/zcash-name-indexer-watchdog.timer) | `php indexer.php watchdog` | 5 min after boot, then every 5 minutes |

**Enable the `.timer`, not the `.service`.** Each service sets `RefuseManualStart=yes` and deliberately has **no `[Install]` section** — with one, `systemctl enable` would pull the service into `multi-user.target` and run it once at boot, outside the timer's control and before its boot delay.

```bash
sudo cp resources/contrib/zcash-name-indexer*.{service,timer} /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now zcash-name-indexer.timer zcash-name-indexer-watchdog.timer
```

**Why a separate watchdog exists.** Systemd never starts a second instance of a unit while the first is still running — concurrent activations are coalesced into one pending job. That is the right behaviour here (overlapping runs would mutate the same tables and the same tree), but it means a sync run can never observe *its own* stalled lock, so the in-run alert can never fire. The watchdog restores that alert from a separate unit. Its check is strictly read-only: it inspects the lock file's age without ever acquiring it, so it cannot make a sync run skip a cycle, and it never deletes a lock it does not own. A stalled lock is reported after `SYNC_LOCK_STALE_SEC` (600s by default) and the alert repeats every 5 minutes until the lock clears.

### Cron

[`resources/contrib/crontab`](resources/contrib/crontab) provides 1-minute interval rules. Cron *does* start overlapping runs, so the guard inside the run reports stalled locks by itself and the watchdog units above are unnecessary.

---

## 🧩 Related Components

| Component                                                                     | Role |
|:------------------------------------------------------------------------------|:---|
| [`rr-proxy`](https://github.com/ZCashNames/zcash-name-rr-proxy)               | RocksDB socket daemon that owns the compact SMT and issues Merkle proofs. |
| [`zcash-name-prover`](https://github.com/ZCashNames/zcash-name-prover)        | SP1 zkVM circuit and host runner producing Groth16 proofs of batch state transitions. |
| [`zcash-name-evm-anchor`](https://github.com/ZCashNames/zcash-name-evm-anchor)| The EVM contract storing anchored roots and verifying proofs. |
| [`rust_tx_scanner`](https://github.com/ZCashNames/rust_tx_scanner)            | Shielded-note scanner; decrypts commands and payments with the published viewing key. |

---

## 📄 License

This project is licensed under the terms of the **GNU Affero General Public License v3.0 (AGPL-3.0)**.

Please refer to the [LICENSE](LICENSE) file in the root of this repository for the full text of the license.
