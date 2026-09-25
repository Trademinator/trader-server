# Manage CCXT exchanges

The `exchanges` table contains the exchange records that Trademinator can use. The CCXT exchange ID (`class`) is the identifier passed to the market-data commands. `trademinator:exchange` manages those records after the exchange seeder has run.

```bash
php artisan trademinator:exchange list --search=kraken
php artisan trademinator:exchange edit kraken --name='Kraken Spot'
php artisan trademinator:exchange edit kraken --config='{"timeout":30000}'
php artisan trademinator:exchange delete kraken
```

The seeder adds every CCXT exchange when the table is empty. `add` refuses an existing ID: use `edit` for a seeded exchange. To add an exchange again after deleting its record, run `php artisan trademinator:exchange add kraken --name=Kraken`. The command validates new IDs against the installed CCXT version.

`--name` changes only the display name. `--config` replaces the complete settings object; use `{}` to clear it. Alternatively, pass `--config-file=/path/to/settings.json` to read a JSON object from a file. The settings are passed to CCXT when the exchange is used. `enableRateLimit` is always set to true by Trademinator. The command does not print the settings while listing or modifying exchanges.

Deletion asks for confirmation. `--force` skips that question for an intentional noninteractive deletion. It deletes the exchange configuration and inactive market/feed records only after all users unsubscribe. Historical candle rows remain. Editing the CCXT ID itself is unsupported because existing data refers to that ID.

Exchange `config` is currently stored as unencrypted JSON. Use `{}` for public market data. Do not put trading API secrets there until encrypted credential storage is implemented.

Run the focused tests with:

```bash
php artisan test --filter=ManageExchangesTest
```
