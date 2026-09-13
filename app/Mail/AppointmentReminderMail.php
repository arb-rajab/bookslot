<?php

namespace App\Mail;

use App\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FR-06 (02-requirements.md), D-0050 (09-decision-log.md). A real Mailable,
 * not Mail::raw() — Mail::fake()'s assertSent()/assertNothingSent() only
 * track Mailable-object sends reliably; a proper class here (rather than a
 * raw closure-built message) is what makes
 * tests/Feature/Jobs/SendAppointmentReminderJobTest.php's assertions mean
 * anything.
 *
 * Not itself ShouldQueue — SendAppointmentReminderJob (already a queued
 * TenantScopedJob) is the unit of async work; queuing the mail a second
 * time here would be redundant and would lose this job's own tenant-context
 * middleware for the actual send.
 */
class AppointmentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly NotificationDelivery $delivery) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectFor($this->delivery->purpose));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.appointment-reminder',
            with: [
                'tenantName' => $this->delivery->tenant->name,
                'serviceName' => $this->delivery->appointment->service->name,
                'startsAt' => $this->delivery->appointment->starts_at->toDayDateTimeString(),
            ],
        );
    }

    private function subjectFor(string $purpose): string
    {
        return match ($purpose) {
            'reminder_7d' => 'Your upcoming appointment — one week to go',
            'reminder_24h' => 'Reminder: your appointment is tomorrow',
            'reminder_2h' => 'Reminder: your appointment is in 2 hours',
            default => 'Appointment reminder',
        };
    }
}
