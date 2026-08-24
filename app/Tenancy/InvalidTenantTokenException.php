<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * The single exception type for every way a signed tenant-carrying token
 * (App\Tenancy\SignedTenantToken) can fail verification — bad signature,
 * wrong purpose, or expired. Deliberately one type, not three: per D-0021,
 * the HTTP response rendered from this must not let a caller distinguish
 * which of those happened, since there is no separate raw id in the
 * request to leak an existence signal against.
 */
class InvalidTenantTokenException extends RuntimeException {}
