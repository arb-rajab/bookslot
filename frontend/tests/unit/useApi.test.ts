// @vitest-environment nuxt
import { describe, expect, it } from 'vitest'
import { FetchError } from 'ofetch'
import { apiErrorBody } from '~/composables/useApi'

describe('apiErrorBody', () => {
  it('extracts the backend\'s structured { error, fields } body from a FetchError', () => {
    const error = new FetchError('Unprocessable')
    // @ts-expect-error - test double for the ofetch response shape apiErrorBody actually reads
    error.data = { error: 'VALIDATION_FAILED', fields: { name: ['required'] } }

    expect(apiErrorBody(error)).toEqual({ error: 'VALIDATION_FAILED', fields: { name: ['required'] } })
  })

  it('falls back to NETWORK_ERROR when the request never reached the API at all', () => {
    expect(apiErrorBody(new TypeError('Failed to fetch'))).toEqual({ error: 'NETWORK_ERROR' })
  })

  it('falls back to NETWORK_ERROR for a FetchError whose data carries no structured error code', () => {
    const error = new FetchError('Server error')
    // @ts-expect-error - simulating an unstructured 500 (e.g. an HTML error page)
    error.data = '<html>500</html>'

    expect(apiErrorBody(error)).toEqual({ error: 'NETWORK_ERROR' })
  })
})
