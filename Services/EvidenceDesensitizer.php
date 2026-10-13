<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

/**
 * 经验采样脱敏（AiSkill 本地，不 import Ai 内部 Service）。
 *
 * 敏感键整值替换；凭据形态片段就地替换；超长截断。
 * 训练材料不得携带真实联系方式/凭据。
 */
final class EvidenceDesensitizer
{
    public const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|api[-_]?key|authorization|credential|private[-_]?key|access[-_]?key|phone|mobile|email)/i';

    private const SENSITIVE_VALUE_PATTERN = '/\b(sk-[A-Za-z0-9_-]{8,}|Bearer\s+[A-Za-z0-9._~+\/=-]{8,})/';

    private const MAX_STRING = 2000;

    public function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            $masked = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                    $masked[$key] = self::REDACTED;

                    continue;
                }
                $masked[$key] = $this->mask($item);
            }

            return $masked;
        }

        if (is_string($value)) {
            $masked = (string) preg_replace(self::SENSITIVE_VALUE_PATTERN, self::REDACTED, $value);
            if (mb_strlen($masked) > self::MAX_STRING) {
                return mb_substr($masked, 0, self::MAX_STRING) . '…[truncated]';
            }

            return $masked;
        }

        return $value;
    }
}
