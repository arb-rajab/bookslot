<?php

// This is a decoupled API-only backend (03-architecture.md D-0002) — there
// is no server-rendered web frontend. This file exists because
// bootstrap/app.php's withRouting() call needs it; it intentionally
// carries no routes. The Nuxt frontend is a separate application and not
// this session's concern. See routes/api.php for the application's actual
// endpoints.
