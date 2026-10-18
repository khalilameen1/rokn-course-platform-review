<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final class AdminEditorVersion
{
    /** @param list<string> $fields */
    public static function for(Model $model, array $fields): string
    {
        return hash('sha256', json_encode(array_map(
            static function (string $field) use ($model): mixed {
                $value = $model->getAttribute($field);
                if ($value instanceof DateTimeInterface) {
                    return $value->format('Y-m-d\TH:i:s.uP');
                }

                return $value;
            },
            $fields
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
