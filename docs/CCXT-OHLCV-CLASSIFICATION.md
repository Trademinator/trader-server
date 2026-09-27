# CCXT OHLCV access classification — M3 R3

Reviewed **2026-09-26**, using every synchronous PHP adapter in the supplied CCXT **4.5.57** lockfile (110 adapters; reference `9ebdddc8c0c0382836cd124d27fc1466fe87801b`).

**Effective access: 89 public, 4 authentication required, 17 unknown/unsupported.** “Markets” includes fresh-client discovery; “Candles” describes the candle route itself. “Effective” combines them and requires advertised native OHLCV support. A public candle route can therefore have an authentication-required effective result (Alpaca). Unknown does not mean that an exchange has no trading pairs.

The spot selector offers **73 public + 2 authentication-required adapters** when their database entries exist. All 17 unknown entries here lack advertised supported native OHLCV. Indodax has an unadvertised public implementation; it is still excluded. Adapters without spot support are also excluded regardless of access state.

This is source-based classification for default unauthenticated paths, not a live availability or regional access test. Optional private branches may be enabled by configuration/credentials. Detailed method/line references, endpoint signing observations and branch notes for **every row** are in `resources/data/ccxt-access-reviews.json`. Source fingerprints bind those reviews to the installed files. See [refresh and review procedure](M3-R3-ACCESS.md).

