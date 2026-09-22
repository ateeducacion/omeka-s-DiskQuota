<?php
declare(strict_types=1);

namespace DiskQuotaTest\Service;

use DiskQuota\Service\UploadSizeResolver;
use Laminas\Http\Request;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\TestCase;

class UploadSizeResolverTest extends TestCase
{
    /** @dataProvider metadataSizes */
    public function testMetadataFallback(array $data, int $expected): void
    {
        $resolver = new UploadSizeResolver();
        $this->assertSame($expected, $resolver->getSize(new \stdClass(), $data));
        $this->assertSame($expected, $resolver->getSize(new Request(), $data));
    }

    public function metadataSizes(): array
    {
        return [
            'no size' => [[], 0],
            'data size takes priority' => [['data' => ['size' => '12'], 'o:size' => 20], 12],
            'processed size' => [['o:size' => 20], 20],
            'zero falls back' => [['data' => ['size' => 0], 'o:size' => 20], 20],
            'negative remains nonpositive' => [['data' => ['size' => -1]], -1],
        ];
    }

    /** @dataProvider uploadedFiles */
    public function testSupportedHttpFileShapes(string $shape, int $expected): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quota-upload-');
        $empty = tempnam(sys_get_temp_dir(), 'quota-empty-');
        file_put_contents($file, str_repeat('x', 12));
        try {
            $upload = ['tmp_name' => $file];
            $shapes = [
                'flat' => [$upload],
                'nested' => [['invalid', $upload]],
                'invalid before valid' => ['invalid', ['tmp_name' => $file . '-missing'], $upload],
                'first file only' => [$upload, $upload],
                'zero first file uses metadata' => [['tmp_name' => $empty], $upload],
                'nested before flat' => [[$upload], ['tmp_name' => $empty]],
                'unsupported deeper nesting' => [[[$upload]]],
            ];
            $http = new Request();
            $http->setFiles(new Parameters($shapes[$shape]));
            $this->assertSame($expected, (new UploadSizeResolver())->getSize($http, ['o:size' => 99]));
        } finally {
            unlink($file);
            unlink($empty);
        }
    }

    public function uploadedFiles(): array
    {
        return [
            ['flat', 12], ['nested', 12], ['invalid before valid', 12], ['first file only', 12],
            ['zero first file uses metadata', 99], ['nested before flat', 12], ['unsupported deeper nesting', 99],
        ];
    }
}
