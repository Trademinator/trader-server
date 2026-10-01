Dashboard Need-Attention dedup fix

Extract this archive at the repository root to replace:
  app/Domain/Intelligence/CollectionAttention.php
  tests/Feature/DashboardMarketsTest.php

Then run:
  php artisan test tests/Feature/DashboardMarketsTest.php

What changes:
- A queued feed no longer reports its previous last_error as a separate "Collector queued" issue.
- An expired queue lease becomes the primary collection issue and includes the previous attempt's error as context.
- selected_period=NULL is suppressed when it is already explained by the primary collection issue.
- Pending period-selection failures no longer produce a second generic missing-period warning.
- Independent stale/gap/invalid-candle issues remain separate.
