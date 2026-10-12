<?php
declare(strict_types=1);

namespace DiskQuota\Service;

use Laminas\Http\Request;

class UploadSizeResolver
{
    /** Resolve the selected multipart file; downloaded media are checked during ingestion. */
    public function getSize($httpRequest, array $data): int
    {
        if (($data['o:ingester'] ?? null) === 'url') {
            return 0;
        }
        if (isset($data['file_index'])) {
            $files = $httpRequest instanceof Request ? $httpRequest->getFiles()->toArray() : [];
            return $this->getFileSize($files['file'][$data['file_index']] ?? null) ?? 0;
        }
        $size = $httpRequest instanceof Request
            ? $this->getHttpSize($httpRequest->getFiles()->toArray()) : 0;
        if ($size > 0) {
            return $size;
        }
        if (!empty($data['data']['size'])) {
            return (int) $data['data']['size'];
        }
        return (int) ($data['o:size'] ?? 0);
    }

    private function getHttpSize(array $files): int
    {
        foreach ($files as $file) {
            $size = $this->getFileSize($file);
            if ($size !== null) {
                return $size;
            }
            $size = $this->getNestedSize($file);
            if ($size !== null) {
                return $size;
            }
        }
        return 0;
    }

    private function getNestedSize($files): ?int
    {
        if (!is_array($files)) {
            return null;
        }
        foreach ($files as $file) {
            $size = $this->getFileSize($file);
            if ($size !== null) {
                return $size;
            }
        }
        return null;
    }

    private function getFileSize($file): ?int
    {
        if (!is_array($file) || empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return null;
        }
        return (int) filesize($file['tmp_name']);
    }
}
