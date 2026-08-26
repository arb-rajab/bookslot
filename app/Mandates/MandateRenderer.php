<?php

namespace App\Mandates;

use App\Models\Service;
use App\Models\Tenant;

/**
 * D-0030 (docs/project-memory/09-decision-log.md), fulfilling this
 * project's standing ruling that `payment_mandates.mandate_text` (D-0010)
 * must be server-authoritative and rendered by exactly one shared code
 * path — both the pre-submission display endpoint
 * (MandateController::show) and the booking-creation code path
 * (BookingController::store) call this same class. Neither ever accepts
 * client-supplied mandate text; the client sends only
 * `mandate_template_version` and `mandate_accepted` (D-0015(b)).
 *
 * Deliberately not versioned/templated beyond the one constant below — the
 * actual mandate wording/copy and whether a formal Stripe SCA mandate flow
 * is required for a given card scheme/region stay explicitly deferred
 * (D-0015(a)); this class renders the one live template MVP ships with, not
 * a lookup across historical versions.
 */
final class MandateRenderer
{
    public const TEMPLATE_VERSION = 'v1';

    /**
     * @return array{template_version: string, text: string, balance_amount_disclosed: int}
     */
    public function render(Tenant $tenant, Service $service): array
    {
        $depositAmount = $this->depositAmount($service);
        $balanceAmount = $service->price_amount - $depositAmount;

        $text = sprintf(
            'You are booking "%s" with %s. A deposit of %s %s is being charged now to hold '.
            'your appointment. If you do not attend (a no-show), this deposit will be forfeited '.
            'per %s\'s policy. The remaining balance of %s %s will be automatically charged to '.
            'this same payment method once your appointment is marked completed.',
            $service->name,
            $tenant->name,
            $this->formatAmount($depositAmount),
            strtoupper($service->currency),
            $tenant->name,
            $this->formatAmount($balanceAmount),
            strtoupper($service->currency),
        );

        return [
            'template_version' => self::TEMPLATE_VERSION,
            'text' => $text,
            'balance_amount_disclosed' => $balanceAmount,
        ];
    }

    private function depositAmount(Service $service): int
    {
        if ($service->deposit_type === 'fixed') {
            return $service->deposit_fixed_amount;
        }

        return (int) round($service->price_amount * $service->deposit_percentage_bps / 10000);
    }

    private function formatAmount(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2);
    }
}
