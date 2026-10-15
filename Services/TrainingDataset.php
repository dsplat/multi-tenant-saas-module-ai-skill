<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * 数据集窗口的唯一冻结标准（WP-147a）。
 *
 * 采样窗口必须在登记时冻结并落成规范键，训练器之后只读规范键：
 * 否则「登记一份没有窗口的 manifest、执行时再猜窗口」会把授权面变成运行时决定，
 * 而窗口是授权的一部分。登记即拒绝（此时还没花钱），比执行时失败便宜得多。
 */
final class TrainingDataset
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed> 规范键：source_types / time_from / time_to / field_allowlist / max_samples / access_mode
     */
    public static function freeze(array $manifest, int $tenantId): array
    {
        if (isset($manifest['remote_url']) || isset($manifest['file_path'])) {
            throw new SkillDomainException('scope_denied', 'Remote URL or filesystem path is not allowed.');
        }

        $from = self::iso8601(self::scalar($manifest, ['time_from', 'from'], 'time_from'), 'time_from');
        $to = self::iso8601(self::scalar($manifest, ['time_to', 'to'], 'time_to'), 'time_to');
        if ($from > $to) {
            throw new SkillDomainException('data_incomplete', 'Dataset window must be ordered.');
        }

        $sources = array_values(array_map('strval', (array) ($manifest['source_types'] ?? [])));
        if ($sources === []) {
            throw new SkillDomainException('data_incomplete', 'Dataset source list must be explicit.');
        }
        foreach ($sources as $source) {
            if (preg_match('/^[a-z0-9_]+$/D', $source) !== 1) {
                throw new SkillDomainException('scope_denied', 'Invalid dataset source.');
            }
        }

        $frozen = [
            'source_types' => $sources,
            'time_from' => $from,
            'time_to' => $to,
            'field_allowlist' => self::listOf($manifest, ['field_allowlist', 'fields']),
            'max_samples' => min(500, max(1, (int) ($manifest['max_samples'] ?? 100))),
            'access_mode' => (string) ($manifest['access_mode'] ?? 'metadata_only'),
            'authorized_tenant_id' => $tenantId,
        ];
        foreach (['sample_ids'] as $key) {
            if (isset($manifest[$key]) && $manifest[$key] !== []) {
                $frozen[$key] = array_values(array_map('strval', (array) $manifest[$key]));
            }
        }

        return $frozen;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $keys
     */
    private static function scalar(array $manifest, array $keys, string $field): mixed
    {
        $found = null;
        foreach ($keys as $key) {
            $value = $manifest[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $found = $value;
                break;
            }
        }
        if ($found === null) {
            throw new SkillDomainException('data_incomplete', "Dataset window requires an explicit {$field}.");
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function listOf(array $manifest, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($manifest[$key]) && $manifest[$key] !== []) {
                return array_values(array_map('strval', (array) $manifest[$key]));
            }
        }

        return [];
    }

    /** 窗口端点必须是可解析的绝对时间：留空即拒绝，不补「现在」这类退化窗口。 */
    private static function iso8601(mixed $value, string $field): string
    {
        try {
            $parsed = new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            throw new SkillDomainException('data_incomplete', "Dataset window [{$field}] is not a valid timestamp.");
        }

        return $parsed->format('c');
    }
}
