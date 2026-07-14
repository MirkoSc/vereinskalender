<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Http\Request;

/** @var Kernel $kernel */
$kernel = require dirname(__DIR__) . '/app/src/bootstrap.php';
$kernel->handle(Request::fromGlobals())->send();
