<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Http\Controllers;

use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\AiSkill\DTOs\RequestKey;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Services\SkillTrainingService;

/**
 * D 包训练/候选 HTTP（BL-145+146 后端）。
 * 采用裁决只来自 AdoptionPolicy；UI 不得自行计算低风险或跳过评测。
 */
class SkillTrainingController extends BaseController
{
    public function enqueue(Request $request, SkillTrainingService $training)
    {
        $data = $request->validate([
            'mode' => 'required|string|max:20',
            'dataset_manifest' => 'required|array',
            'config_fingerprint' => 'sometimes|nullable|string|max:80',
            'prompt_fingerprint' => 'sometimes|nullable|string|max:80',
            'model_ref' => 'sometimes|nullable|string|max:120',
            'budget' => 'sometimes|nullable|array',
            'cost_micro' => 'sometimes|nullable|integer|min:0',
            'request_key' => 'required|string|max:100',
            'input_fingerprint' => 'sometimes|nullable|string|max:64',
        ]);

        $spec = [
            'mode' => $data['mode'],
            'dataset_manifest' => $data['dataset_manifest'],
            'config_fingerprint' => $data['config_fingerprint'] ?? null,
            'prompt_fingerprint' => $data['prompt_fingerprint'] ?? null,
            'model_ref' => $data['model_ref'] ?? null,
            'budget' => $data['budget'] ?? null,
            'cost_micro' => $data['cost_micro'] ?? null,
        ];
        $fingerprint = (string) ($data['input_fingerprint'] ?? '');
        if ($fingerprint === '') {
            $fingerprint = hash('sha256', json_encode($spec, JSON_UNESCAPED_UNICODE));
        }
        $key = new RequestKey((string) $data['request_key'], $fingerprint);

        return response()->json(['success' => true, 'data' => $training->enqueue($spec, $key)]);
    }

    public function show(string $trainingRunId, SkillTrainingService $training)
    {
        $this->assertId($trainingRunId, 'training_run_id');

        return response()->json(['success' => true, 'data' => $training->get((int) $trainingRunId)]);
    }

    public function index(Request $request, SkillTrainingService $training)
    {
        $data = $request->validate([
            'status' => 'sometimes|nullable|string|max:20',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        return response()->json([
            'success' => true,
            'data' => $training->list($data['status'] ?? null, (int) ($data['limit'] ?? 50)),
        ]);
    }

    public function cancel(string $trainingRunId, SkillTrainingService $training)
    {
        $this->assertId($trainingRunId, 'training_run_id');

        return response()->json(['success' => true, 'data' => $training->cancel((int) $trainingRunId)]);
    }

    public function candidates(Request $request, SkillTrainingService $training)
    {
        $data = $request->validate([
            'training_run_id' => 'sometimes|nullable|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        return response()->json([
            'success' => true,
            'data' => $training->listCandidates(
                isset($data['training_run_id']) ? (int) $data['training_run_id'] : null,
                (int) ($data['limit'] ?? 50),
            ),
        ]);
    }

    public function showCandidate(string $candidateId, SkillTrainingService $training)
    {
        $this->assertId($candidateId, 'candidate_id');

        return response()->json(['success' => true, 'data' => $training->getCandidate((int) $candidateId)]);
    }

    public function decideCandidate(Request $request, string $candidateId, SkillTrainingService $training)
    {
        $this->assertId($candidateId, 'candidate_id');
        $data = $request->validate([
            'action' => 'required|in:evaluate,adopt,reject',
            'context' => 'sometimes|array',
        ]);
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];

        if ($data['action'] === 'reject') {
            return response()->json([
                'success' => true,
                'data' => $training->decideCandidate((int) $candidateId, $context, 'reject'),
            ]);
        }

        $result = $training->decideCandidate((int) $candidateId, [], $data['action'] === 'adopt' ? 'adopt' : null);
        if ($data['action'] === 'adopt') {
            $decision = $result['adoption']['decision'] ?? null;
            // 幂等：已 adopted 直接返回；needs_review/denied 不得伪造 auto 采用
            if (($result['status'] ?? null) === 'adopted') {
                return response()->json(['success' => true, 'data' => $result]);
            }
            throw new SkillDomainException(
                $decision === 'denied' ? 'review_required' : 'review_required',
                'Candidate is not auto-adoptable; human review required.',
                ['decision' => $decision, 'reason' => $result['adoption']['reason'] ?? null],
            );
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function assertId(string $value, string $field): void
    {
        validator([$field => $value], [$field => 'required|integer|min:1'])->validate();
    }
}
