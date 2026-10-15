<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Http\Controllers;

use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillExecutionServiceContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\RequestKey;
use MultiTenantSaas\Modules\AiSkill\DTOs\SkillRunView;
use MultiTenantSaas\Modules\AiSkill\DTOs\SkillSelection;
use MultiTenantSaas\Modules\AiSkill\DTOs\ValidatedInput;
use MultiTenantSaas\Modules\AiSkill\Services\SkillExecutionService;

/**
 * Skill 固定执行 HTTP（C 包契约 §4.2）。
 * 身份由认证与可信 OperatorContext 解析；请求不自报 actor/tenant。
 */
class SkillRunController extends BaseController
{
    public function start(Request $request, SkillExecutionServiceContract $runs)
    {
        $data = $request->validate([
            'skill_key' => 'required|string|max:100',
            'version' => 'required|string|max:50',
            'variant_key' => 'sometimes|nullable|string|max:50',
            'definition_ref' => 'sometimes|nullable|string|max:255',
            'input' => 'sometimes|array',
            'options' => 'sometimes|array',
            'request_key' => 'required|string|max:100',
            'input_fingerprint' => 'sometimes|nullable|string|max:64',
        ]);

        $selection = new SkillSelection(
            skillKey: (string) $data['skill_key'],
            version: (string) $data['version'],
            variantKey: $data['variant_key'] ?? null,
            definitionRef: $data['definition_ref'] ?? null,
            options: is_array($data['options'] ?? null) ? $data['options'] : [],
        );
        $params = is_array($data['input'] ?? null) ? $data['input'] : [];
        $input = new ValidatedInput(params: $params);
        $key = new RequestKey(key: (string) $data['request_key'], inputFingerprint: '');

        return response()->json(['success' => true, 'data' => $this->payload($runs->start($selection, $input, $key))]);
    }

    public function advance(Request $request, string $runId, SkillExecutionServiceContract $runs)
    {
        $data = $request->validate([
            'expected_revision' => 'required|integer|min:1',
            'step_input' => 'sometimes|array',
        ]);
        $this->assertRunId($runId);

        $view = $runs->advance(
            (int) $runId,
            (int) $data['expected_revision'],
            is_array($data['step_input'] ?? null) ? $data['step_input'] : [],
        );

        return response()->json(['success' => true, 'data' => $this->payload($view)]);
    }

    public function cancel(Request $request, string $runId, SkillExecutionServiceContract $runs)
    {
        $data = $request->validate(['expected_revision' => 'required|integer|min:1']);
        $this->assertRunId($runId);

        return response()->json([
            'success' => true,
            'data' => $this->payload($runs->cancel((int) $runId, (int) $data['expected_revision'])),
        ]);
    }

    public function show(string $runId, SkillExecutionServiceContract $runs)
    {
        $this->assertRunId($runId);

        return response()->json(['success' => true, 'data' => $this->payload($runs->get((int) $runId))]);
    }

    public function index(Request $request, SkillExecutionServiceContract $runs)
    {
        $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:100']);
        $views = $runs instanceof SkillExecutionService
            ? $runs->listActive((int) ($data['limit'] ?? 50))
            : [];

        return response()->json([
            'success' => true,
            'data' => array_map($this->payload(...), $views),
        ]);
    }

    private function assertRunId(string $runId): void
    {
        validator(['run_id' => $runId], ['run_id' => 'required|integer|min:1'])->validate();
    }

    private function payload(SkillRunView $view): array
    {
        return [
            // 主键字符串序列化（不透明引用）
            'skill_run_id' => (string) $view->runId,
            'skill_key' => $view->skillKey,
            'skill_version' => $view->skillVersion,
            'revision' => $view->revision,
            'status' => $view->status,
            'current_step' => $view->currentStep,
            'steps' => $view->steps,
            'context' => $view->context,
            'outcome' => $view->outcome,
            'reason' => $view->reason,
            'next_action' => $view->nextAction,
            'warnings' => $view->warnings,
        ];
    }
}
