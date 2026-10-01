<?php

namespace App\Domain\MarketData;

use App\Models\Exchange;
use App\Models\ExchangeCredential;
use App\Models\User;
use ccxt\BaseError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Resolve credentials at execution time; queues and rotation cursors contain no secrets. */
class ExchangeCredentials
{
    public const FIELDS = [
        'apiKey' => 'API key / key name',
        'secret' => 'API secret / signing key',
        'password' => 'API passphrase / password',
        'uid' => 'API user ID',
        'login' => 'API login',
        'accountId' => 'API account ID',
        'token' => 'API token',
    ];

    /** @return list<string> */
    public function requiredFields(Exchange $exchange): array
    {
        if (! in_array($exchange->class, \ccxt\Exchange::$exchanges, true)) {
            return [];
        }
        $class = '\\ccxt\\'.$exchange->class;
        $adapter = new $class;

        return array_keys(array_filter($adapter->requiredCredentials));
    }

    public function shared(?string $exchangeId = null): Builder
    {
        return ExchangeCredential::query()->where('is_shared', true)
            ->whereIn('user_id', User::ownerIds())
            ->whereHas('user', fn (Builder $query) => $query->whereNull('suspended_at'))
            ->when($exchangeId !== null, fn (Builder $query) => $query->where('exchange_id', $exchangeId));
    }

    public function resolve(Exchange $exchange, ?User $user = null, ?string $symbol = null, bool $rotate = true): ?ExchangeCredential
    {
        if (! $exchange->exists || $exchange->getKey() === null) {
            return null;
        }
        if ($user !== null) {
            if ($user->suspended_at !== null) {
                throw new RuntimeException('The account is suspended.');
            }
            $personal = $user->exchangeCredentials()->where('exchange_id', $exchange->exchange_id)->first();
            if ($personal !== null) {
                return $personal;
            }
        } elseif ($symbol !== null) {
            $subscribers = ExchangeCredential::query()->where('exchange_id', $exchange->exchange_id)
                ->whereHas('user', fn (Builder $query) => $query->whereNull('suspended_at')
                    ->whereHas('subscriptions', fn (Builder $query) => $query->where('active', true)
                        ->whereHas('market', fn (Builder $query) => $query
                            ->where('exchange_id', $exchange->exchange_id)->where('symbol', $symbol))))
                ->where(fn (Builder $query) => $query->where('is_shared', false)
                    ->orWhereNotIn('user_id', User::ownerIds()));
            $personal = $this->next($subscribers, 'market:'.$exchange->exchange_id.':'.$symbol, $rotate);
            if ($personal !== null) {
                return $personal;
            }
        }

        return $this->next($this->shared($exchange->exchange_id), 'shared:'.$exchange->exchange_id, $rotate);
    }

    /** @return array<string, mixed> */
    public function settings(Exchange $exchange, ?User $user = null, ?string $symbol = null, bool $rotate = true): array
    {
        $settings = json_decode($exchange->config ?: '{}', true) ?: [];
        $credential = $this->resolve($exchange, $user, $symbol, $rotate);
        if ($credential !== null) {
            $settings = array_diff_key($settings, array_fill_keys([
                ...array_keys(self::FIELDS), 'privateKey', 'walletAddress', 'twofa',
            ], true));
            try {
                $settings = array_replace($settings, array_intersect_key($credential->credentials, self::FIELDS));
            } catch (Throwable) {
                throw new RuntimeException('Exchange credentials could not be decrypted. Check the application encryption key or replace the saved credentials.');
            }
        }

        return $settings;
    }

    private function next(Builder $query, string $scope, bool $rotate): ?ExchangeCredential
    {
        if (! $rotate) {
            return $query->orderBy('exchange_credential_id')->first();
        }
        if (! (clone $query)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($query, $scope): ?ExchangeCredential {
            DB::table('exchange_credential_rotations')->insertOrIgnore(['scope' => $scope]);
            $cursor = DB::table('exchange_credential_rotations')->where('scope', $scope)->lockForUpdate()->first();
            $next = (clone $query)->when($cursor->last_credential_id !== null,
                fn (Builder $query) => $query->where('exchange_credential_id', '>', $cursor->last_credential_id))
                ->orderBy('exchange_credential_id')->first();
            $next ??= (clone $query)->orderBy('exchange_credential_id')->first();
            DB::table('exchange_credential_rotations')->where('scope', $scope)
                ->update(['last_credential_id' => $next?->exchange_credential_id]);

            return $next;
        }, attempts: 5);
    }

    /** Discard raw exchange responses, signed URLs and the previous exception before logging or queue persistence. */
    public static function safeFailure(#[\SensitiveParameter] Throwable $error): Throwable
    {
        $message = 'Exchange market-data request failed. '.MarketCatalogException::fromFailure($error)->getMessage();
        if ($error instanceof BaseError) {
            $class = $error::class;

            return new $class($message);
        }

        return new RuntimeException($message);
    }
}
