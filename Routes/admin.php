<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\AiSkill\Http\Controllers\SkillAdminController;

/**
 * 平台 Admin 治理路由（独立于租户 Console）。
 * 权限：platform.ai.skill.view / platform.ai.skill.manage（集成者播种）。
 * 不套用租户 setting.update；不默认全租户采样。
 * 评测判据只在此入口冻结（BL-144）：租户侧与训练器都无法改题。
 */
Route::prefix('ai-skills')->middleware('ai-skill.enabled')->group(function () {
    Route::middleware('rbac.permission:platform.ai.skill.view')->group(function () {
        Route::get('/versions', [SkillAdminController::class, 'versions']);
        Route::get('/candidates', [SkillAdminController::class, 'candidates']);
        Route::get('/eval-cases', [SkillAdminController::class, 'evalCases']);
        Route::get('/training-runs', [SkillAdminController::class, 'trainingRuns']);
    });

    Route::middleware('rbac.permission:platform.ai.skill.manage')->group(function () {
        Route::patch('/versions/{definitionId}/status', [SkillAdminController::class, 'changeVersionStatus']);
        Route::post('/training-runs', [SkillAdminController::class, 'enqueueTraining']);
        Route::post('/candidates/{candidateId}/decide', [SkillAdminController::class, 'decideCandidate']);
        Route::post('/eval-cases', [SkillAdminController::class, 'freezeEvalCases']);
        Route::post('/candidates/{candidateId}/evaluate', [SkillAdminController::class, 'evaluateCandidate']);
    });
});
