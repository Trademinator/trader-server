<?php

use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'app.name' => 'Trademinator',
        'app.logo_url' => null,
        'session.driver' => 'array',
        'cache.default' => 'array',
    ]);
});

it('uses the bundled cyborg icon for an unset or blank override', function ($override) {
    config(['app.logo_url' => $override]);

    $this->blade('<x-app-logo-icon class="size-8" />')
        ->assertSee('src="'.asset('images/branding/trademinator-icon.webp').'?v=trademinator-silver-transparent-2"', false)
        ->assertSee('aria-hidden="true"', false)
        ->assertSee('size-8', false)
        ->assertDontSee('<svg', false);
})->with([
    'unset' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
]);

it('uses the full approved artwork where requested', function () {
    $this->blade('<x-app-logo-icon variant="full" alt="Trademinator logo" />')
        ->assertSee('src="'.asset('images/branding/trademinator-logo.webp').'?v=trademinator-silver-transparent-2"', false)
        ->assertSee('alt="Trademinator logo"', false)
        ->assertDontSee('aria-hidden="true"', false);
});

it('honours an external custom logo in both sizes', function ($variant) {
    config(['app.logo_url' => 'https://branding.example.test/custom-logo.png']);

    $this->blade('<x-app-logo-icon variant="'.$variant.'" />')
        ->assertSee('src="https://branding.example.test/custom-logo.png"', false)
        ->assertDontSee('trademinator-icon.webp', false)
        ->assertDontSee('trademinator-logo.webp', false);
})->with(['icon', 'full']);

it('resolves a custom public asset path', function ($path) {
    config(['app.logo_url' => $path]);

    $this->blade('<x-app-logo-icon />')
        ->assertSee('src="'.asset('images/custom-logo.png').'"', false);
})->with(['images/custom-logo.png', '/images/custom-logo.png']);

it('renders the configured application name beside the sidebar icon', function () {
    config(['app.name' => 'My Trading Desk']);

    $this->blade('<x-app-logo />')
        ->assertSee('My Trading Desk')
        ->assertSee('trademinator-icon.webp', false)
        ->assertDontSee('<svg', false)
        ->assertDontSee('Laravel Starter Kit');
});

it('escapes application names in the page title', function () {
    config(['app.name' => 'Trading <Desk>']);

    $this->view('partials.head', ['title' => 'Dashboard'])
        ->assertSee('<title>Dashboard | Trading &lt;Desk&gt;</title>', false)
        ->assertDontSee('<title>Dashboard | Laravel</title>', false);
});

it('uses the application name when no page title is supplied', function () {
    $this->view('partials.head')
        ->assertSee('<title>Trademinator</title>', false)
        ->assertSee('favicon.ico?v=trademinator-silver-transparent-2', false)
        ->assertSee('favicon-32x32.png?v=trademinator-silver-transparent-2', false)
        ->assertSee('apple-touch-icon.png?v=trademinator-silver-transparent-2', false);
});

it('uses the full artwork in the authentication layouts', function ($layout) {
    $this->blade('<x-layouts.auth.'.$layout.'><p>Branding test</p></x-layouts.auth.'.$layout.'>')
        ->assertSee('trademinator-logo.webp', false)
        ->assertSee('Branding test')
        ->assertDontSee('viewBox="0 0 40 42"', false)
        ->assertDontSee('<title>Laravel</title>', false);
})->with(['simple', 'card', 'split']);

it('renders a branded welcome page with guest navigation', function () {
    $this->view('welcome')
        ->assertSee('trademinator-logo.webp', false)
        ->assertSee('<title>Trademinator</title>', false)
        ->assertSee('href="'.route('login').'"', false)
        ->assertSee('href="'.route('register').'"', false)
        ->assertDontSee('laravel.com', false)
        ->assertDontSee('laracasts.com', false);
});

it('retains authenticated navigation on the welcome page', function () {
    $this->actingAs(new App\Models\User);

    $this->view('welcome')
        ->assertSee('href="'.route('dashboard').'"', false)
        ->assertSee('href="'.route('markets.index').'"', false)
        ->assertDontSee('href="'.route('login').'"', false);
});

