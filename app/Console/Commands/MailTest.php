<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Checks the SMTP set-up end to end by sending one message synchronously —
 * no queue in the way, so a wrong password or blocked port shows up here,
 * not later in failed_jobs.
 */
class MailTest extends Command
{
    protected $signature = 'mail:test {address : Where to send the test message}';

    protected $description = 'Send a test email with the current mail settings';

    public function handle(): int
    {
        $address = (string) $this->argument('address');

        if (Validator::make(['address' => $address], ['address' => 'email'])->fails()) {
            $this->error("\"{$address}\" is not an email address.");

            return self::INVALID;
        }

        $mailer = (string) config('mail.default');
        $this->table(['Setting', 'Value'], [
            ['Mailer', $mailer],
            ['Host', $mailer === 'smtp' ? config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port') : '—'],
            ['Scheme', (string) (config('mail.mailers.smtp.scheme') ?? 'auto (STARTTLS when offered)')],
            ['Username', config('mail.mailers.smtp.username') ? 'set' : 'not set'],
            ['From', config('mail.from.name').' <'.config('mail.from.address').'>'],
        ]);

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("The \"{$mailer}\" mailer does not deliver email. Set MAIL_MAILER=smtp to send for real.");
        }

        // A MAIL_FROM_ADDRESS that is not an address (a bare domain, say) fails
        // deep inside the transport, and for queued mail that surfaces only as a
        // failed job. Catch it here, where the message can say what to fix.
        $from = (string) config('mail.from.address');
        if (Validator::make(['from' => $from], ['from' => 'email'])->fails()) {
            $this->error("MAIL_FROM_ADDRESS is \"{$from}\", which is not an email address. It needs a mailbox name, for example no-reply@{$from}.");

            return self::INVALID;
        }

        try {
            Mail::raw(
                'This is a test message from '.config('app.name').' ('.config('app.url').'). If you can read it, outbound email works.',
                fn (Message $message) => $message->to($address)->subject(config('app.name').' mail test'),
            );
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$address}. Check the inbox (and the spam folder).");

        return self::SUCCESS;
    }
}
