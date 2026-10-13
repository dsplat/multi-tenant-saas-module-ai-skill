<?php

namespace MultiTenantSaas\Modules\AiSkill\Console;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Services\SkillSeedLoader;

class ImportSkillSeedsCommand extends Command
{
    protected $signature = 'ai-skill:import {directory : Reviewed module/project root} {--scope=module : module or project}';

    protected $description = 'Import immutable reviewed Skill assets without executing tools or calling an LLM';

    public function handle(SkillSeedLoader $loader): int
    {
        try {
            $result = $loader->load((string) $this->argument('directory'), (string) $this->option('scope'));
        } catch (SkillDomainException $exception) {
            $this->error($exception->reason . ': ' . $exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
