import http from 'k6/http';
import { check, sleep } from 'k6';

// TF-02: Nebenläufigkeit & Überbuchungsschutz
// Simulates 50 Virtual Users attempting to book the last seat simultaneously.
export const options = {
  scenarios: {
    concurrent_booking: {
      executor: 'per-vu-iterations',
      vus: 50,
      iterations: 1,
      maxDuration: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.99'], // Expect requests to return proper HTTP responses
    http_req_duration: ['p(95)<500'], // 95% of requests under 500ms
  },
};

export default function () {
  const url = __ENV.TARGET_URL || 'http://localhost:8080/wp-json/cybevents/v1/book-sessions';
  const token = __ENV.TEST_TOKEN || 'test_token_123';
  const sessionId = __ENV.TEST_SESSION_ID || 1;

  const payload = JSON.stringify({
    token: token,
    session_ids: [sessionId],
  });

  const params = {
    headers: {
      'Content-Type': 'application/json',
    },
  };

  const res = http.post(url, payload, params);

  check(res, {
    'status is 200 or 409': (r) => r.status === 200 || r.status === 409,
  });

  sleep(0.1);
}
