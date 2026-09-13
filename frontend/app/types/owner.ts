// Mirrors the owner-facing endpoints this session added (D-0051) — no
// fields beyond what those endpoints actually return, same discipline as
// types/booking.ts.

export interface OwnerService {
  id: string
  name: string
  duration_minutes: number
  price_amount: number
  currency: string
  deposit_type: 'fixed' | 'percentage'
  deposit_fixed_amount: number | null
  deposit_percentage_bps: number | null
  buffer_before_minutes: number
  buffer_after_minutes: number
  is_active: boolean
}

export interface OwnerStaff {
  id: string
  display_name: string
  is_active: boolean
}

export interface WorkingHour {
  id?: string
  day_of_week: number
  start_time: string
  end_time: string
}

export interface AvailabilityException {
  id: string
  date: string
  is_available: boolean
  start_time: string | null
  end_time: string | null
  reason: string | null
}

export interface OwnerPayment {
  id: string
  type: string
  status: string
  amount: number
  currency: string
  failure_code: string | null
  created_at: string
}

export interface OwnerReminder {
  id: string
  purpose: string
  channel: string
  scheduled_for: string
  sent_at: string | null
  status: string
}

export interface OwnerBookingEvent {
  id: string
  actor_type: string
  event_type: string
  from_status: string | null
  to_status: string | null
  created_at: string
}

export interface OwnerAppointmentDetail {
  id: string
  status: string
  starts_at: string
  ends_at: string
  customer_name: string | null
  customer_email: string | null
  customer_phone: string | null
  service_name: string | null
  staff_name: string | null
  deposit_status: string | null
  notes: string | null
  cancelled_by: string | null
  cancelled_reason: string | null
  cancelled_at: string | null
  payments: OwnerPayment[]
  reminders: OwnerReminder[]
  events: OwnerBookingEvent[]
}

export interface NotificationLogEntry {
  id: string
  appointment_id: string
  customer_name: string | null
  purpose: string
  channel: string
  scheduled_for: string
  sent_at: string | null
  status: string
}

export interface QueueHealth {
  broker: {
    available: boolean
    messages_ready?: number | null
    messages_unacknowledged?: number | null
    consumers?: number | null
  }
  reminders: {
    sent: number
    failed: number
    scheduled: number
    cancelled: number
  }
  pending_payment_appointments: number
}
