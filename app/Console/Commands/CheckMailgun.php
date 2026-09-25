<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class CheckMailgun extends Command
{
    protected $signature = 'trademinator:mailgun-check {--to= : Send a test email to this address after validating configuration}';

    protected $description = 'Check Mailgun settings and optionally send a test message';

    public function handle(): int
    {
        $issues = [];
        if (config('mail.default') !== 'mailgun') {
            $issues[] = 'Set MAIL_MAILER=mailgun.';
        }
        if (config('mail.mailers.mailgun.transport') !== 'mailgun') {
            $issues[] = 'The Mailgun mailer is not configured in config/mail.php.';
        }
        if (! filled(config('services.mailgun.domain'))) {
            $issues[] = 'Set MAILGUN_DOMAIN to the verified sending domain.';
        }
        if (! filled(config('services.mailgun.secret'))) {
            $issues[] = 'Set MAILGUN_SECRET to your Mailgun sending API key.';
        }
        if (! filled(config('services.mailgun.endpoint'))) {
            $issues[] = 'Set MAILGUN_ENDPOINT to the API host for your domain region.';
        }
        $sender = config('mail.from.address');
        if (! is_string($sender) || ! filter_var($sender, FILTER_VALIDATE_EMAIL) || $sender === 'hello@example.com') {
            $issues[] = 'Set MAIL_FROM_ADDRESS to an address for your verified sending domain.';
        }
        foreach ($issues as $issue) {
            $this->error($issue);
        }
        if ($issues !== []) {
            return self::FAILURE;
        }

        $recipient = $this->option('to');
        if ($recipient === null) {
            $this->info('Mailgun configuration is present. Use --to=you@example.com to test delivery.');

            return self::SUCCESS;
        }
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error('--to must be a valid email address.');

            return self::FAILURE;
        }

        try {
            Mail::raw('This is a test of Trademinator transactional email via Mailgun.',
                function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('Trademinator Mailgun test');
                });
        } catch (Throwable) {
            // A transport exception may contain sensitive request information.
            $this->error('The Mailgun test could not be sent. Check the API key, verified domain, endpoint, and Mailgun delivery logs.');

            return self::FAILURE;
        }
        $this->info('Test message submitted to Mailgun for '.$recipient.'.');

        return self::SUCCESS;
    }
}
