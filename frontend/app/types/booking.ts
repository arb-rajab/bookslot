// Mirrors 05-api-contracts.md's response shapes exactly — no fields beyond
// what the real endpoints actually return.

export interface Service {
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
}

export interface Slot {
  staff_id: string
  starts_at: string
  ends_at: string
}

export interface Mandate {
  template_version: string
  text: string
  balance_amount_disclosed: number
}

export interface BookingResponse {
  appointment_id: string
  status: string
  deposit: {
    amount: number
    currency: string
    client_secret: string
  }
  manage_token: string
  payment_confirmation_token: string
}

export interface ConfirmPaymentResponse {
  status: string
  last_payment_error?: {
    code: string | null
    message: string | null
  }
}
