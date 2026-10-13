<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\AiSkill\Http\Controllers\SkillCatalogController;

Route::prefix('tenant/ai-skills')->middleware(['ai-skill.enabled', 'rbac.permission:ai.capability.view'])->group(function () {
    Route::get('/', [SkillCatalogController::class, 'index']);
    Route::get('/favorites', [SkillCatalogController::class, 'favorites']);
    Route::post('/favorites', [SkillCatalogController::class, 'saveFavorite']);
    Route::delete('/favorites/{favoriteId}', [SkillCatalogController::class, 'removeFavorite']);
});
