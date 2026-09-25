# Trademinator branding

## Default artwork

The default identity is the user-selected silver cyborg logo: an older human face with worn mechanical parts, silver market graphics, and restrained red accents. The full approved raster is preserved byte-for-byte in `public/images/branding/trademinator-logo.png`.

The head has not been regenerated, redrawn, or retouched. Compact icons are crops and resizes of that same source. Its existing black background is preserved; the artwork is not transparent.

| Asset | Size | Use |
| --- | --- | --- |
| `public/images/branding/trademinator-logo.png` | 1254 × 1254 | Original approved source |
| `public/images/branding/trademinator-logo.webp` | 960 × 960 | Welcome and authentication pages |
| `public/images/branding/trademinator-icon.webp` | 256 × 256 | Sidebar / compact branding |
| `public/images/branding/trademinator-icon.png` | 256 × 256 | PNG compact icon |
| `public/favicon.ico` | 16, 32, 48, 64 | Browser fallback |
| `public/favicon-16x16.png` | 16 × 16 | Small browser tabs |
| `public/favicon-32x32.png` | 32 × 32 | Higher-density browser tabs |
| `public/apple-touch-icon.png` | 180 × 180 | Apple home-screen shortcut |

These are raster assets, not vector SVGs. An SVG wrapper around a bitmap would not turn this illustration into true vector artwork.

## Configuration

```dotenv
APP_NAME=Trademinator
APP_LOGO_URL=
```

An unset, empty, or whitespace-only `APP_LOGO_URL` uses the bundled default. There is no Laravel-icon fallback. Existing `.env` files are not modified by this update. An explicitly configured `APP_NAME=Laravel` still wins over the default and should be changed to `Trademinator`.

The optional `APP_LOGO_URL` override is retained. It accepts a public asset path (with or without a leading slash) or an absolute HTTPS URL. It applies to both compact and full logo components. For example:

```dotenv
APP_LOGO_URL=/images/custom-logo.png
```

Relative assets are resolved through Laravel's `asset()` helper, so the application's configured asset URL is respected. If `ASSET_URL` points to a CDN, deploy these public assets to that CDN too. The override does not regenerate or replace the shipped favicon files; replace those files separately when changing to another identity.

Environment access remains in `config/app.php`; views read the configuration, not `env()`.

## Components and pages

```blade
{{-- Decorative compact image beside visible branding text. --}}
<x-app-logo-icon class="size-8" />

{{-- Full logo with an accessible text alternative. --}}
<x-app-logo-icon variant="full" :alt="config('app.name').' logo'" class="h-auto w-full" />

{{-- Compact logo and the configured application name. --}}
<x-app-logo />
```

Empty `alt` values are decorative and receive `aria-hidden="true"`. The sidebar and authentication home links already contain the application name as visible or screen-reader text. Standalone use should supply an appropriate `alt` value.

The `simple`, `card`, and `split` authentication layouts are covered. The default Laravel welcome page is replaced by a Trademinator welcome page while retaining login, registration, dashboard, and market navigation. Shared titles use `APP_NAME`, and `partials/branding-icons.blade.php` defines the browser icons. Favicon URLs carry a version suffix to help avoid old cached icons.

## Deployment

After applying the code and assets, check the two `.env` values above and run:

```bash
npm run build
php artisan optimize:clear
php artisan test --filter=BrandingTest
```

Run `npm ci` before the build only if the existing Node dependencies have not been installed. No database migration or new Composer/Node dependency is required for the branding update.

On production installations that cache configuration and views, rebuild those caches after checking the result:

```bash
php artisan config:cache
php artisan view:cache
```

Use a hard browser refresh after deployment. If PHP OPcache is configured not to check changed files, reload the site's PHP-FPM/FastCGI service through the normal hosting procedure. Purge any CDN cache for the branding paths when replacing an existing asset in place.

## Regression checks

`tests/Unit/BrandingTest.php` boots the application's test case but does not use `RefreshDatabase`. It covers blank and custom URL behavior, both logo variants, accessible alternatives, custom app names, shared titles and favicons, all authentication layouts, guest/authenticated welcome navigation, and the image dimensions. The tests are under `Unit` to avoid this project's automatic database-refresh trait for `Feature` tests; they still exercise the Blade/application layer.

For a visual check, inspect the sidebar at desktop/mobile widths, the welcome page, login/register/password-reset screens, and browser icons in both appearance modes. The complete illustration is reserved for larger placements so the tiny wordmark is not relied on at favicon size.
