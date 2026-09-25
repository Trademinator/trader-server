# Mailgun transactional email

Complete command reference: [CLI.md](CLI.md).

Trademinator uses Laravel's Mailgun HTTP API transport for outgoing mail, including registration verification and password reset messages. The Symfony Mailgun Mailer and HTTP client packages, the `mailgun` mailer, and the `services.mailgun` config are included in this repository. No extra Composer package is required.

## Configure

1. Add a sending domain to Mailgun and complete its DNS verification. Use the Mailgun API key authorized to send for that domain. A Mailgun sandbox domain can only send to authorized recipients.
2. Set the following values in the application's real `.env` (or deployment secrets), using your own domain and key:

   ```dotenv
   APP_URL=https://your-trademinator-domain.example
   MAIL_MAILER=mailgun
   MAILGUN_DOMAIN=mg.your-domain.example
   MAILGUN_SECRET=your-mailgun-sending-api-key
   MAILGUN_ENDPOINT=api.mailgun.net
   MAIL_FROM_ADDRESS=notifications@mg.your-domain.example
   MAIL_FROM_NAME="Trademinator"
   ```

   For a domain hosted in Mailgun's EU region, use `MAILGUN_ENDPOINT=api.eu.mailgun.net`. The endpoint is a **host name**, with no `https://` prefix or `/v3` suffix. The sender address should belong to the sending domain configured in Mailgun. Use the domain's Mailgun HTTP API key, not its SMTP password. Never commit your real `.env` or API key.
3. If Laravel config has been cached, rebuild it after changing environment values and restart any long-running workers:

   ```bash
   php artisan config:clear
   php artisan config:cache
   php artisan queue:restart
   ```

   Apply the same mail settings on every application node. The `APP_URL` should be the public HTTPS address so account verification and password reset links point to the correct site.

## Verify

```bash
php artisan trademinator:mailgun-check
php artisan trademinator:mailgun-check --to=you@your-domain.example
```

The first command validates that the application has loaded the required configuration and sends nothing. The second submits a test email through the configured Mailgun transport. Successful submission is not proof of inbox delivery; inspect Mailgun delivery events and your receiving mailbox. The command does not display the API key. If the domain is still a Mailgun sandbox, authorize the recipient first.

The `.env.example` intentionally retains `MAIL_MAILER=log` for a fresh local installation. Switch to `mailgun` in the deployed `.env` to enable real transactional email. Mailgun does not require a queue worker for the existing synchronous Laravel account notifications.
