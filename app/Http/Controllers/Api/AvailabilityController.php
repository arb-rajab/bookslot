<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AvailabilityException;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/tenants/{slug}/availability (05-api-contracts.md endpoint 1).
 * Never built before this session — 04-data-model.md's "derived, not
 * materialized" slot algorithm (staff_working_hours + availability_
 * exceptions + existing appointments' occupancy, all timezone-aware against
 * the tenant's IANA zone) existed only as a specification until now.
 *
 * `service_id`/`staff_id` are query params, not route segments, so
 * MandateController's implicit-route-model-binding-order finding doesn't
 * apply here — both are looked up manually anyway (findOrFail against the
 * tenant-scoped query), same pattern every controller in this repository
 * uses.
 *
 * This does not duplicate BookingController's exclusion-constraint check —
 * that constraint remains the sole source of truth against a genuine race
 * (D-0007/D-0008). This endpoint is read-only, best-effort display: it can
 * race against a concurrent booking between the read here and a later
 * POST .../bookings, which is exactly why that endpoint's defined 409
 * response tells the frontend to re-fetch availability, not retry blindly.
 *
 * Grouped lookups below are built into plain arrays, not chained
 * Collection::groupBy() calls — PHPStan/Larastan treats Eloquent Collection
 * and Support Collection as distinct, non-covariant generic types even
 * though one extends the other, which turns a "collection of collections"
 * return value into a losing type-hinting fight for no real benefit here.
 */
class AvailabilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'uuid'],
            'staff_id' => ['nullable', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tenant = Tenant::query()->findOrFail(CurrentTenant::id());
        $service = Service::query()->where('is_active', true)->findOrFail($validated['service_id']);

        $timezone = $tenant->timezone;
        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['from'], $timezone)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['to'], $timezone)->startOfDay();

        $maxLookaheadDays = (int) config('booking.availability_max_lookahead_days');
        if ($from->diffInDays($to) > $maxLookaheadDays) {
            throw ValidationException::withMessages([
                'to' => "The date range must not exceed {$maxLookaheadDays} days.",
            ]);
        }

        // No staff_id: every active staff member, per 05 ("omit to search
        // across all staff who offer the service") — this schema has no
        // staff/service pivot yet, so every active staff member is treated
        // as offering every service.
        $staffQuery = Staff::query()->where('is_active', true);
        if (! empty($validated['staff_id'])) {
            $staffQuery->where('id', $validated['staff_id']);
        }
        $staffList = $staffQuery->get();

        if (! empty($validated['staff_id']) && $staffList->isEmpty()) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $slots = [];
        foreach ($staffList as $staff) {
            $slots = array_merge($slots, $this->slotsForStaff($staff, $service, $from, $to, $timezone));
        }

        usort($slots, fn (array $a, array $b) => [$a['starts_at'], $a['staff_id']] <=> [$b['starts_at'], $b['staff_id']]);

        return response()->json(['slots' => $slots]);
    }

    /**
     * @return array<int, array{staff_id: string, starts_at: string, ends_at: string}>
     */
    private function slotsForStaff(Staff $staff, Service $service, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        /** @var array<int, array<int, StaffWorkingHour>> $workingHoursByDay */
        $workingHoursByDay = [];
        foreach (StaffWorkingHour::query()->where('staff_id', $staff->id)->get() as $hours) {
            $workingHoursByDay[$hours->day_of_week][] = $hours;
        }

        /** @var array<string, array<int, AvailabilityException>> $exceptionsByDate */
        $exceptionsByDate = [];
        $exceptions = AvailabilityException::query()
            ->where(fn ($query) => $query->whereNull('staff_id')->orWhere('staff_id', $staff->id))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();
        foreach ($exceptions as $exception) {
            $exceptionsByDate[$exception->date->toDateString()][] = $exception;
        }

        // A padded window around [from, to]: buffer minutes (bounded to
        // <=1440 by the appointments table's own CHECK constraint) can push
        // an existing appointment's occupancy range up to one day outside
        // the literal date range being queried. Read once per staff member,
        // not once per candidate slot.
        $existingAppointments = Appointment::query()
            ->where('staff_id', $staff->id)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('starts_at', '<', $to->addDay())
            ->where('ends_at', '>', $from->subDay())
            ->get(['starts_at', 'ends_at', 'buffer_before_minutes', 'buffer_after_minutes'])
            ->all();

        $now = CarbonImmutable::now($timezone);
        $increment = (int) config('booking.availability_slot_increment_minutes');

        $slots = [];
        $date = $from;
        while ($date->lessThanOrEqualTo($to)) {
            $dayExceptions = $exceptionsByDate[$date->toDateString()] ?? [];

            $blockedAllDay = collect($dayExceptions)
                ->contains(fn (AvailabilityException $e) => ! $e->is_available && $e->start_time === null);

            if (! $blockedAllDay) {
                $windows = $this->windowsForDate($date, $workingHoursByDay[$date->dayOfWeek] ?? [], $dayExceptions, $timezone);

                foreach ($windows as [$windowStart, $windowEnd]) {
                    $slots = array_merge($slots, $this->slotsWithinWindow(
                        $staff, $service, $windowStart, $windowEnd, $increment, $existingAppointments, $now,
                    ));
                }
            }

            $date = $date->addDay();
        }

        return $slots;
    }

    /**
     * Working-hours windows for one calendar date, with that date's
     * availability_exceptions applied: a full-day block already short-
     * circuits the caller before this runs; a partial block (is_available =
     * false, a time range) subtracts from the window list; a one-off extra
     * window (is_available = true) is appended. All local wall-clock times
     * are projected onto this specific date in the tenant's zone here, not
     * derived by adding minutes across days — the DST-safety 04-data-model.md
     * requires.
     *
     * @param  array<int, StaffWorkingHour>  $dayWorkingHours
     * @param  array<int, AvailabilityException>  $dayExceptions
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windowsForDate(CarbonImmutable $date, array $dayWorkingHours, array $dayExceptions, string $timezone): array
    {
        $windows = array_map(fn (StaffWorkingHour $hours) => [
            $this->localTime($date, $hours->start_time, $timezone),
            $this->localTime($date, $hours->end_time, $timezone),
        ], $dayWorkingHours);

        foreach ($dayExceptions as $exception) {
            if ($exception->is_available) {
                $windows[] = [
                    $exception->start_time ? $this->localTime($date, $exception->start_time, $timezone) : $date,
                    $exception->end_time ? $this->localTime($date, $exception->end_time, $timezone) : $date->addDay(),
                ];

                continue;
            }

            if ($exception->start_time === null) {
                continue; // the all-day block case; already handled by the caller
            }

            $blockStart = $this->localTime($date, $exception->start_time, $timezone);
            $blockEnd = $this->localTime($date, $exception->end_time, $timezone);
            $windows = $this->subtractInterval($windows, $blockStart, $blockEnd);
        }

        return $windows;
    }

    private function localTime(CarbonImmutable $date, string $time, string $timezone): CarbonImmutable
    {
        [$hour, $minute, $second] = array_pad(explode(':', $time), 3, '0');

        return CarbonImmutable::create($date->year, $date->month, $date->day, (int) $hour, (int) $minute, (int) $second, $timezone);
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $windows
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function subtractInterval(array $windows, CarbonImmutable $blockStart, CarbonImmutable $blockEnd): array
    {
        $result = [];

        foreach ($windows as [$start, $end]) {
            if ($blockEnd->lessThanOrEqualTo($start) || $blockStart->greaterThanOrEqualTo($end)) {
                $result[] = [$start, $end];

                continue;
            }

            if ($start->lessThan($blockStart)) {
                $result[] = [$start, $blockStart];
            }

            if ($end->greaterThan($blockEnd)) {
                $result[] = [$blockEnd, $end];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, Appointment>  $existingAppointments
     * @return array<int, array{staff_id: string, starts_at: string, ends_at: string}>
     */
    private function slotsWithinWindow(
        Staff $staff,
        Service $service,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        int $increment,
        array $existingAppointments,
        CarbonImmutable $now,
    ): array {
        $slots = [];
        $slotStart = $windowStart;

        while ($slotStart->lessThan($now) && $slotStart->addMinutes($service->duration_minutes)->lessThanOrEqualTo($windowEnd)) {
            $slotStart = $slotStart->addMinutes($increment);
        }

        while ($slotStart->addMinutes($service->duration_minutes)->lessThanOrEqualTo($windowEnd)) {
            $slotEnd = $slotStart->addMinutes($service->duration_minutes);

            if ($this->fitsWithoutConflict($slotStart, $slotEnd, $service, $existingAppointments)) {
                $slots[] = [
                    'staff_id' => $staff->id,
                    'starts_at' => $slotStart->utc()->toIso8601String(),
                    'ends_at' => $slotEnd->utc()->toIso8601String(),
                ];
            }

            $slotStart = $slotStart->addMinutes($increment);
        }

        return $slots;
    }

    /**
     * Mirrors the exclusion constraint's own occupancy check (D-0007/D-0008):
     * each side's literal appointment window is padded by its own buffer
     * before comparing for overlap.
     *
     * @param  array<int, Appointment>  $existingAppointments
     */
    private function fitsWithoutConflict(CarbonImmutable $slotStart, CarbonImmutable $slotEnd, Service $service, array $existingAppointments): bool
    {
        $occupancyStart = $slotStart->subMinutes($service->buffer_before_minutes);
        $occupancyEnd = $slotEnd->addMinutes($service->buffer_after_minutes);

        foreach ($existingAppointments as $appointment) {
            $existingOccupancyStart = CarbonImmutable::parse($appointment->starts_at)->subMinutes($appointment->buffer_before_minutes);
            $existingOccupancyEnd = CarbonImmutable::parse($appointment->ends_at)->addMinutes($appointment->buffer_after_minutes);

            if ($occupancyStart->lessThan($existingOccupancyEnd) && $existingOccupancyStart->lessThan($occupancyEnd)) {
                return false;
            }
        }

        return true;
    }
}
