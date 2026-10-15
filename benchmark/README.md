# Sync vs async PHP benchmark

Two HTTP servers run **the same SQL and the same CPU work** against the same
Postgres database. The only difference between them is the execution model.

| | Sync | Async |
|---|---|---|
| Stack | nginx → PHP-FPM (`pm = static`) → PDO | amphp/http-server (Revolt + ext-event), forked workers on `SO_REUSEPORT` → amphp/postgres |
| Concurrency model | one blocking process per in-flight request | many fibers per process; queries in flight never block the worker |
| Default size | 16 FPM children (`FPM_CHILDREN`) | 2 workers (`ASYNC_WORKERS`) × up to 64 pooled connections (`DB_POOL_SIZE`) |
| DB connections | persistent, one per child | pool per worker |
| Port | `localhost:8081` | `localhost:8082` |
| Code | [`sync/public/index.php`](sync/public/index.php) | [`async/server.php`](async/server.php) |

Both PHP containers are capped at the same `APP_CPUS` (default 2) and
`APP_MEM` (default 1g). Postgres gets `DB_CPUS` (default 4) so the database is
not the first bottleneck. Shared SQL and work live in
[`src/Benchmark/Workloads.php`](../src/Benchmark/Workloads.php).

## Endpoints

| Endpoint | Workload | Expectation |
|---|---|---|
| `/health` | nothing | baseline framework overhead |
| `/io?ms=100` | `SELECT pg_sleep(0.1)` — pure wait | **async wins big**: sync is capped at `FPM_CHILDREN / 0.1s` req/s |
| `/customer` | small indexed join (random customer) | async wins under high concurrency |
| `/report` | heavy CTE + window functions for one region | DB-bound; both converge on what Postgres can compute |
| `/regions` | three `/report` queries | sync runs them one after another, async runs them concurrently → lower latency per request |
| `/cpu?n=20000` | 20k rounds of sha256, no I/O | **no async advantage**: a busy event loop blocks its other requests |

The `/report` and `/cpu` cases are in on purpose: async helps when PHP is
*waiting*, not when PHP or the database is *working*.

## Run it

```bash
docker compose up -d --build --wait
```

The first start seeds 5,000 products, 300,000 orders and ~750,000 order items
with set-based SQL ([`docker/postgres/01-seed.sh`](../docker/postgres/01-seed.sh)).
Change the size with `SEED_ORDERS` / `SEED_PRODUCTS` and re-seed with
`docker compose down -v`.

Try a single request:

```bash
curl -s 'localhost:8081/regions' | head -c 300
```

```bash
curl -s 'localhost:8082/regions' | head -c 300
```

Run the full benchmark (k6 inside the compose network, one server at a time):

```bash
./benchmark/run.sh
```

Results go to `benchmark/results/summary.md` (plus one JSON file per run).

Knobs, all optional:

```bash
VUS=200 DURATION=30s FPM_CHILDREN=32 ASYNC_WORKERS=2 ./benchmark/run.sh '/io?ms=100' /regions@8
```

| Variable | Default | Meaning |
|---|---|---|
| `VUS` | 64 | concurrent virtual users (`/path@N` overrides per endpoint; defaults run `/report` at 16 and `/regions` at 8, since Postgres tops out at ~3 heavy queries/s) |
| `DURATION` | 20s | measured run length per endpoint/server |
| `APP_CPUS` / `APP_MEM` | 2 / 1g | limits for both PHP containers |
| `DB_CPUS` | 4 | Postgres CPU limit |
| `FPM_CHILDREN` | 16 | sync worker processes |
| `PDO_PERSISTENT` | 1 | `0` reconnects on every sync request |
| `ASYNC_WORKERS` | 2 | async event-loop processes |
| `DB_POOL_SIZE` | 64 | connections per async worker |
| `TARGETS` | `sync async` | which servers to hit |

Change server settings with `docker compose up -d` before running, or pass them
to `run.sh`, which calls `docker compose up` itself.

## Reading the results fairly

`run.sh` waits for Postgres to go idle before every run. Queries keep running
after k6 disconnects (and keep FPM children blocked), so without that wait one
run's backlog slows the next one down.

- **Raise `VUS` past `FPM_CHILDREN`** to see the sync ceiling: extra requests
  queue in nginx/FPM and p95 latency climbs, while the async server keeps
  accepting them.
- **Raising `FPM_CHILDREN` closes the gap** on I/O endpoints, at the cost of
  roughly 20–40 MB of RAM per child and one DB connection each. That memory
  cost is the honest price of sync concurrency; note it next to the numbers.
- The sync stack has an extra nginx hop (capped at 1 CPU, outside `APP_CPUS`).
- Run the load generator on the same machine only as a first pass; for final
  numbers give k6 its own cores (`K6_CPUS`) or another host.

## Pitfalls hit while building this

Each of these skewed the numbers by a lot before it was fixed. Look out for them
in your own setups:

- **PHPStreamServer doesn't set `TCP_NODELAY`.** Every keep-alive response sat
  ~40 ms waiting for a delayed ACK (a 100 ms query measured 146 ms at any
  concurrency). That's why the async server uses amphp/http-server directly.
- **amphp/postgres + ext-pgsql refuses the `ev` loop.** Use `event` (libevent)
  or `uv`, or switch the driver to pecl-pq.
- **nginx `fastcgi_keep_conn` with many nginx workers starves FPM.** Idle kept
  connections stay pinned to FPM children, so sync `/io` reached only ~50 req/s
  instead of ~160.
- **nginx caches upstream IPs.** Recreating `sync-fpm` gave 100% 502s until
  nginx was set to re-resolve through Docker DNS (`resolve`).
