// @vitest-environment nuxt
import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import FieldError from '~/components/FieldError.vue'

describe('components/FieldError.vue', () => {
  it('renders nothing when there is no message', async () => {
    const wrapper = await mountSuspended(FieldError, { props: {} })

    expect(wrapper.find('.field-error').exists()).toBe(false)
  })

  it('renders the message when one is given', async () => {
    const wrapper = await mountSuspended(FieldError, { props: { message: 'is required' } })

    expect(wrapper.find('.field-error').text()).toBe('is required')
  })
})
