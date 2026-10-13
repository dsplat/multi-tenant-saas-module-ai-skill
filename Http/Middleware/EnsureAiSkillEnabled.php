<?php

namespace MultiTenantSaas\Modules\AiSkill\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAiSkillEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('ai-skill.enabled', false) === true, 404);

        return $next($request);
    }
}
