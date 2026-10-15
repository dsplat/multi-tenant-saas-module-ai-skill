<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\AiSkill\Http\Controllers\SkillCatalogController;
use MultiTenantSaas\Modules\AiSkill\Http\Controllers\SkillRecommendationController;
use MultiTenantSaas\Modules\AiSkill\Http\Controllers\SkillRunController;
use MultiTenantSaas\Modules\AiSkill\Http\Controllers\SkillTrainingController;

Route::prefix('tenant/ai-skills')->middleware(['ai-skill.enabled', 'rbac.permission:ai.capability.view'])->group(function () {
    Route::patch('/definitions/{definitionId}/status', [SkillCatalogController::class, 'changeStatus']);
    Route::get('/', [SkillCatalogController::class, 'index']);
    Route::get('/{skillKey}/versions/{version}', [SkillCatalogController::class, 'definition']);
    Route::get('/favorites', [SkillCatalogController::class, 'favorites']);
    Route::post('/favorites', [SkillCatalogController::class, 'saveFavorite']);
    Route::delete('/favorites/{favoriteId}', [SkillCatalogController::class, 'removeFavorite']);

    // C 包固定执行（BL-143）：start/advance/cancel + 恢复读取
    Route::get('/runs', [SkillRunController::class, 'index']);
    Route::post('/runs', [SkillRunController::class, 'start']);
    Route::get('/runs/{runId}', [SkillRunController::class, 'show']);
    Route::post('/runs/{runId}/advance', [SkillRunController::class, 'advance']);
    Route::post('/runs/{runId}/cancel', [SkillRunController::class, 'cancel']);

    // D 包训练/候选（BL-145+146 后端）
    Route::get('/training-runs', [SkillTrainingController::class, 'index']);
    Route::post('/training-runs', [SkillTrainingController::class, 'enqueue'])->middleware('rbac.permission:setting.update');
    Route::get('/training-runs/{trainingRunId}', [SkillTrainingController::class, 'show']);
    Route::post('/training-runs/{trainingRunId}/cancel', [SkillTrainingController::class, 'cancel'])->middleware('rbac.permission:setting.update');
    Route::get('/candidates', [SkillTrainingController::class, 'candidates']);
    Route::get('/candidates/{candidateId}', [SkillTrainingController::class, 'showCandidate']);
    Route::post('/candidates/{candidateId}/decide', [SkillTrainingController::class, 'decideCandidate'])->middleware('rbac.permission:setting.update');

    // D 包推荐与事件（BL-145）
    Route::get('/recommendations', [SkillRecommendationController::class, 'index']);
    Route::post('/recommendations', [SkillRecommendationController::class, 'recommend']);
    Route::get('/recommendations/{recommendationId}', [SkillRecommendationController::class, 'show']);
    Route::post('/recommendations/{recommendationId}/events', [SkillRecommendationController::class, 'event']);
});
