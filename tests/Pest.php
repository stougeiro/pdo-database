<?php declare(strict_types=1);

uses(PHPUnit\Framework\TestCase::class)->in(__DIR__ . '/Unit', __DIR__ . '/Feature', __DIR__ . '/Performance');

require_once __DIR__ . '/Performance/bench.php';
