<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ExternalApiCache extends Model
{
    protected $table = 'external_api_cache';

    protected $fillable = [
        'source',
        'external_id',
        'data',
        'fetched_at',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', Carbon::now());
    }

    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('expires_at', '>=', Carbon::now());
    }

    public static function get(string $source, string $externalId): ?self
    {
        return static::where('source', $source)
            ->where('external_id', $externalId)
            ->fresh()
            ->first();
    }

    public static function put(string $source, string $externalId, array $data, int $ttlHours = 24): self
    {
        return static::updateOrCreate(
            ['source' => $source, 'external_id' => $externalId],
            [
                'data' => $data,
                'fetched_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addHours($ttlHours),
            ]
        );
    }

    public static function findByKey(string $key): ?array
    {
        $record = static::where('external_id', $key)->fresh()->first();

        return $record?->data;
    }

    public static function storeByKey(string $key, array $data, Carbon $expiresAt): self
    {
        [$source] = explode(':', $key, 2);

        return static::updateOrCreate(
            ['source' => $source ?: 'unknown', 'external_id' => $key],
            [
                'data' => $data,
                'fetched_at' => Carbon::now(),
                'expires_at' => $expiresAt,
            ]
        );
    }
}
