<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/phpbb/ext/forumfortress/protect/service/ff_api_resilience.php';
require_once __DIR__ . '/FfApiResilienceContract.php';

exit(FfApiResilienceContract::run());