it('ships the branded images at their declared dimensions', function ($path, $width, $height) {
    $size = getimagesize(public_path($path));

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe($width)
        ->and($size[1])->toBe($height);
})->with([
    ['images/branding/trademinator-logo.png', 1254, 1254],
    ['images/branding/trademinator-logo.webp', 960, 960],
    ['images/branding/trademinator-icon.webp', 256, 256],
    ['images/branding/trademinator-icon.png', 256, 256],
    ['favicon-16x16.png', 16, 16],
    ['favicon-32x32.png', 32, 32],
    ['apple-touch-icon.png', 180, 180],
]);

it('ships a nonempty ICO favicon', function () {
    $contents = file_get_contents(public_path('favicon.ico'));

    expect(strlen($contents))->toBeGreaterThan(6)
        ->and(substr($contents, 0, 4))->toBe("\x00\x00\x01\x00");
});


it('does not place an opaque black tile behind the sidebar logo', function () {
    $this->blade('<x-app-logo />')
        ->assertDontSee('bg-black', false);
});

it('does not place an opaque black tile behind the welcome or split-layout logos', function () {
    $this->view('welcome')->assertDontSee('bg-black', false);

    $this->blade('<x-layouts.auth.split><p>Transparency test</p></x-layouts.auth.split>')
        ->assertDontSee('bg-black', false);
});

it('leaves signed custom logo URLs and fragments untouched', function () {
    config(['app.logo_url' => 'https://branding.example.test/custom.png?signature=abc123#logo']);

    $this->blade('<x-app-logo-icon />')
        ->assertSee('src="https://branding.example.test/custom.png?signature=abc123#logo"', false)
        ->assertDontSee('trademinator-silver-transparent-2', false);
});

it('ships RGBA PNG assets rather than opaque RGB replacements', function ($path) {
    $contents = file_get_contents(public_path($path));

    expect(substr($contents, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(substr($contents, 12, 4))->toBe('IHDR')
        ->and(ord($contents[24]))->toBe(8)
        ->and(ord($contents[25]))->toBe(6);
})->with([
    'images/branding/trademinator-logo.png',
    'images/branding/trademinator-icon.png',
    'favicon-16x16.png',
    'favicon-32x32.png',
    'apple-touch-icon.png',
]);

it('ships WebP logo variants with their alpha channel enabled', function ($path) {
    $contents = file_get_contents(public_path($path));

    expect(substr($contents, 0, 4))->toBe('RIFF')
        ->and(substr($contents, 8, 4))->toBe('WEBP')
        ->and(substr($contents, 12, 4))->toBe('VP8X')
        ->and(ord($contents[20]) & 0x10)->toBe(0x10);
})->with([
    'images/branding/trademinator-logo.webp',
    'images/branding/trademinator-icon.webp',
]);

it('keeps the actual top left pixel transparent in each PNG without requiring GD', function ($path) {
    $contents = file_get_contents(public_path($path));
    $compressed = '';
    $offset = 8;

    expect(ord($contents[24]))->toBe(8)
        ->and(ord($contents[25]))->toBe(6)
        ->and(ord($contents[28]))->toBe(0);

    while ($offset + 12 <= strlen($contents)) {
        $length = unpack('Nlength', substr($contents, $offset, 4))['length'];
        $type = substr($contents, $offset + 4, 4);

        if ($type === 'IDAT') {
            $compressed .= substr($contents, $offset + 8, $length);
        }

        $offset += 12 + $length;

        if ($type === 'IEND') {
            break;
        }
    }

    $pixels = gzuncompress($compressed);

    expect($pixels)->not->toBeFalse()
        ->and(strlen($pixels))->toBeGreaterThan(4);

    // In a non-interlaced RGBA PNG, the first byte is the scanline filter.
    // All predictors for the first pixel are zero, so its alpha is byte 4.
    expect(ord($pixels[0]))->toBeLessThanOrEqual(4)
        ->and(ord($pixels[4]))->toBe(0);
})->with([
    'images/branding/trademinator-logo.png',
    'images/branding/trademinator-icon.png',
    'favicon-16x16.png',
    'favicon-32x32.png',
    'apple-touch-icon.png',
]);
