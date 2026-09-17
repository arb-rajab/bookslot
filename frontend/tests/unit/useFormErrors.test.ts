// @vitest-environment nuxt
import { describe, expect, it } from 'vitest'
import { FetchError } from 'ofetch'
import { useFormErrors } from '~/composables/useFormErrors'

function validationError(fields: Record<string, string[]>): FetchError {
  const error = new FetchError('Unprocessable')
  // @ts-expect-error - test double for the ofetch response shape apiErrorBody actually reads
  error.data = { error: 'VALIDATION_FAILED', fields }
  return error
}

describe('useFormErrors', () => {
  it('surfaces both a banner message and per-field messages from a VALIDATION_FAILED response', () => {
    const { fieldErrors, formError, fieldError, applyError } = useFormErrors()

    applyError(validationError({ name: ['The name field is required.'] }), (code) =>
      code === 'VALIDATION_FAILED' ? 'Please check the highlighted fields.' : code,
    )

    expect(formError.value).toBe('Please check the highlighted fields.')
    expect(fieldErrors.value).toEqual({ name: ['The name field is required.'] })
    expect(fieldError('name')).toBe('The name field is required.')
    expect(fieldError('other_field')).toBeNull()
  })

  it('does not populate fieldErrors for a non-validation error, even though formError is still set', () => {
    const { fieldErrors, formError, applyError } = useFormErrors()

    const error = new FetchError('Not found')
    // @ts-expect-error - test double
    error.data = { error: 'NOT_FOUND' }

    applyError(error, () => 'That record could not be found.')

    expect(formError.value).toBe('That record could not be found.')
    expect(fieldErrors.value).toEqual({})
  })

  it('replaces the previous attempt\'s errors wholesale rather than merging, so the server always wins', () => {
    const { fieldErrors, applyError } = useFormErrors()

    applyError(validationError({ name: ['required'], currency: ['invalid'] }), () => 'x')
    expect(fieldErrors.value).toEqual({ name: ['required'], currency: ['invalid'] })

    // A second, different failure (e.g. after the user fixed `name` but not `currency`) —
    // the stale `name` error must not still be showing afterward.
    applyError(validationError({ currency: ['invalid'] }), () => 'x')
    expect(fieldErrors.value).toEqual({ currency: ['invalid'] })
  })

  it('clear() resets both the banner and every field error', () => {
    const { fieldErrors, formError, applyError, clear } = useFormErrors()

    applyError(validationError({ name: ['required'] }), () => 'x')
    clear()

    expect(formError.value).toBeNull()
    expect(fieldErrors.value).toEqual({})
  })

  it('otherFieldErrors() returns messages for fields the form has no dedicated slot for', () => {
    const { otherFieldErrors, applyError } = useFormErrors()

    applyError(validationError({
      name: ['required'],
      'working_hours.0.day_of_week': ['The day_of_week has already been taken.'],
    }), () => 'x')

    expect(otherFieldErrors(['name'])).toEqual(['The day_of_week has already been taken.'])
    expect(otherFieldErrors(['name', 'working_hours.0.day_of_week'])).toEqual([])
  })
})
