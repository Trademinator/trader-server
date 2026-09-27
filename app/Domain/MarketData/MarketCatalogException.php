<?php

namespace App\Domain\MarketData;

use ccxt\AuthenticationError;
use ccxt\DDoSProtection;
use ccxt\NetworkError;
use ccxt\NotSupported;
use ccxt\PermissionDenied;
use ccxt\RateLimitExceeded;
use ccxt\RequestTimeout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class MarketCatalogException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 503)
    {
        parent::__construct($message);
    }

    public static function fromFailure(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        return match (true) {
            $exception instanceof PermissionDenied => new self('access_denied',
                'The exchange denied this server access. Check API permissions and the exchange’s regional restrictions.', 503),
            $exception instanceof AuthenticationError => new self('authentication_required',
                'This exchange requires valid API credentials to list pairs. Ask the administrator to check the exchange configuration.', 422),
            $exception instanceof RateLimitExceeded, $exception instanceof DDoSProtection => new self('rate_limited',
                'The exchange is limiting requests from this server. Please wait and try again.', 503),
            $exception instanceof RequestTimeout => new self('timeout',
                'The exchange did not respond in time. Please try again.', 504),
            $exception instanceof NetworkError => new self('connection_failed',
                'The server could not connect to the exchange. Please retry; if this continues, ask the administrator to check connectivity and regional restrictions.', 503),
            $exception instanceof NotSupported => new self('unsupported',
                'This exchange adapter cannot list the markets required by this page.', 422),
            default => new self('catalogue_failed',
                'The server could not load this exchange’s pairs. Please retry or give the reference below to the administrator.', 503),
        };
    }

    /** Log diagnostics without API keys, signed URLs or raw exchange responses. */
    public static function reportFailure(Throwable $exception, string $exchange): array
    {
        $failure = self::fromFailure($exception);
        $reference = (string) Str::uuid();
        Log::warning('Market catalogue request failed.', [
            'reference' => $reference, 'exchange' => $exchange,
            'reason' => $failure->reason, 'exception_class' => $exception::class,
        ]);

        return ['message' => $failure->getMessage(), 'code' => $failure->reason, 'reference' => $reference];
    }
}
