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

function plainError(code: string): FetchError {
  const error = new FetchError('error')
  // @ts-expect-error - test double for the ofetch response shape apiErrorBody actually reads
  error.data = { error: code }
  return error
}

describe('useFormErrors', () => {
  it('surfaces a 422\'s field errors individually and suppresses the generic banner', () => {
    const form = useFormErrors()

    form.setFromError(validationError({ name: ['required'], price_amount: ['must be at least 0'] }), () => 'should not be shown')

    expect(form.errorFor('name')).toBe('required')
    expect(form.errorFor('price_amount')).toBe('must be at least 0')
    expect(form.errorFor('currency')).toBeUndefined()
    expect(form.generalError.value).toBeNull()
  })

  it('falls back to the generic description for a non-validation error', () => {
    const form = useFormErrors()

    form.setFromError(plainError('NOT_FOUND'), (code) => `describeError(${code})`)

    expect(form.generalError.value).toBe('describeError(NOT_FOUND)')
    expect(form.errorFor('name')).toBeUndefined()
  })

  it('falls back to the generic description for a VALIDATION_FAILED body with no fields', () => {
    const form = useFormErrors()

    form.setFromError(plainError('VALIDATION_FAILED'), () => 'fallback message')

    expect(form.generalError.value).toBe('fallback message')
  })

  it('clear() resets both field errors and the general error', () => {
    const form = useFormErrors()

    form.setFromError(validationError({ name: ['required'] }), () => 'x')
    expect(form.errorFor('name')).toBe('required')

    form.clear()

    expect(form.errorFor('name')).toBeUndefined()
    expect(form.generalError.value).toBeNull()
    expect(form.fieldErrors.value).toEqual({})
  })

  it('a later setFromError() call replaces prior field errors rather than merging with them', () => {
    const form = useFormErrors()

    form.setFromError(validationError({ name: ['required'] }), () => 'x')
    form.setFromError(validationError({ price_amount: ['must be at least 0'] }), () => 'x')

    expect(form.errorFor('name')).toBeUndefined()
    expect(form.errorFor('price_amount')).toBe('must be at least 0')
  })
})
