<?php
declare(strict_types=1);

namespace DiskQuotaTest;

use PHPUnit\Framework\TestCase;

class CoverageThresholdTest extends TestCase
{
    /** @dataProvider reports */
    public function testCoverageGate(string $xml, string $threshold, int $exitCode): void
    {
        $report = tempnam(sys_get_temp_dir(), 'diskquota-clover-');
        file_put_contents($report, $xml);
        try {
            $process = proc_open([
                PHP_BINARY, dirname(__DIR__) . '/check-coverage.php', $report, $threshold,
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame($exitCode, proc_close($process), $output);
        } finally {
            unlink($report);
        }
    }

    public function reports(): array
    {
        $report = function (int $total, int $covered): string {
            return '<coverage><project><metrics statements="' . $total . '" coveredstatements="'
                . $covered . '"/></project></coverage>';
        };
        return [
            'exact threshold' => [$report(100, 90), '90', 0],
            'above threshold' => [$report(100, 91), '90', 0],
            'below threshold even if rounded to 90' => [$report(100000, 89999), '90', 1],
            'empty report' => [$report(0, 0), '90', 1],
            'missing metrics' => ['<coverage/>', '90', 1],
            'malformed report' => ['not XML', '90', 1],
            'invalid threshold' => [$report(100, 100), 'invalid', 1],
        ];
    }
}
