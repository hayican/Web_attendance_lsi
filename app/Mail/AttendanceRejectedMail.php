<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AttendanceRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $employeeName,
        public readonly string $dateLabel,
        public readonly string $phaseLabel,
        public readonly string $rejectReason,
        public readonly ?string $retryDeadline = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Absensi/Kunjungan Ditolak ({$this->dateLabel})",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.attendance-rejected',
        );
    }
}
