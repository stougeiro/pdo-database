<?php declare(strict_types=1);

/**
 * A tiny, dependency-free micro-bench helper.
 *
 * It runs $work $iterations times and returns the elapsed nanoseconds. When
 * BENCH=1 is set it also writes an informative line to STDERR, so the same
 * call is a silent regression guard in CI and a readable bench when wanted.
 */
function bench(string $label, int $iterations, Closure $work): int
{
    $work();

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $work();
    }

    $elapsed = hrtime(true) - $start;

    if (getenv('BENCH') === '1') {
        $perIteration = $elapsed / $iterations;

        fwrite(STDERR, sprintf(
            "  [bench] %-40s %10.2f ns/op  (%d ops%s)\n",
            $label,
            $perIteration,
            $iterations,
            PHP_EOL,
        ));
    }

    return $elapsed;
}
