<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant-scoped tables
    |--------------------------------------------------------------------------
    |
    | The manifest referenced by D-0005/D-0009 (docs/project-memory/09-decision-
    | log.md) and 07-testing-strategy.md's tenant-isolation suite. Every table
    | listed here must carry a `tenant_id` column and an enforced Postgres RLS
    | policy (see the RLS migration and tests/TenantIsolation/RlsManifestTest.php).
    |
    | This is not a convenience list — it is what the manifest-completeness
    | and RLS-enforcement checks are graded against. A new tenant-scoped table
    | that isn't added here fails the manifest-completeness check on its own;
    | a table listed here without a real RLS policy fails the RLS-enforcement
    | check. Both checks are parameterized over this list plus the live
    | schema, not hand-maintained per table.
    |
    | `tenants` and `stripe_webhook_events` are deliberately absent — tenants
    | is the tenancy boundary itself, not inside it, and stripe_webhook_events
    | is not tenant-scoped (a webhook may arrive before its tenant is known).
    |
    */

    'tenant_scoped_tables' => [
        'users',
        'staff',
        'services',
        'customers',
        'staff_working_hours',
        'availability_exceptions',
        'appointments',
        'payments',
        'refunds',
        'payment_mandates',
        'booking_events',
        'notification_deliveries',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant context GUC
    |--------------------------------------------------------------------------
    |
    | The Postgres session variable every RLS policy above is keyed on. Set
    | only via a parameterized set_config(..., true) call as the first
    | statement inside an explicit transaction — see App\Tenancy\TenantContext.
    | Never a session-level SET. Per D-0009.
    |
    */

    'guc' => 'app.current_tenant_id',

];
