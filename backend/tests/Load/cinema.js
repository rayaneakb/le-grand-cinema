import http from 'k6/http'
import { check, fail, sleep } from 'k6'
import { Counter } from 'k6/metrics'

const base = __ENV.BASE_URL || 'http://127.0.0.1:8002'
if (base !== 'http://127.0.0.1:8002') {
    throw new Error(
        'Ce scénario utilise uniquement le serveur local de test sur le port 8002.',
    )
}
const completed = new Counter('reservations_completed')
const conflicts = new Counter('reservation_conflicts')

export const options = {
    stages: [
        { duration: '5s', target: 10 },
        { duration: '20s', target: 10 },
        { duration: '5s', target: 50 },
        { duration: '20s', target: 50 },
        { duration: '5s', target: 100 },
        { duration: '20s', target: 100 },
        { duration: '5s', target: 0 },
    ],
    thresholds: {
        http_req_failed: ['rate==0'],
        checks: ['rate==1'],
        'http_req_duration{action:films}': ['p(95)<=3000'],
        'http_req_duration{action:seats}': ['p(95)<=3000'],
        'http_req_duration{action:confirm}': ['p(95)<=3000'],
        reservations_completed: ['count>0'],
    },
    summaryTrendStats: ['avg', 'med', 'p(95)', 'max'],
}

export function setup() {
    const response = http.get(
        `${base}/api/screenings/${__ENV.SCREENING_ID}/seats`,
    )
    if (response.status !== 200) fail('La séance de test est inaccessible.')
    return response.json()
}

export default function (initial) {
    const action = Math.random()
    if (action < 0.7) {
        const response = http.get(`${base}/api/films`, {
            tags: { action: 'films' },
        })
        check(response, {
            'programmation reçue': (r) =>
                r.status === 200 && Array.isArray(r.json('films')),
        })
    } else {
        const response = http.get(
            `${base}/api/screenings/${initial.screening.id}/seats`,
            { tags: { action: 'seats' } },
        )
        if (
            !check(response, {
                'plan reçu': (r) =>
                    r.status === 200 && Array.isArray(r.json('seats')),
            })
        )
            return
        if (action >= 0.95) reserveAndCancel(response.json())
    }
    sleep(0.3 + Math.random() * 0.5)
}

function reserveAndCancel(plan) {
    const free = plan.seats.filter((seat) => seat.available)
    if (free.length === 0) return
    const csrfResponse = http.get(`${base}/api/csrf`, {
        tags: { action: 'csrf' },
    })
    if (
        !check(csrfResponse, {
            'jeton CSRF reçu': (r) =>
                r.status === 200 && typeof r.json('csrfToken') === 'string',
        })
    )
        return
    const headers = {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfResponse.json('csrfToken'),
    }
    const payload = {
        screeningId: plan.screening.id,
        seatIds: [free[Math.floor(Math.random() * free.length)].id],
        firstName: 'Charge',
        lastName: 'Test',
        email: 'charge@example.com',
        expectedUnitPriceCents: plan.unitPriceCents,
        confirmationKey: `00000000-0000-4000-8000-${(__VU * 100000000 + __ITER).toString(16).padStart(12, '0')}`,
    }
    const response = http.post(
        `${base}/api/reservations`,
        JSON.stringify(payload),
        {
            headers,
            tags: { action: 'confirm' },
            responseCallback: http.expectedStatuses(200, 201, 409),
        },
    )
    if (response.status === 409) {
        check(response, {
            'conflit de siège expliqué': (r) =>
                r.json('status') === 'seats_unavailable',
        })
        conflicts.add(1)
        return
    }
    if (
        !check(response, {
            'réservation confirmée': (r) =>
                [200, 201].includes(r.status) &&
                r.json('status') === 'confirmed',
        })
    )
        return
    const booking = response.json('reservation')
    const query = booking.managementUrl.slice(
        booking.managementUrl.indexOf('?'),
    )
    const cancel = http.post(
        `${base}/api/reservations/${booking.reference}/cancel${query}`,
        null,
        {
            headers: { 'X-CSRF-Token': csrfResponse.json('csrfToken') },
            tags: { action: 'cancel', name: 'POST annulation' },
        },
    )
    if (
        check(cancel, {
            'annulation confirmée': (r) =>
                r.status === 200 &&
                r.json('reservation.status') === 'cancelled',
        })
    ) {
        completed.add(1)
    }
}

export function handleSummary(data) {
    return { 'coverage/load-summary.json': JSON.stringify(data, null, 2) }
}
