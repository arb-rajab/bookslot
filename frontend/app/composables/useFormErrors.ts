/**
 * Every owner-admin form (services, staff, working hours, availability
 * exceptions, appointment cancellation) previously discarded the backend's
 * `fields` object from a 422 `VALIDATION_FAILED` response (see
 * ApiErrorBody in useApi.ts) and showed only a generic "Please check the
 * highlighted fields" banner — the field-level messages were fetched but
 * never rendered. This composable is the one place that turns a caught
 * error into both a banner message and per-field messages, so every form
 * follows the same rule instead of five slightly different ones.
 *
 * Precedence: the server's response is authoritative. `applyError` always
 * *replaces* fieldErrors/formError wholesale rather than merging into
 * whatever the previous attempt left behind, so a resubmission never shows
 * a stale field error for something the latest response says is now fine.
 * Native HTML validation (`required`, `min`, `max`, …) already used on
 * these forms still gives immediate feedback and blocks a submit before it
 * ever reaches the server, but only for what the browser can check itself
 * (an empty field can't hit this codebase's cross-field or uniqueness
 * rules, for example) — it never suppresses or overrides a server error.
 */
export interface FieldErrors {
  [field: string]: string[]
}

export function useFormErrors() {
  const fieldErrors = ref<FieldErrors>({})
  const formError = ref<string | null>(null)

  function fieldError(field: string): string | null {
    return fieldErrors.value[field]?.[0] ?? null
  }

  /** Messages for fields the form has no dedicated slot for, so nothing the backend reports is ever silently dropped. */
  function otherFieldErrors(knownFields: string[]): string[] {
    return Object.entries(fieldErrors.value)
      .filter(([field]) => !knownFields.includes(field))
      .flatMap(([, messages]) => messages)
  }

  function clear(): void {
    fieldErrors.value = {}
    formError.value = null
  }

  function applyError(error: unknown, describeError: (code: string) => string): void {
    const body = apiErrorBody(error)
    fieldErrors.value = body.error === 'VALIDATION_FAILED' && body.fields ? body.fields : {}
    formError.value = describeError(body.error)
  }

  return { fieldErrors, formError, fieldError, otherFieldErrors, clear, applyError }
}
