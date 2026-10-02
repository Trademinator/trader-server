# Exchange credential security

Trademinator exchange credentials are for authenticated **public market-data collection only**.

## Create a dedicated exchange key

Create a separate API credential specifically for Trademinator. Do not reuse a key that is also used by a trading bot, wallet, withdrawal tool, or another application.

At the exchange, grant only the minimum permissions required to read public market data. Where the exchange offers separate switches or scopes, keep these disabled:

- trading and order placement;
- transfers between accounts or portfolios;
- withdrawals;
- wallet/private-key access;
- any permission that can move funds or create financial exposure.

Do not enter an exchange account password, wallet private key, recovery phrase, seed phrase, or other custody secret into Trademinator.

## Trademinator cannot verify exchange-side permissions

The Server can validate which credential fields a CCXT adapter requires, but there is no portable CCXT mechanism that proves how the key was configured at the exchange.

The **READ-ONLY KEYS ONLY** confirmation in Settings is therefore a user safety acknowledgement, not a technical permission check. A key with trading or withdrawal permission can still be pasted into the form if the exchange issued it that way.

If you are unsure about an existing key, revoke it at the exchange and create a new dedicated read-only key.

## Use exchange-side restrictions

When supported by the exchange:

- restrict the key to the server's known outbound IP addresses;
- enable only the minimum read scopes;
- disable trading, transfer and withdrawal permissions;
- use an expiry or rotation policy if the exchange supports one.

IP allowlisting is defense in depth, not a replacement for read-only permissions.

## Server-side boundary

Trademinator stores exchange credentials encrypted and does not display them after saving. The current Server market-data paths do not intentionally place orders, trade, transfer assets, or request withdrawals.

Credential permissions must still be restricted at the exchange because exchange APIs and CCXT adapters differ, and the Server cannot reliably inspect those permissions generically.

## Shared owner credentials

A server owner may choose to share credentials for collection. Shared credentials remain hidden from other users, but they can be used by shared market-data feeds.

Use an especially restricted dedicated key for shared access. Never share a credential with trading, transfer, withdrawal, custody, or wallet permissions.
