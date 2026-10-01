Trademinator market-feed exchange interleaving fix

Replace these files in the repository:
- app/Domain/MarketData/MarketFeedDispatcher.php
- tests/Feature/MarketSubscriptionsTest.php

Then run:
php artisan test tests/Feature/MarketSubscriptionsTest.php

The dispatcher now ranks due feeds within each exchange and queues one per exchange per round.
Example: Kraken, Kraken, Kraken, Bitso, Bitso becomes Kraken, Bitso, Kraken, Bitso, Kraken.

This reduces same-exchange bursts but does not guarantee a one-request-per-second API limit when multiple queue workers execute jobs concurrently. A distributed per-exchange/API throttle is still the hard safety mechanism.
