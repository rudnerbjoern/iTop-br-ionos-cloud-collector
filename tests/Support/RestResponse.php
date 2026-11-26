<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Support;

/** Build native iTop core/get responses for the offline collector tests. */
final class RestResponse
{
    /**
     * Return a successful reply containing a single iTop object.
     *
     * @param array<string,mixed> $fields Requested output fields.
     * @return array{code:int,objects:array<string,array<string,mixed>>}
     */
    public static function one(string $class, int $id, array $fields = []): array
    {
        return ['code' => 0, 'objects' => ["$class::$id" => [
            'class' => $class, 'key' => (string) $id, 'code' => 0, 'fields' => $fields,
        ]]];
    }
}
