<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\Validator;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;

/** Deployment-only import of reviewed code assets. Never called from an HTTP input or model output. */
final class SkillSeedLoader
{
    public function load(string $directory, string $scope = 'module'): array
    {
        if (! in_array($scope, ['module', 'project'], true)) {
            throw new SkillDomainException('scope_denied');
        }
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new SkillDomainException('data_incomplete', 'Seed source directory is missing.');
        }
        $composer = $this->json($this->asset($root, 'composer.json'));
        $namespace = $composer['extra']['saas']['name'] ?? null;
        if (! is_string($namespace) || ! preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $namespace)) {
            throw new SkillDomainException('data_incomplete', 'Source must declare its registered module name.');
        }
        $manifest = $this->json($this->asset($root, 'resources/skills/catalog.json'));
        if (array_diff(array_keys($manifest), ['schema_version', 'skills']) !== [] || ($manifest['schema_version'] ?? null) !== 1
            || ! isset($manifest['skills']) || ! is_array($manifest['skills']) || ! array_is_list($manifest['skills'])
            || count($manifest['skills']) > 100) {
            throw new SkillDomainException('data_incomplete', 'Unsupported seed manifest.');
        }
        $definitions = [];
        $seen = [];
        foreach ($manifest['skills'] as $seed) {
            $definition = $this->definition($root, $scope, $namespace, $seed);
            $identity = $definition['skill_key'] . '@' . $definition['version'];
            if (isset($seen[$identity])) {
                throw new SkillDomainException('version_conflict', 'Duplicate version in seed manifest.');
            }
            $seen[$identity] = true;
            $definitions[] = $definition;
        }

        return (new SkillDefinition)->getConnection()->transaction(function () use ($definitions): array {
            $created = $replayed = 0;
            foreach ($definitions as $data) {
                $identity = array_intersect_key($data, array_flip(['skill_key', 'version', 'scope_type', 'scope_id']));
                // createOrFirst uses a savepoint and the storage unique key to handle concurrent imports.
                $definition = SkillDefinition::query()->lockForUpdate()->createOrFirst($identity, array_diff_key($data, $identity));
                if ($definition->wasRecentlyCreated) {
                    $created++;

                    continue;
                }
                if (! in_array($definition->status, [SkillDefinition::STATUS_PUBLISHED, SkillDefinition::STATUS_DISABLED], true)) {
                    throw new SkillDomainException('version_conflict', 'Seed identity conflicts with an unpublished definition.');
                }
                foreach (SkillDefinition::SEMANTIC_FIELDS as $field) {
                    if ($this->canonical($definition->getAttribute($field)) !== $this->canonical($data[$field] ?? null)) {
                        throw new SkillDomainException('version_conflict', 'Seed version changed; publish a new version.');
                    }
                }
                // A replay must not re-enable an administratively disabled published version.
                $replayed++;
            }

            return ['created' => $created, 'replayed' => $replayed];
        }, 3);
    }

    private function definition(string $root, string $scope, string $namespace, mixed $seed): array
    {
        $allowed = ['skill_key', 'version', 'execution', 'title', 'description', 'input_schema', 'applicability', 'tool_deps', 'risk', 'success_criteria', 'content_path'];
        if (! is_array($seed) || array_diff(array_keys($seed), $allowed) !== []) {
            throw new SkillDomainException('data_incomplete', 'Unexpected seed fields.');
        }
        $validator = Validator::make($seed, [
            'skill_key' => 'required|string|max:100', 'version' => ['required', 'string', 'max:50', 'regex:/^\d+\.\d+\.\d+$/D'],
            'execution' => 'required|in:chain,instructions,mixed', 'title' => 'required|string|max:255', 'description' => 'required|string|max:4096',
            'input_schema' => 'required|array', 'applicability' => 'required|array', 'tool_deps' => 'present|array|max:64',
            'tool_deps.*' => 'required|string|max:100', 'risk' => 'required|array', 'success_criteria' => 'required|array',
            'content_path' => 'required|string|max:150',
        ]);
        if ($validator->fails() || ! str_starts_with($seed['skill_key'], $scope . '.' . $namespace . '.')
            || ! preg_match('/^[a-z0-9_.-]+$/D', $seed['skill_key']) || ($seed['input_schema']['type'] ?? null) !== 'object') {
            throw new SkillDomainException('data_incomplete', 'Invalid or unnamespaced seed definition.');
        }
        $contentPath = $seed['content_path'];
        if (! str_starts_with($contentPath, 'resources/skills/') || ! str_contains($contentPath, '/' . $seed['version'] . '/')) {
            throw new SkillDomainException('data_incomplete', 'Content must live in a versioned Skill asset directory.');
        }
        $content = $this->asset($root, $contentPath);
        $body = file_get_contents($content);
        $digest = hash('sha256', $body);
        // The persisted reference is an immutable content digest, never a local path.
        $reference = 'sha256:' . $digest;
        if (strlen($reference) > 255) {
            throw new SkillDomainException('data_incomplete', 'Content reference exceeds storage limit.');
        }
        unset($seed['content_path']);

        return [...$seed, 'scope_type' => $scope, 'scope_id' => 0, 'owner_type' => null, 'owner_id' => null,
            'content_ref' => $reference, 'content_body' => $body, 'content_sha256' => $digest, 'status' => SkillDefinition::STATUS_PUBLISHED];
    }

    private function asset(string $root, string $relative): string
    {
        $path = realpath($root . '/' . $relative);
        if ($path === false || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR) || ! is_file($path)
            || filesize($path) > 262144 || str_contains($relative, '..') || str_contains($relative, '\\')) {
            throw new SkillDomainException('data_incomplete', 'Missing, unsafe or oversized Skill asset.');
        }

        return $path;
    }

    private function json(string $path): array
    {
        try {
            $data = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SkillDomainException('data_incomplete', 'Invalid JSON asset.');
        }
        if (! is_array($data)) {
            throw new SkillDomainException('data_incomplete', 'JSON object required.');
        }

        return $data;
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            $value = array_map($this->canonical(...), $value);
        }

        return $value;
    }
}
