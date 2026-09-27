<?php

use Tests\Support\TestEnvironment;

require dirname(__DIR__).'/vendor/autoload.php';

// This runs before Pest discovers tests or Laravel reads any application config.
TestEnvironment::prepare();
