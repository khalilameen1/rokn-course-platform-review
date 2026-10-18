// Launch-day load smoke for the Rokn API, written for k6 (https://k6.io).
//
// It models the two populations the launch brings: guests browsing the
// catalogue, and signed-in learners watching lessons (manifest, then
// heartbeats). AI chat is off unless CHAT=1, because every chat turn costs
// real OpenRouter credit.
//
//   k6 run -e BASE_URL=https://staging.rokn.app \
//          -e TOKENS_FILE=./tokens.json -e COURSE_ID=12 -e LESSON_ID=340 \
//          backend/scripts/load/launch-smoke.js
//
// tokens.json is a JSON array of bearer tokens for staging test accounts
// enrolled in COURSE_ID (one token per learner VU; tokens are reused
// round-robin when there are fewer). Never point this at production.
//
// All VUs share one source address. The API's per-IP ceilings
// (API_READ_IP_PER_MINUTE, AUTH_IP_PER_MINUTE) exist for carrier NAT and
// will trip here long before the server does, so either run from three or
// four machines or raise those two variables on staging for the run.
//
// Pass criteria (see docs/LAUNCH-DAY-RUNBOOK-AR.md): p95 reads < 800 ms,
// p95 writes < 1500 ms, < 1 % failed requests, no 5xx, no MySQL deadlocks.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const BASE = (__ENV.BASE_URL || '').replace(/\/$/, '');
const COURSE_ID = __ENV.COURSE_ID || '1';
const LESSON_ID = __ENV.LESSON_ID || '1';
const CHAT = __ENV.CHAT === '1';
const GUESTS = Number(__ENV.GUESTS || 600);
const LEARNERS = Number(__ENV.LEARNERS || 400);

const tokens = new SharedArray('tokens', () =>
    __ENV.TOKENS_FILE ? JSON.parse(open(__ENV.TOKENS_FILE)) : []);

export const options = {
    scenarios: {
        guests: {
            executor: 'ramping-vus',
            exec: 'guest',
            startVUs: 0,
            stages: [
                { duration: '2m', target: GUESTS },
                { duration: '6m', target: GUESTS },
                { duration: '1m', target: 0 },
            ],
        },
        learners: {
            executor: 'ramping-vus',
            exec: 'learner',
            startVUs: 0,
            stages: [
                { duration: '2m', target: LEARNERS },
                { duration: '6m', target: LEARNERS },
                { duration: '1m', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{kind:read}': ['p(95)<800'],
        'http_req_duration{kind:write}': ['p(95)<1500'],
        'checks': ['rate>0.99'],
    },
};

const json = (kind, extra = {}) => ({
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...extra },
    tags: { kind },
});

const bearer = token => ({ Authorization: `Bearer ${token}` });

const ok = (res, name) => check(res, {
    [`${name} ok`]: r => r.status >= 200 && r.status < 300,
    [`${name} not 5xx`]: r => r.status < 500,
});

export function setup() {
    if (!BASE) throw new Error('BASE_URL is required');
    if (/rokn\.app$/.test(BASE) && !/staging|test/.test(BASE)) {
        throw new Error('Refusing to load-test what looks like production.');
    }
    const health = http.get(`${BASE}/api/health/ready`, json('read'));
    if (health.status !== 200) throw new Error(`health/ready returned ${health.status}`);
    if (LEARNERS > 0 && tokens.length === 0) {
        throw new Error('TOKENS_FILE is required for the learners scenario (or set LEARNERS=0).');
    }
}

// A guest opens the app, scrolls the catalogue, opens one course, searches.
export function guest() {
    ok(http.get(`${BASE}/api/v1/settings`, json('read')), 'settings');
    sleep(1);
    ok(http.get(`${BASE}/api/v1/courses/list`, json('read')), 'courses/list');
    sleep(2);
    ok(http.get(`${BASE}/api/v1/courses/${COURSE_ID}/details`, json('read')), 'course details');
    sleep(3);
    ok(http.get(`${BASE}/api/v1/search/courses?q=${encodeURIComponent('تصميم')}`, json('read')), 'search');
    sleep(4);
}

// A learner opens ركني and watches one lesson: one manifest, then a
// heartbeat every five seconds for about a minute, as the player does.
export function learner() {
    const auth = bearer(tokens[__VU % tokens.length]);

    ok(http.get(`${BASE}/api/v1/learning/courses`, json('read', auth)), 'learning/courses');
    ok(http.get(`${BASE}/api/v1/saved-folders`, json('read', auth)), 'saved-folders');
    ok(http.get(`${BASE}/api/v1/user/watch-history?per_page=10`, json('read', auth)), 'watch-history');
    sleep(1);

    const manifest = http.post(`${BASE}/api/v1/lessons/${LESSON_ID}/playback-manifest`, '{}', json('write', auth));
    if (!ok(manifest, 'playback-manifest')) { sleep(5); return; }
    const sessionId = manifest.json('data.playback_session_id');
    if (!sessionId) { sleep(5); return; }

    for (let sequence = 1; sequence <= 12; sequence++) {
        const body = JSON.stringify({
            lesson_id: Number(LESSON_ID),
            playback_session_id: sessionId,
            sequence,
            event_type: sequence === 1 ? 'start' : 'heartbeat',
            position_seconds: sequence * 5,
            duration_seconds: 300,
        });
        ok(http.post(`${BASE}/api/v1/user/watch-history`, body, json('write', auth)), 'heartbeat');
        sleep(5);
    }

    if (CHAT) {
        const turn = JSON.stringify({
            message: 'إزاي أبدأ أول مشروع؟',
            client_request_id: uuidv4(),
            lesson_id: Number(LESSON_ID),
        });
        ok(http.post(`${BASE}/api/v1/courses/${COURSE_ID}/chat`, turn, json('write', auth)), 'chat send');
    }
    sleep(3);
}
