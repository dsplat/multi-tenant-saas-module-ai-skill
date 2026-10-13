<?php

namespace MultiTenantSaas\Modules\AiSkill\Services;

use MultiTenantSaas\Contracts\OperatorContextContract;
use MultiTenantSaas\Contracts\PermissionCheckerContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\FavoriteAccessPolicyContract;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;

final class FavoriteAccessPolicy implements FavoriteAccessPolicyContract
{
    public function __construct(private readonly OperatorContextContract $operators, private readonly PermissionCheckerContract $permissions) {}

    public function authorizeTenantWrite(array $actor): void
    {
        if ($actor !== $this->operators->resolve() || ! $this->permissions->check('setting.update')) {
            throw new SkillDomainException('scope_denied');
        }
    }

    public function validateDefaults(SkillDefinition $definition, array $defaults): array
    {
        if (count($defaults) > 20 || strlen(json_encode($defaults, JSON_THROW_ON_ERROR)) > 4096) {
            throw new SkillDomainException('scope_denied', 'Favorite defaults exceed the allowed size.');
        }
        $properties = $definition->input_schema['properties'] ?? [];
        foreach ($defaults as $name => $value) {
            $schema = $properties[$name] ?? [];
            // Only explicit finite choices may be persisted; free text and dynamic IDs remain per-run inputs.
            if (! is_string($name) || preg_match('/(?:id|token|secret|password|credential|email|phone)/i', $name)
                || ($schema['x-favorite-default'] ?? false) !== true || ! isset($schema['enum'])
                || ! is_array($schema['enum']) || ! is_scalar($value) || ! in_array($value, $schema['enum'], true)
                || (is_string($value) && (strlen($value) > 128 || preg_match('/(?:Bearer\s|sk-|@|1[3-9]\d{9})/i', $value)))) {
                throw new SkillDomainException('scope_denied', 'Favorite defaults must be explicitly permitted finite preferences.');
            }
            $validType = match ($schema['type'] ?? null) {
                'string' => is_string($value), 'integer' => is_int($value),
                'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value), default => false,
            };
            if (! $validType) {
                throw new SkillDomainException('scope_denied', 'Favorite default type mismatch.');
            }
        }

        return $defaults;
    }
}
