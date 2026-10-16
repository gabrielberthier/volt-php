#!/usr/bin/env bash
# Runs the same k6 load test against the sync and async servers, one at a time,
# and writes a markdown comparison table to benchmark/results/summary.md.
#
#   ./benchmark/run.sh                          # defaults
#   VUS=200 DURATION=30s ./benchmark/run.sh     # more concurrency
#   ./benchmark/run.sh '/io?ms=50' /report@16   # only some endpoints; @N overrides VUs
set -euo pipefail

cd "$(dirname "$0")/.."

VUS="${VUS:-64}"
DURATION="${DURATION:-20s}"
WARMUP="${WARMUP:-5s}"
TARGETS="${TARGETS:-sync async}"

if [[ $# -gt 0 ]]; then
    ENDPOINTS=("$@")
else
    # Heavy queries saturate Postgres at ~3 req/s, so run them at lower concurrency.
    ENDPOINTS=('/io?ms=100' '/customer' '/report@16' '/regions@8' '/cpu?n=20000')
fi

mkdir -p benchmark/results
SUMMARY=benchmark/results/summary.md

docker compose up -d --build --wait postgres sync-fpm sync async >/dev/null

k6() {
    docker compose run --rm --no-deps -T \
        -e TARGET="$1" -e BASE_URL="http://$1:8080" -e ENDPOINT="$2" \
        -e VUS="$3" -e DURATION="$4" \
        k6 run -q /scripts/load.js 2>/dev/null
}

# Queries keep running in Postgres after k6 disconnects (and block FPM children
# until they finish). Wait for them so one run's backlog can't slow the next.
drain() {
    for _ in $(seq 120); do
        active=$(docker compose exec -T postgres psql -U user -d analytics_db -Atc \
            "SELECT count(*) FROM pg_stat_activity WHERE datname = 'analytics_db' AND state = 'active' AND pid <> pg_backend_pid()")
        [[ "$active" == "0" ]] && return
        sleep 1
    done
    echo "warning: Postgres still has ${active} active queries" >&2
}

{
    echo "# Sync vs async PHP — $(date -u +'%Y-%m-%d %H:%M UTC')"
    echo
    echo "VUs: ${VUS} unless set per endpoint, duration: ${DURATION} per run, app CPUs: ${APP_CPUS:-2}, FPM children: ${FPM_CHILDREN:-16}, async workers: ${ASYNC_WORKERS:-2}"
    echo
    echo "| endpoint | server | VUs | req/s | p50 ms | p95 ms | p99 ms | errors |"
    echo "|---|---|---|---|---|---|---|---|"
} | tee "$SUMMARY"

for spec in "${ENDPOINTS[@]}"; do
    endpoint="${spec%@*}"
    vus="$VUS"
    [[ "$spec" == *@* ]] && vus="${spec##*@}"
    for target in $TARGETS; do
        drain
        # Warm up opcache, connection pools and Postgres buffers; discard the numbers.
        k6 "$target" "$endpoint" 4 "$WARMUP" >/dev/null || true
        drain
        k6 "$target" "$endpoint" "$vus" "$DURATION" | tee -a "$SUMMARY"
    done
done

echo
echo "Saved ${SUMMARY} (raw JSON per run in benchmark/results/)"
