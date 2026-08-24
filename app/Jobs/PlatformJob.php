<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * D-0009: base for tenant-less platform housekeeping (e.g. purging
 * stripe_webhook_events) — sets no tenant GUC and must never touch a
 * tenant-scoped table. Distinct from TenantScopedJob so a job's base class
 * alone states which category it belongs to.
 */
abstract class PlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
}
