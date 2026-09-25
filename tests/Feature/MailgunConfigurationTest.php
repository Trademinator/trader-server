<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

it('blocks test sends until Mailgun has a mailer, key, domain, endpoint and sender', function () {
    config([
        'mail.default' => 'log',
        'mail.from.address' => 'hello@example.com',
        'services.mailgun.domain' => null,
        'services.mailgun.secret' => null,
        'services.mailgun.endpoint' => 'api.mailgun.net',
    ]);
    Mail::shouldReceive('raw')->never();

    expect(Artisan::call('trademinator:mailgun-check', ['--to' => 'test@example.test']))->toBe(1)
        ->and(Artisan::output())->toContain('MAIL_MAILER=mailgun')
        ->toContain('MAILGUN_DOMAIN')
        ->toContain('MAILGUN_SECRET')
        ->toContain('MAIL_FROM_ADDRESS');
});

it('checks settings without sending and sends only when a valid recipient is requested', function () {
    config([
        'mail.default' => 'mailgun',
        'mail.from.address' => 'notifications@mg.example.test',
        'services.mailgun.domain' => 'mg.example.test',
        'services.mailgun.secret' => 'test-only-secret',
        'services.mailgun.endpoint' => 'api.mailgun.net',
    ]);

    Mail::shouldReceive('raw')->once();
    expect(Artisan::call('trademinator:mailgun-check'))->toBe(0);
    expect(Artisan::call('trademinator:mailgun-check', ['--to' => 'not-an-email']))->toBe(1);
    expect(Artisan::call('trademinator:mailgun-check', ['--to' => 'recipient@example.test']))->toBe(0);
});
