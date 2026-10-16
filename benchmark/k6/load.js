// Constant-concurrency load test against one endpoint of one server.
// Driven by env vars from benchmark/run.sh: TARGET, BASE_URL, ENDPOINT, VUS, DURATION.
import http from 'k6/http';
import { check } from 'k6';

const TARGET = __ENV.TARGET || 'sync';
const BASE_URL = __ENV.BASE_URL || 'http://sync:8080';
const ENDPOINT = __ENV.ENDPOINT || '/io?ms=100';
const VUS = Number(__ENV.VUS || 64);

export const options = {
    scenarios: {
        load: {
            executor: 'constant-vus',
            vus: VUS,
            duration: __ENV.DURATION || '20s',
            gracefulStop: '30s',
        },
    },
    summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
    const res = http.get(`${BASE_URL}${ENDPOINT}`, { timeout: '120s' });
    check(res, { 'status is 200': (r) => r.status === 200 });
}

export function handleSummary(data) {
    const m = data.metrics;
    const d = m.http_req_duration.values;
    const row = {
        target: TARGET,
        endpoint: ENDPOINT,
        vus: VUS,
        requests: m.http_reqs.values.count,
        rps: m.http_reqs.values.rate,
        avg_ms: d.avg,
        p50_ms: d['p(50)'],
        p95_ms: d['p(95)'],
        p99_ms: d['p(99)'],
        error_rate: m.http_req_failed ? m.http_req_failed.values.rate : 0,
    };
    const slug = ENDPOINT.replace(/[^a-zA-Z0-9]+/g, '_').replace(/^_|_$/g, '');
    const fmt = (n) => n.toFixed(1);
    const line = `| ${ENDPOINT} | ${TARGET} | ${VUS} | ${fmt(row.rps)} | ${fmt(row.p50_ms)} | ${fmt(row.p95_ms)} | ${fmt(row.p99_ms)} | ${(row.error_rate * 100).toFixed(2)}% |\n`;

    return {
        stdout: line,
        [`/results/${TARGET}-${slug}-${VUS}vus.json`]: JSON.stringify(row, null, 2),
    };
}
