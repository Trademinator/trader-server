Trademinator Laravel 13 test fixes

Contents:
- tests/TestCase.php
  Disables Vite during PHP feature tests via withoutVite().

- tests/Feature/Auth/EmailVerificationTest.php
  Uses the model primary key through getKey() instead of assuming $user->id.

- tests/Feature/Auth/RegistrationTest.php
  Uses a password that satisfies the current application password policy.

- app/Http/Controllers/Settings/ProfileController.php
  Uses Rule::unique(...)->ignore($user) so Laravel respects the custom user_id primary key.

Installation:
1. Back up or commit your current changes.
2. Extract this archive from the root of trader-server.
3. Run:
   php artisan optimize:clear
   php artisan test
