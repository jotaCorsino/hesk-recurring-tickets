<?php

declare(strict_types=1);

namespace TicketsRecorrentesHesk\Web;

use InvalidArgumentException;

final class AdminUrl
{
    private readonly string $basePath;

    public function __construct(string $basePath = '')
    {
        $basePath = trim($basePath);

        if ($basePath === '' || $basePath === '/') {
            $this->basePath = '';
            return;
        }

        if (str_contains($basePath, '?')
            || str_contains($basePath, '#')
            || str_contains($basePath, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $basePath)
            || !str_starts_with($basePath, '/')) {
            throw new InvalidArgumentException('ADMIN_UI_BASE_PATH deve ser um caminho absoluto sem query ou fragmento.');
        }

        $segments = explode('/', trim($basePath, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || !preg_match('/^[A-Za-z0-9._~-]+$/D', $segment)) {
                throw new InvalidArgumentException('ADMIN_UI_BASE_PATH contém um segmento inválido.');
            }
        }

        $this->basePath = '/' . implode('/', $segments);
    }

    public static function fromEnvironment(): self
    {
        $configured = getenv('ADMIN_UI_BASE_PATH');

        return new self(is_string($configured) ? $configured : '');
    }

    public function to(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
            throw new InvalidArgumentException('A URL administrativa relativa é inválida.');
        }

        return $this->basePath === '' ? $relative : $this->basePath . '/' . $relative;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function cookiePath(): string
    {
        return $this->basePath === '' ? '/' : $this->basePath . '/';
    }
}
