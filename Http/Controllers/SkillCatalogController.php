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

    public function definition(string $skillKey, string $version, SkillCatalog $catalog)
    {
        validator(['skill_key' => $skillKey, 'version' => $version], ['skill_key' => 'required|string|max:100', 'version' => 'required|string|max:50'])->validate();
        $definition = $catalog->resolveSnapshot($skillKey, $version);

        return response()->json(['success' => true, 'data' => [...$definition->toArray(), 'content_body' => $definition->snapshot()]]);
    }

    public function favorites(SkillFavoriteServiceContract $favorites)
    {
        return response()->json(['success' => true, 'data' => $favorites->list()]);
    }

    public function saveFavorite(Request $request, SkillFavoriteServiceContract $favorites)
    {
        $data = $request->validate(['skill_key' => 'required|string|max:100', 'version' => 'required|string|max:50',
            'definition_ref' => 'sometimes|string|max:255', 'scope' => 'required|in:personal,tenant', 'allowed_defaults' => 'sometimes|array|max:20']);

        return response()->json(['success' => true, 'data' => $favorites->save(
            array_intersect_key($data, array_flip(['skill_key', 'version', 'definition_ref'])), $data['allowed_defaults'] ?? [], $data['scope'])]);
    }

    public function removeFavorite(string $favoriteId, SkillFavoriteServiceContract $favorites)
    {
        validator(['favorite_id' => $favoriteId], ['favorite_id' => 'required|integer|min:1'])->validate();
        $favorites->remove(['skill_favorite_id' => (int) $favoriteId]);

        return response()->json(['success' => true]);
    }
}
