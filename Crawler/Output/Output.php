<?php

namespace Crawler\Output;

use Swoole\Coroutine\Http\Client;
use Swoole\Table;

interface Output
{
    public function addBanner(): void;

    public function addUsedOptions(): void;

    public function addTableHeader(): void;

    public function addTableRow(Client $httpClient, string $url, int $status, float $elapsedTime, int $size, array $extraParsedContent, string $progressStatus): void;

    public function addTotalStats(Table $visited): void;

    public function addError(string $text): void;

    public function end(): void;
}