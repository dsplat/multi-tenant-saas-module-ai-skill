<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Http\Controllers;

use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\AiSkill\Services\SkillAdminService;

/**
 * 平台 Admin 治理 HTTP（独立 /api/v1/admin 路由）。
 * 不与租户 Console API 混用；写操作需 platform.ai.skill.manage。
 */
class SkillAdminController extends BaseController
{
    public function versions(Request $request, SkillAdminService $admin)
    {
        $data = $request->validate([
            'tenant_ids' => 'sometimes|nullable|array',
            'tenant_ids.*' => 'integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        $tenantIds = null;
        if (array_key_exists('tenant_ids', $data) && $data['tenant_ids'] !== null) {
            $tenantIds = array_map('intval', (array) $data['tenant_ids']);
        }

        return response()->json([
            'success' => true,
            'data' => $admin->listGovernedVersions($tenantIds, (int) ($data['limit'] ?? 50)),
        ]);
    }

    public function changeVersionStatus(Request $request, string $definitionId, SkillAdminService $admin)
    {
        $data = $request->validate([
            'expected_status' => 'required|in:draft,published,disabled',
            'status' => 'required|in:draft,published,disabled',
        ]);
        validator(['definition_id' => $definitionId], ['definition_id' => 'required|integer|min:1'])->validate();

        return response()->json([
            'success' => true,
            'data' => $admin->changeVersionStatus((int) $definitionId, (string) $data['expected_status'], (string) $data['status']),
        ]);
    }

    public function enqueueTraining(Request $request, SkillAdminService $admin)
    {
        $data = $request->validate([
            'tenant_id' => 'required|integer|min:1',
            'mode' => 'required|string|max:20',
            'dataset_manifest' => 'required|array',
            'config_fingerprint' => 'sometimes|nullable|string|max:80',
            'prompt_fingerprint' => 'sometimes|nullable|string|max:80',
            'model_ref' => 'sometimes|nullable|string|max:120',
            'budget' => 'sometimes|nullable|array',
            'request_key' => 'required|string|max:100',
            'input_fingerprint' => 'sometimes|nullable|string|max:64',
        ]);

        return response()->json([
            'success' => true,
            'data' => $admin->enqueueTrainingForTenant(
                (int) $data['tenant_id'],
                [
                    'mode' => $data['mode'],
                    'dataset_manifest' => $data['dataset_manifest'],
                    'config_fingerprint' => $data['config_fingerprint'] ?? null,
                    'prompt_fingerprint' => $data['prompt_fingerprint'] ?? null,
                    'model_ref' => $data['model_ref'] ?? null,
                    'budget' => $data['budget'] ?? null,
                ],
                (string) $data['request_key'],
                (string) ($data['input_fingerprint'] ?? ''),
            ),
        ]);
    }

    public function trainingRuns(Request $request, SkillAdminService $admin)
    {
        $data = $request->validate([
            'tenant_id' => 'required|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        return response()->json([
            'success' => true,
            'data' => $admin->listTrainingRuns(
                (int) $data['tenant_id'],
                (int) ($data['limit'] ?? 20),
            ),
        ]);
    }

    public function candidates(Request $request, SkillAdminService $admin)
    {
        $data = $request->validate([
            'tenant_id' => 'sometimes|nullable|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        return response()->json([
            'success' => true,
            'data' => $admin->listCandidates(
                isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
                (int) ($data['limit'] ?? 50),
            ),
        ]);
    }

    public function decideCandidate(Request $request, string $candidateId, SkillAdminService $admin)
    {
        $data = $request->validate([
            'action' => 'required|in:evaluate,adopt,reject',
            'context' => 'sometimes|array',
        ]);
        validator(['candidate_id' => $candidateId], ['candidate_id' => 'required|integer|min:1'])->validate();

        return response()->json([
            'success' => true,
            'data' => $admin->decideCandidate(
                (int) $candidateId,
                (string) $data['action'],
                is_array($data['context'] ?? null) ? $data['context'] : [],
            ),
        ]);
    }

    public function evalCases(Request $request, SkillAdminService $admin)
    {
        $data = $request->validate([
            'tenant_id' => 'required|integer|min:1',
            'dataset_id' => 'required|string|max:100',
            'dataset_version' => 'required|string|max:50',
            'limit' => 'sometimes|integer|min:1|max:200',
        ]);

        return response()->json([
            'success' => true,
            'data' => $admin->listEvalCases(
                (int) $data['tenant_id'],
                (string) $data['dataset_id'],
                (string) $data['dataset_version'],
                (int) ($data['limit'] ?? 50),
            ),
        ]);
    }

    public function freezeEvalCases(Request $request, SkillAdminService $admin)
    {
        $data = $request->validate([
            'tenant_id' => 'required|integer|min:1',
            'dataset_id' => 'required|string|max:100',
            'dataset_version' => 'required|string|max:50',
            'split' => 'required|in:discovery,validation',
            'cases' => 'required|array|min:1|max:200',
            'cases.*.skill_key' => 'required|string|max:100',
            'cases.*.case_key' => 'required|string|max:64',
            'cases.*.input' => 'required|array',
            'cases.*.asserts' => 'required|array',
            'cases.*.rubric_version' => 'sometimes|nullable|string|max:50',
        ]);

        return response()->json([
            'success' => true,
            'data' => $admin->freezeEvalCases(
                (int) $data['tenant_id'],
                (string) $data['dataset_id'],
                (string) $data['dataset_version'],
                (string) $data['split'],
                (array) $data['cases'],
            ),
        ]);
    }

    public function evaluateCandidate(Request $request, string $candidateId, SkillAdminService $admin)
    {
        $data = $request->validate([
            'dataset_id' => 'required|string|max:100',
            'dataset_version' => 'required|string|max:50',
        ]);
        validator(['candidate_id' => $candidateId], ['candidate_id' => 'required|integer|min:1'])->validate();

        return response()->json([
            'success' => true,
            'data' => $admin->enqueueEvaluation(
                (int) $candidateId,
                (string) $data['dataset_id'],
                (string) $data['dataset_version'],
            ),
        ]);
    }
}
