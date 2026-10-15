<?php

use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/** Additive platform-only Skill permissions, shared by fresh installs and upgrades. */
return new class extends Migration
{
    /** @var array<int, array{name: string, display_name: string, group: string}> */
    private const PERMISSIONS = [
        ['name' => 'platform.ai.skill.view', 'display_name' => '查看AI技能治理（平台）', 'group' => 'ai-skill'],
        ['name' => 'platform.ai.skill.manage', 'display_name' => '管理AI技能治理（平台）', 'group' => 'ai-skill'],
    ];

    /** 平台级角色：获得这些权限（与既有 platform.* 一致，不含 tenant_admin） */
    private const PLATFORM_ROLES = ['super_admin', 'platform_admin'];

    public function up(): void
    {
        // RBAC 表可能先于本迁移（最小安装）——表不在就跳过，等 Seeder 覆盖
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $idGen = app(IdGeneratorContract::class);
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            // 幂等：name 唯一键，已存在则复用其 permission_id
            $permId = DB::table('permissions')->where('name', $permission['name'])->value('permission_id');

            if (! $permId) {
                $permId = $idGen->generate();

                DB::table('permissions')->insert([
                    'permission_id' => $permId,
                    'name' => $permission['name'],
                    'display_name' => $permission['display_name'],
                    'group' => $permission['group'],
                    'description' => $permission['display_name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->grant((int) $permId, self::PLATFORM_ROLES, $now);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            $permId = DB::table('permissions')->where('name', $permission['name'])->value('permission_id');

            if (! $permId) {
                continue;
            }

            // 显式删映射，不依赖外键 ON DELETE CASCADE（测试环境 DB_FOREIGN_KEYS=false）
            if (Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->where('permission_id', $permId)->delete();
            }

            DB::table('permissions')->where('permission_id', $permId)->delete();
        }
    }

    /**
     * 幂等授权给全局角色（tenant_id 为 NULL 的系统角色）。
     *
     * @param  array<int, string>  $roleNames
     */
    private function grant(int $permissionId, array $roleNames, CarbonInterface $now): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', $roleNames)
            ->whereNull('tenant_id')
            ->pluck('role_id')
            ->all();

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => (int) $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
