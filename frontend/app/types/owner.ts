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
  customer_id: string | null
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

// 05-api-contracts.md endpoint 5 (D-0056) — POST .../appointments/{id}/refund
export interface OwnerRefund {
  id: string
  payment_id: string
  amount: number
  reason: string | null
  status: string
}

export interface RefundResponse {
  refund: OwnerRefund
  payment_status: string
}

// endpoint 6 (D-0057) — POST .../appointments/{id}/balance/charge. Both
// shapes are real `200` responses (never a 4xx) — a declined/SCA-blocked
// off-session charge is an expected business outcome, not an error.
export interface BalanceChargeSucceeded {
  status: 'succeeded'
  payment_id: string
}

export interface BalanceChargeFailed {
  status: 'failed'
  failure_code: string
  fallback_action: string
}

export type BalanceChargeResponse = BalanceChargeSucceeded | BalanceChargeFailed

// endpoint 10 (D-0058) — Stripe Connect Express onboarding.
export interface OnboardingLinkResponse {
  url: string
  expires_at: number
}

export interface ConnectStatus {
  status: 'not_started' | 'pending' | 'complete' | 'restricted'
  charges_enabled: boolean
  details_submitted: boolean
}

// endpoint 11 (D-0059) — customer export/erasure.
export interface CustomerRecord {
  id: string
  name: string
  email: string
  phone: string | null
  notes: string | null
  erasure_requested_at: string | null
  created_at: string
}

export interface CustomerExportAppointment {
  id: string
  service_name: string | null
  staff_name: string | null
  starts_at: string
  ends_at: string
  status: string
  cancelled_by: string | null
  cancelled_reason: string | null
  cancelled_at: string | null
  notes: string | null
  created_at: string
}

export interface CustomerExportRefund {
  id: string
  amount: number
  reason: string | null
  status: string
  created_at: string
}

export interface CustomerExportPayment {
  id: string
  appointment_id: string
  type: string
  status: string
  amount: number
  currency: string
  failure_code: string | null
  created_at: string
  refunds: CustomerExportRefund[]
}

export interface CustomerExportMandate {
  id: string
  appointment_id: string
  mandate_text: string
  mandate_template_version: string
  balance_amount_disclosed: number
  accepted_at: string
  accepted_ip: string | null
  accepted_user_agent: string | null
}

export interface CustomerExportEvent {
  appointment_id: string | null
  event_type: string
  from_status: string | null
  to_status: string | null
  created_at: string
}

export interface CustomerExport {
  customer: CustomerRecord
  appointments: CustomerExportAppointment[]
  payments: CustomerExportPayment[]
  payment_mandates: CustomerExportMandate[]
  booking_events: CustomerExportEvent[]
}

export interface CustomerErasureResponse {
  id: string
  name: string
  email: string
  phone: string | null
  erasure_requested_at: string | null
}

export interface ReinviteResponse {
  status: string
  channel: string
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
