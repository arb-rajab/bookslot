<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/owner/queue-health (D-0051) — basic, honest visibility into the
 * RabbitMQ-backed async surface D-0047/D-0049/D-0050 (Session 19) built,
 * for the admin frontend. Deliberately does NOT invent a per-job success/
 * failure count this codebase has no table for (no `failed_jobs` migration
 * exists — RabbitMQ, not Laravel's database queue driver, is this
 * project's transport, and this session did not add one only to serve this
 * one dashboard). Instead:
 *
 * - Real broker-level queue depth, read from the management plugin's HTTP
 *   API (already provisioned — see docker-compose.yml's `rabbitmq` service
 *   and config/services.php's `rabbitmq_management`) — best-effort: a
 *   management-plugin outage degrades this section to `available: false`
 *   rather than a 500.
 * - Real job-outcome data this codebase DOES already persist:
 *   `notification_deliveries` status counts (sent/failed/scheduled/
 *   cancelled — D-0050's reminder-send outcomes) and the count of
 *   `pending_payment` appointments still awaiting
 *   `ReleaseExpiredPendingBookingJob` (D-0049) — both tenant-scoped, real
 *   counts, not derived from anything fabricated for this dashboard.
 */
class QueueHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $notificationCounts = NotificationDelivery::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'broker' => $this->brokerHealth(),
            'reminders' => [
                'sent' => (int) ($notificationCounts['sent'] ?? 0),
                'failed' => (int) ($notificationCounts['failed'] ?? 0),
                'scheduled' => (int) ($notificationCounts['scheduled'] ?? 0),
                'cancelled' => (int) ($notificationCounts['cancelled'] ?? 0),
            ],
            'pending_payment_appointments' => Appointment::query()->where('status', 'pending_payment')->count(),
        ]);
    }

    /** @return array<string, mixed> */
    private function brokerHealth(): array
    {
        $config = config('services.rabbitmq_management');

        try {
            $response = Http::withBasicAuth($config['user'], $config['password'])
                ->timeout(2)
                ->get(sprintf(
                    '%s/api/queues/%s/%s',
                    rtrim($config['url'], '/'),
                    rawurlencode($config['vhost']),
                    rawurlencode($config['queue']),
                ));

            if (! $response->successful()) {
                return ['available' => false];
            }

            $body = $response->json();

            return [
                'available' => true,
                'messages_ready' => $body['messages_ready'] ?? null,
                'messages_unacknowledged' => $body['messages_unacknowledged'] ?? null,
                'consumers' => $body['consumers'] ?? null,
            ];
        } catch (Throwable $e) {
            Log::info('Queue health check could not reach the RabbitMQ management API.', ['error' => $e->getMessage()]);

            return ['available' => false];
        }
    }
}
