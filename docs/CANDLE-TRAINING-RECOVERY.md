# Action Training: frozen-history recovery guidance

A frozen dataset is not silently rewritten when the canonical source history
changes or is no longer available. Action Training continues to reject a
snapshot mismatch with HTTP 422 and the original validation field.

The response now distinguishes the configured server owner (including
additional configured owners, via `User::isOwner()`) from an authorized trainer.
Owners receive shell-quoted commands derived from the loaded manifest:

```sh
php artisan trademinator:build-features 'EXCHANGE' 'SYMBOL' 'PERIOD' &&
php artisan trademinator:knn-build 'EXCHANGE' 'SYMBOL' 'PERIOD' --schema='SCHEMA'
```

The displayed values are populated automatically; the example above is only
illustrative. Run the displayed command from the application directory. The
second command runs only if feature rebuilding succeeds. It creates a fresh
semantic dataset and rebuilds that market's intelligence model; it does not
repair the old dataset in place or pass the stale UUID back with `--dataset`.
Existing core, technical and full schema selections are preserved. For custom
or unsupported schemas, the message explicitly identifies the supported
configured fallback (core if the configured value is also unsupported).

After completion, return to Human training and select the newly created dataset.
This does not guarantee that every label from a different immutable snapshot
will be compatible with the new dataset. Switching datasets does not submit
browser-staged labels. Neither displaying this error nor applying this patch
runs a rebuild, deletes labels or modifies datasets.

Other trainers receive a prompt to choose another dataset or contact the server
owner, with no CLI instructions in the JSON response. Cursor-validation errors
and training authorization retain their existing behavior. Recovery guidance
also applies to source mismatches during initial review and label submission.

The chart now reads validation messages for 422 history responses instead of
replacing them with its generic retry message. It renders plain text, preserves
line breaks, and wraps long command lines. Throttle, redirect, non-JSON and
non-validation server errors retain safe fallback messages.

## Deployment

No migration, Composer dependency or environment change is required. Compile
and deploy the updated frontend assets with the normal application workflow:

```sh
npm run build
php artisan view:clear
```

## Regression checks

```sh
node --test tests/js/candle-training-history-error.test.mjs
php artisan test --filter=CandleTrainingRecoveryTest
php artisan test --filter=CandleTrainingTest
```

The JavaScript regression tests were executed while preparing this patch. The
new PHP files passed syntax checking. Laravel integration tests require the
application's installed dependencies and test database and were not executed
in the patch preparation environment.
