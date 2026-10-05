# Paginated OHLCV synchronization

Complete command reference: [CLI.md](CLI.md).

`trademinator:sync-ohlcv` now divides the requested time range into bounded pages. The default `--page-size=90` both limits the number of candle periods in a page and caps the CCXT `fetch_ohlcv` request limit. Use a smaller value (minimum 10) for exchanges with stricter OHLCV limits.

```bash
php artisan trademinator:sync-ohlcv kraken BTC/USD 1m --from='30 days ago' --page-size=50
php artisan trademinator:sync-ohlcv kraken BTC/USD 1m --from='30 days ago' --page-size=50 --queue
```

Direct execution walks each page in sequence and reports `pages`, `fetched`, `repaired`, `reconstructed`, and `missing_ranges`. With `--repair-gaps`, repairs count newly inserted timestamps in that page, including the initial fetch; reconstructions are a subset. Fetched counts can include existing neighbours. Repair output also includes bounded `reconstruction_details` with per-timeframe checks and rejection reasons, plus an omitted-detail count. A queued run enqueues the first page; that job schedules the next page only when it succeeds. A failing page is retried up to five times with backoff, then the sequence stops. Failed jobs can be inspected with `php artisan queue:failed`. See [CLI.md](CLI.md#trademinatorsync-ohlcv) for isolated reconstruction, evidence requirements and retry bookkeeping.

Adjacent pages overlap a few candle periods so a missing interval at a page boundary can be inspected. The overlap can make aggregate received and gap counts include repeats, but persistence upserts by exchange, symbol, period and timestamp. `--incremental` skips old pages and revisits the most recent stored candle. `--to=now` is evaluated when the command starts.

Use `QUEUE_CONNECTION=database` or another persistent Laravel queue connection and keep `php artisan queue:work` running. The `sync` queue driver is rejected for `--queue`, because it would run every page inline. Each page must still finish before the worker timeout; adjust worker settings if your exchange is slow.

The exchange remains the authoritative source. Some exchanges return less history than requested or impose tighter limits; lower `--page-size` or inspect the exchange's CCXT documentation when a request fails.