| CCXT ID | Markets | Candles | Effective OHLCV | Spot selector | OHLCV implementation |
| --- | --- | --- | --- | --- | --- |
| `aftermath` | Public | Public | **Public** | No: no spot support | `php/aftermath.php:560` |
| `alpaca` | Auth required | Public | **Auth required** | Yes | `php/alpaca.php:712` |
| `apex` | Public | Public | **Public** | No: no spot support | `php/apex.php:804` |
| `arkham` | Public | Public | **Public** | Yes | `php/arkham.php:754` |
| `ascendex` | Public | Public | **Public** | Yes | `php/ascendex.php:1273` |
| `aster` | Public | Public | **Public** | No: no spot support | `php/aster.php:991` |
| `backpack` | Public | Public | **Public** | Yes | `php/backpack.php:928` |
| `bequant` | Public | Public | **Public** | Yes | `php/hitbtc.php:1814` |
| `bigone` | Public | Public | **Public** | Yes | `php/bigone.php:1321` |
| `binance` | Public | Public | **Public** | Yes | `php/binance.php:4708` |
| `binancecoinm` | Public | Public | **Public** | No: no spot support | `php/binance.php:4708` |
| `binanceus` | Public | Public | **Public** | Yes | `php/binance.php:4708` |
| `binanceusdm` | Public | Public | **Public** | No: no spot support | `php/binance.php:4708` |
| `bingx` | Public | Public | **Public** | Yes | `php/bingx.php:1146` |
| `bit2c` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `bitbank` | Public | Public | **Public** | Yes | `php/bitbank.php:585` |
| `bitbns` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `bitfinex` | Public | Public | **Public** | Yes | `php/bitfinex.php:1507` |
| `bitflyer` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `bitget` | Public | Public | **Public** | Yes | `php/bitget.php:4209` |
| `bithumb` | Public | Public | **Public** | Yes | `php/bithumb.php:668` |
| `bitmart` | Public | Public | **Public** | Yes | `php/bitmart.php:2092` |
| `bitmex` | Public | Public | **Public** | Yes | `php/bitmex.php:1636` |
| `bitopro` | Public | Public | **Public** | Yes | `php/bitopro.php:866` |
| `bitrue` | Public | Public | **Public** | Yes | `php/bitrue.php:1432` |
| `bitso` | Public | Public | **Public** | Yes | `php/bitso.php:752` |
| `bitstamp` | Public | Public | **Public** | Yes | `php/bitstamp.php:1286` |
| `bitteam` | Public | Public | **Public** | Yes | `php/bitteam.php:743` |
| `bittrade` | Public | Public | **Public** | Yes | `php/bittrade.php:1009` |
| `bitvavo` | Public | Public | **Public** | Yes | `php/bitvavo.php:1120` |
| `blockchaincom` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `blofin` | Public | Public | **Public** | No: no spot support | `php/blofin.php:940` |
| `btcbox` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `btcmarkets` | Public | Public | **Public** | Yes | `php/btcmarkets.php:646` |
| `btcturk` | Public | Public | **Public** | Yes | `php/btcturk.php:671` |
| `bullish` | Public | Public | **Public** | Yes | `php/bullish.php:1316` |
| `bybit` | Public | Public | **Public** | Yes | `php/bybit.php:2636` |
| `bybiteu` | Public | Public | **Public** | Yes | `php/bybit.php:2636` |
| `bydfi` | Public | Public | **Public** | No: no spot support | `php/bydfi.php:810` |
| `cex` | Public | Public | **Public** | Yes | `php/cex.php:749` |
| `coinbase` | Public | Public | **Public** | Yes | `php/coinbase.php:3793` |
| `coinbaseadvanced` | Public | Public | **Public** | Yes | `php/coinbase.php:3793` |
| `coinbaseexchange` | Public | Public | **Public** | Yes | `php/coinbaseexchange.php:1194` |
| `coinbaseinternational` | Public | Public | **Public** | Yes | `php/coinbaseinternational.php:427` |
| `coincheck` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `coinex` | Public | Public | **Public** | Yes | `php/coinex.php:1638` |
| `coinmate` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `coinmetro` | Public | Public | **Public** | Yes | `php/coinmetro.php:594` |
| `coinone` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `coinsph` | Public | Public | **Public** | Yes | `php/coinsph.php:1055` |
| `coinspot` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `cryptocom` | Public | Public | **Public** | Yes | `php/cryptocom.php:1079` |
| `cryptomus` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `deepcoin` | Public | Public | **Public** | Yes | `php/deepcoin.php:624` |
| `delta` | Public | Public | **Public** | Yes | `php/delta.php:1606` |
| `deribit` | Public | Public | **Public** | Yes | `php/deribit.php:1422` |
| `derive` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `digifinex` | Public | Public | **Public** | Yes | `php/digifinex.php:1521` |
| `dydx` | Public | Public | **Public** | No: no spot support | `php/dydx.php:723` |
| `exmo` | Public | Public | **Public** | Yes | `php/exmo.php:955` |
| `extended` | Public | Public | **Public** | No: no spot support | `php/extended.php:1202` |
| `fmfwio` | Public | Public | **Public** | Yes | `php/hitbtc.php:1814` |
| `foxbit` | Public | Public | **Public** | Yes | `php/foxbit.php:754` |
| `gate` | Public | Public | **Public** | Yes | `php/gate.php:3326` |
| `gemini` | Public | Public | **Public** | Yes | `php/gemini.php:2026` |
| `grvt` | Public | Public | **Public** | No: no spot support | `php/grvt.php:1108` |
| `hashkey` | Public | Public | **Public** | Yes | `php/hashkey.php:1529` |
| `hibachi` | Public | Public | **Public** | No: no spot support | `php/hibachi.php:1453` |
| `hitbtc` | Public | Public | **Public** | Yes | `php/hitbtc.php:1814` |
| `hollaex` | Public | Public | **Public** | Yes | `php/hollaex.php:902` |
| `htx` | Public | Public | **Public** | Yes | `php/htx.php:3158` |
| `huobi` | Public | Public | **Public** | Yes | `php/htx.php:3158` |
| `hyperliquid` | Public | Public | **Public** | Yes | `php/hyperliquid.php:1433` |
| `independentreserve` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `indodax` | Public | Public | **Unknown** | No: OHLCV unsupported | `php/indodax.php:694` |
| `kraken` | Public | Public | **Public** | Yes | `php/kraken.php:1167` |
| `krakenfutures` | Public | Public | **Public** | No: no spot support | `php/krakenfutures.php:708` |
| `kucoin` | Public | Public | **Public** | Yes | `php/kucoin.php:3222` |
| `kucoinfutures` | Public | Public | **Public** | No: no spot support | `php/kucoin.php:3222` |
| `latoken` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `lbank` | Public | Public | **Public** | Yes | `php/lbank.php:1161` |
| `lighter` | Public | Public | **Public** | No: no spot support | `php/lighter.php:1587` |
| `luno` | Public | Auth required | **Auth required** | Yes | `php/luno.php:970` |
| `mercado` | Public | Public | **Public** | Yes | `php/mercado.php:842` |
| `mexc` | Public | Public | **Public** | Yes | `php/mexc.php:1800` |
| `modetrade` | Public | Auth required | **Auth required** | No: no spot support | `php/modetrade.php:1251` |
| `myokx` | Public | Public | **Public** | Yes | `php/okx.php:2607` |
| `ndax` | Public | Public | **Public** | Yes | `php/ndax.php:867` |
| `novadax` | Public | Public | **Public** | Yes | `php/novadax.php:725` |
| `okx` | Public | Public | **Public** | Yes | `php/okx.php:2607` |
| `okxus` | Public | Public | **Public** | Yes | `php/okx.php:2607` |
| `onetrading` | Public | Public | **Public** | Yes | `php/onetrading.php:1033` |
| `p2b` | Public | Public | **Public** | Yes | `php/p2b.php:751` |
| `pacifica` | Public | Public | **Public** | No: no spot support | `php/pacifica.php:972` |
| `paradex` | Public | Public | **Public** | No: no spot support | `php/paradex.php:798` |
| `paymium` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `phemex` | Public | Public | **Public** | Yes | `php/phemex.php:1367` |
| `poloniex` | Public | Public | **Public** | Yes | `php/poloniex.php:623` |
| `tokocrypto` | Public | Public | **Public** | Yes | `php/tokocrypto.php:1392` |
| `toobit` | Public | Public | **Public** | Yes | `php/toobit.php:1077` |
| `upbit` | Public | Public | **Public** | Yes | `php/upbit.php:1086` |
| `wavesexchange` | Public | Public | **Public** | Yes | `php/wavesexchange.php:1061` |
| `weex` | Public | Public | **Public** | Yes | `php/weex.php:1320` |
| `whitebit` | Public | Public | **Public** | Yes | `php/whitebit.php:1805` |
| `woo` | Public | Public | **Public** | Yes | `php/woo.php:2139` |
| `woofipro` | Public | Auth required | **Auth required** | No: no spot support | `php/woofipro.php:1288` |
| `xt` | Public | Public | **Public** | Yes | `php/xt.php:1390` |
| `yobit` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `zaif` | Public | Unknown | **Unknown** | No: OHLCV unsupported | `php/Exchange.php:5447` |
| `zebpay` | Public | Public | **Public** | Yes | `php/zebpay.php:677` |

