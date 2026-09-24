# Trademinator M1 market-data patch

Base: Trademinator/trader-server main at 831f7f8. This archive contains changed/new files only; extract at the repository root. Back up local edits first and review differences before overwriting files.

Implemented: numeric 0..1 candle-quality score (true-flat ratio, longest true-flat run, zero-volume ratio, unique-close ratio and median range measured in tick sizes); select the shortest requested, exchange-supported timeframe that passes a threshold and minimum sample; fetch boundary handling; recent-candle upserts with explicit UUID; sequential DB candles; original CCXT numeric string values rather than forced 8-digit rounding; lowercase 1y conversion; CCXT rate limiter enabled.

Example:

    php artisan trademinator:select-candle-period coinbase BTC/USD --tick-size=0.01 --threshold=0.7 --minimum=50 --from='7 days ago'

A null selection means no period met the threshold; do not silently select the longest. Tick size must come from the exchange's market metadata and should be supplied explicitly. Timeframes are sampled over the same interval.

Run composer install, then php artisan test --filter=CandleQuality, followed by the existing suite. This runtime does not have PHP/Composer, so those checks could not run here. This is a focused M1 increment, not a completed queued backfill/missing-range scheduler, durable quality history, exchange retry policy, or an end-to-end trading engine. Existing migrations and indicator tests remain separate stabilization work.
