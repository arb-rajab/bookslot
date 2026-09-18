/**
 * A fixed `setTimeout` wait after triggering a network-backed action (the
 * mock HTTP server round-trip these owner-admin form tests use) is a race,
 * not a guarantee — under worker contention (the full `vitest run` spawns
 * one worker per file) a 50ms delay is not always enough for the request/
 * response/re-render to finish, and the test fails even though the app
 * behaves correctly. Poll for the expected condition instead of assuming a
 * fixed delay covers it.
 */
export async function flushUntil(predicate: () => boolean, timeoutMs = 2000, intervalMs = 10): Promise<void> {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    if (predicate()) return
    await new Promise((resolve) => setTimeout(resolve, intervalMs))
  }
  if (!predicate()) {
    throw new Error(`flushUntil: condition not met within ${timeoutMs}ms`)
  }
}