## Authentication-required paths

| Adapter | Mandatory authenticated step | Spot support |
| --- | --- | --- |
| Alpaca | `fetch_markets` → `traderPrivateGetV2Assets`; `fetch_ohlcv` first loads markets even though crypto bars are on a public route. | Yes |
| Luno | `fetch_ohlcv` → `exchangePrivateGetCandles`. | Yes |
| ModeTrade | `fetch_ohlcv` → `v1PrivateGetKline`. | No |
| WOOFi Pro | `fetch_ohlcv` → `v1PrivateGetKline`. | No |

## Unknown/unsupported in this lockfile

`bit2c`, `bitbns`, `bitflyer`, `blockchaincom`, `btcbox`, `coincheck`, `coinmate`, `coinone`, `coinspot`, `cryptomus`, `derive`, `independentreserve`, `indodax`, `latoken`, `paymium`, `yobit`, `zaif`.

These are retained as explicit unknown/unsupported records, rather than being described as exchanges that necessarily need keys. For future versions, unknown also covers new adapters, changed source without a matching review, and inspection failures. The refresh report distinguishes these reasons.

## Source-review notes

- **`aftermath`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`alpaca`:** fetch_markets calls traderPrivateGetV2Assets and requires apiKey/secret. Crypto bars use marketPublicGetV1beta3CryptoLocBars, but fetch_ohlcv first calls load_markets. Effective fresh-client access therefore requires authentication.
- **`apex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`arkham`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`ascendex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`aster`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`backpack`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bequant`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bigone`:** Market and candle routes are public. Optional webExchange asset discovery is unsigned and configured to mute web-API failure.
- **`binance`:** Spot exchangeInfo and klines are public. Private margin/currency enrichment is conditional on credentials; Trademinator spot discovery disables that enrichment and restricts market types.
- **`binancecoinm`:** Spot exchangeInfo and klines are public. Private margin/currency enrichment is conditional on credentials; Trademinator spot discovery disables that enrichment and restricts market types. Inherited implementation; capabilities are taken from this adapter, not its parent.
- **`binanceus`:** Spot exchangeInfo and klines are public. Private margin/currency enrichment is conditional on credentials; Trademinator spot discovery disables that enrichment and restricts market types. Inherited implementation; capabilities are taken from this adapter, not its parent.
- **`binanceusdm`:** Spot exchangeInfo and klines are public. Private margin/currency enrichment is conditional on credentials; Trademinator spot discovery disables that enrichment and restricts market types. Inherited implementation; capabilities are taken from this adapter, not its parent.
- **`bingx`:** Market discovery and candle routes are public. Private currency enrichment is skipped when check_required_credentials(false) fails; stored credentials can enable additional authenticated requests.
- **`bit2c`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`bitbank`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitbns`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`bitfinex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitflyer`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`bitget`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bithumb`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitmart`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitmex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitopro`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitrue`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitso`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitstamp`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bitteam`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bittrade`:** Default fetchMarketsMethod is publicGetCommonSymbols. Market history kline and currency endpoints are public; configuring a different method is outside this review.
- **`bitvavo`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`blockchaincom`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`blofin`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`btcbox`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`btcmarkets`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`btcturk`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bullish`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`bybit`:** Market instruments and candles are public by default (usePrivateInstrumentsInfo=false). Private currency discovery returns early without credentials. Opting into private instruments changes access requirements.
- **`bybiteu`:** Market instruments and candles are public by default (usePrivateInstrumentsInfo=false). Private currency discovery returns early without credentials. Opting into private instruments changes access requirements. Inherited implementation; capabilities are taken from this adapter, not its parent.
- **`bydfi`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`cex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`coinbase`:** Default fetchMarketsV3 and fetchOHLCV use public brokerage/market routes (usePrivate=false). Private fee discovery is conditional on credentials; opting into private routes changes access requirements.
- **`coinbaseadvanced`:** Default fetchMarketsV3 and fetchOHLCV use public brokerage/market routes (usePrivate=false). Private fee discovery is conditional on credentials; opting into private routes changes access requirements. Inherited implementation; capabilities are taken from this adapter, not its parent.
- **`coinbaseexchange`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`coinbaseinternational`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`coincheck`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`coinex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`coinmate`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`coinmetro`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`coinone`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`coinsph`:** Market discovery and candle routes are public. Private currency enrichment is skipped when check_required_credentials(false) fails; stored credentials can enable additional authenticated requests.
- **`coinspot`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`cryptocom`:** Market discovery and candle routes are public. Private currency enrichment is skipped when check_required_credentials(false) fails; stored credentials can enable additional authenticated requests.
- **`cryptomus`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`deepcoin`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`delta`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`deribit`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`derive`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`digifinex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`dydx`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`exmo`:** Pair settings and candles are public. Margin pair enrichment is conditional on credentials.
- **`extended`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`fmfwio`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`foxbit`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`gate`:** Market/candle routes are public. load_unified_status is conditional on credentials; public default discovery does not require an account.
- **`gemini`:** Default fetchMarketsMethod is fetch_markets_from_api (public symbols/details); optional web metadata is unsigned. Candles are public.
- **`grvt`:** Market instruments, currencies and klines are public. Market loading signs in only when apiKey or privateKey is present. No spot support advertised.
- **`hashkey`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`hibachi`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`hitbtc`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`hollaex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`htx`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`huobi`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`hyperliquid`:** Markets, currencies and candleSnapshot use public info requests. Wallet/builder initialization in fetch_currencies is guarded by check_required_credentials(false).
- **`independentreserve`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`indodax`:** An unsigned TradingView history implementation exists, but has.fetchOHLCV is not advertised. Effective classification stays unknown/unsupported; excluded until capability and operational behavior are reviewed.
- **`kraken`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`krakenfutures`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`kucoin`:** Default spot/contract market and candle paths are public. Margin enrichment and UTA account-mode detection are guarded by credentials; private account setup is not needed on the default unauthenticated path.
- **`kucoinfutures`:** Default spot/contract market and candle paths are public. Margin enrichment and UTA account-mode detection are guarded by credentials; private account setup is not needed on the default unauthenticated path. Inherited implementation; capabilities are taken from this adapter, not its parent.
- **`latoken`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`lbank`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`lighter`:** Candles, order books and assets are public. Private signer-library/account initialization in fetch_currencies is guarded by credentials. No spot support advertised.
- **`luno`:** Market discovery uses exchangeGetMarkets. fetch_ohlcv uses exchangePrivateGetCandles; sign requires apiKey/secret. Optional private currency discovery is skipped without credentials.
- **`mercado`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`mexc`:** Market discovery and candle routes are public. Private currency enrichment is skipped when check_required_credentials(false) fails; stored credentials can enable additional authenticated requests.
- **`modetrade`:** Market and currency discovery are public; fetch_ohlcv calls v1PrivateGetKline and sign requires credentials. The adapter declares no spot support.
- **`myokx`:** Market discovery and candle routes are public. Private currency enrichment is skipped when check_required_credentials(false) fails; stored credentials can enable additional authenticated requests.
- **`ndax`:** GetInstruments, GetProducts and GetTickerHistory are public routes. The public-group Authenticate routes are unrelated to this data path.
- **`novadax`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`okx`:** Market discovery and candle routes are public. Private currency enrichment is skipped when check_required_credentials(false) fails; stored credentials can enable additional authenticated requests.
- **`okxus`:** Market discovery and candle routes are public. Private currency enrichment is skipped when check_required_credentials(false) fails; stored credentials can enable additional authenticated requests.
- **`onetrading`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`p2b`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`pacifica`:** Info and kline routes are public. Client/account initialization in fetch_markets is conditional on credentials. No spot support advertised.
- **`paradex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`paymium`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`phemex`:** Product catalogues in v1/v2 and OHLCV public/md endpoints are unsigned. sign requires credentials only for private endpoints.
- **`poloniex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`tokocrypto`:** Both Binance klines and the local open market klines paths are unsigned; discovery uses the public common symbols route.
- **`toobit`:** The common exchangeInfo and quote/klines routes are unsigned; sign checks credentials only for private API requests.
- **`upbit`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`wavesexchange`:** Market tickers, assets and candles are unsigned. The private/forward token paths are not part of unauthenticated OHLCV discovery.
- **`weex`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`whitebit`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`woo`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`woofipro`:** Market and currency discovery are public; fetch_ohlcv calls v1PrivateGetKline and sign requires credentials. The adapter declares no spot support.
- **`xt`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
- **`yobit`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`zaif`:** The unified fetch_ohlcv implementation is unsupported (base method throws NotSupported). Public/static market discovery does not establish an OHLCV service.
- **`zebpay`:** The reviewed default market/currency discovery and OHLCV branches use unsigned public routes; sign does not require credentials for those routes.
