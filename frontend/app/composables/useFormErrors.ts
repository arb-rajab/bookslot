/**
 * Consistent field-level validation error handling for owner-admin forms.
 *
 * Precedence between client-side and server-side validation: native HTML5
 * constraints (`required`, `min`, `max`, `pattern`, …) already on these
 * forms run first and block submission before any request is made — that's
 * the fastest feedback path and is left as-is, not duplicated here. The
 * backend's 422 response (`{ error: 'VALIDATION_FAILED', fields: {...} }`,
 * bootstrap/app.php) is authoritative for everything HTML5 can't check
 * (cross-field rules, uniqueness, DB constraints) and always wins on
 * conflict: `setFromError` replaces whatever field errors were showing, and
 * callers clear() at the start of each submit so a stale server error never
 * lingers over input the user has since corrected.
 */
export interface FormErrorsHandle {
  fieldErrors: Ref<Record<string, string[]>>
  generalError: Ref<string | null>
  errorFor: (field: string) => string | undefined
  setFromError: (error: unknown, describe: (code: string) => string) => void
  clear: () => void
}

export function useFormErrors(): FormErrorsHandle {
  const fieldErrors = ref<Record<string, string[]>>({})
  const generalError = ref<string | null>(null)

  function errorFor(field: string): string | undefined {
    return fieldErrors.value[field]?.[0]
  }

  /**
   * A VALIDATION_FAILED body with field errors is shown entirely inline
   * (no redundant generic banner); any other error code — or a
   * VALIDATION_FAILED with no `fields` at all — falls back to the
   * caller-provided generic description, same as every form's existing
   * describeError() mapping.
   */
  function setFromError(error: unknown, describe: (code: string) => string): void {
    const body = apiErrorBody(error)
    const fields = body.fields ?? {}
    fieldErrors.value = fields
    generalError.value = Object.keys(fields).length > 0 ? null : describe(body.error)
  }

  function clear(): void {
    fieldErrors.value = {}
    generalError.value = null
  }

  return { fieldErrors, generalError, errorFor, setFromError, clear }
}
