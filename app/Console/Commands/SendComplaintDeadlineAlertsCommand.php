<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\ComplaintDeadlineAlertMail;
use App\Models\Complaint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

final class SendComplaintDeadlineAlertsCommand extends Command
{
    protected $signature = 'complaints:send-deadline-alerts';

    protected $description = 'Email the shop a daily list of unanswered complaints from the alert day onwards, including overdue ones.';

    public function handle(): int
    {
        $complaints = Complaint::query()
            ->needsAttention()
            ->orderBy('response_due_on')
            ->orderBy('received_at')
            ->get();

        if ($complaints->isEmpty()) {
            $this->info('No complaints need attention.');

            return self::SUCCESS;
        }

        $recipient = trim((string) config('legal.complaints.alert_email'));

        if ($recipient === '') {
            $this->error('legal.complaints.alert_email is not configured.');

            return self::FAILURE;
        }

        Mail::to($recipient)->send(new ComplaintDeadlineAlertMail($complaints));

        $this->info("Sent a reminder about {$complaints->count()} complaint(s) to {$recipient}.");

        return self::SUCCESS;
    }
}
