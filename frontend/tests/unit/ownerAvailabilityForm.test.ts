// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerAvailabilityPage from '~/pages/owner/availability/index.vue'
import { flushUntil } from './support/waitFor'

/**
 * Companion to ownerServicesForm.test.ts — covers the other two owner-admin
 * forms this session found with no field-level 422 display: the
 * create-staff form and the weekly-working-hours PUT, whose backend
 * validation errors come back as array-indexed dotted keys
 * (`working_hours.0.start_time`) rather than plain field names, which is
 * exactly the case useFormErrors()'s otherFieldErrors()/the page's own
 * workingHourFieldError() mapping exist to handle correctly.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

beforeEach(async () => {
  routes = {
    '/sanctum/csrf-cookie': () => ({ status: 204, body: null }),
    '/api/owner/staff': () => ({ status: 200, body: { staff: [] } }),
  }
  server = createServer((req, res) => {
    const handler = req.url ? routes[req.url] : undefined
    if (!handler) {
      res.writeHead(404).end()
      return
    }
    const { status, body } = handler(req)
    res.writeHead(status, { 'Content-Type': 'application/json' })
    res.end(body === null ? '' : JSON.stringify(body))
  })
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
  const { port } = server.address() as AddressInfo

  const config = useRuntimeConfig()
  config.public.apiOrigin = `http://127.0.0.1:${port}`
  config.public.apiBase = `http://127.0.0.1:${port}/api`

  useOwnerSession().authState.value = 'authenticated'
})

afterEach(() => new Promise<void>((resolve) => server.close(() => resolve())))

describe('owner/availability/index.vue', () => {
  it('shows a field-level message under the staff name input on a 422 from the create-staff form', async () => {
    routes['/api/owner/staff'] = (req) => {
      if (req.method === 'POST') {
        return { status: 422, body: { error: 'VALIDATION_FAILED', fields: { display_name: ['The display name field is required.'] } } }
      }
      return { status: 200, body: { staff: [] } }
    }

    const wrapper = await mountSuspended(OwnerAvailabilityPage)
    await flushUntil(() => wrapper.find('.add-staff input').exists())

    await wrapper.find('.add-staff input').setValue('  ')
    await wrapper.find('.add-staff').trigger('submit.prevent')
    // The page itself no-ops on a blank name (`if (!newStaffName.value.trim()) return`) —
    // fill something non-blank so the request actually fires.
    await wrapper.find('.add-staff input').setValue('New Staff')
    await wrapper.find('.add-staff').trigger('submit.prevent')
    await flushUntil(() => wrapper.text().includes('The display name field is required.'))

    expect(wrapper.text()).toContain('The display name field is required.')
  })

  it('maps a working_hours.{index}.{field} validation error back to the correct day row', async () => {
    const staffId = 'staff-1'
    routes['/api/owner/staff'] = () => ({
      status: 200,
      body: { staff: [{ id: staffId, tenant_id: 't1', display_name: 'Alex', is_active: true, user_id: null }] },
    })
    routes[`/api/owner/staff/${staffId}/working-hours`] = (req) => {
      if (req.method === 'PUT') {
        // Monday enabled alone means it's index 0 in the submitted array.
        return {
          status: 422,
          body: { error: 'VALIDATION_FAILED', fields: { 'working_hours.0.end_time': ['The end time must be after start time.'] } },
        }
      }
      return { status: 200, body: { working_hours: [] } }
    }
    routes[`/api/owner/staff/${staffId}/availability-exceptions`] = () => ({ status: 200, body: { availability_exceptions: [] } })

    const wrapper = await mountSuspended(OwnerAvailabilityPage)
    await flushUntil(() => wrapper.findAll('.day-row').some((row) => row.text().includes('Monday')))

    const mondayRow = wrapper.findAll('.day-row').find((row) => row.text().includes('Monday'))!
    await mondayRow.find('input[type="checkbox"]').setValue(true)
    const saveButton = wrapper.findAll('button').find((b) => b.text().includes('Save working hours'))!
    await saveButton.trigger('click')
    await flushUntil(() => {
      const row = wrapper.findAll('.day-row').find((r) => r.text().includes('Monday'))
      return !!row && row.text().includes('The end time must be after start time.')
    })

    const updatedMondayRow = wrapper.findAll('.day-row').find((row) => row.text().includes('Monday'))!
    expect(updatedMondayRow.text()).toContain('The end time must be after start time.')
  })
})
