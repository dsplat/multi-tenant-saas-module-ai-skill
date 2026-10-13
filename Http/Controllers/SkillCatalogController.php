<?php

namespace MultiTenantSaas\Modules\AiSkill\Http\Controllers;

use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillFavoriteServiceContract;
use MultiTenantSaas\Modules\AiSkill\Services\SkillCatalog;

class SkillCatalogController extends BaseController
{
    public function index(Request $request, SkillCatalog $catalog)
    {
        $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:100']);

        return response()->json(['success' => true, 'data' => $catalog->list((int) ($data['limit'] ?? 50))]);
    }

    public function favorites(SkillFavoriteServiceContract $favorites)
    {
        return response()->json(['success' => true, 'data' => $favorites->list()]);
    }

    public function saveFavorite(Request $request, SkillFavoriteServiceContract $favorites)
    {
        $data = $request->validate(['skill_key' => 'required|string|max:100', 'version' => 'required|string|max:50',
            'scope' => 'required|in:personal,tenant', 'allowed_defaults' => 'sometimes|array|max:20']);

        return response()->json(['success' => true, 'data' => $favorites->save(
            ['skill_key' => $data['skill_key'], 'version' => $data['version']], $data['allowed_defaults'] ?? [], $data['scope'])]);
    }

    public function removeFavorite(string $favoriteId, SkillFavoriteServiceContract $favorites)
    {
        validator(['favorite_id' => $favoriteId], ['favorite_id' => 'required|integer|min:1'])->validate();
        $favorites->remove(['skill_favorite_id' => (int) $favoriteId]);

        return response()->json(['success' => true]);
    }
}
