<?php

declare(strict_types=1);

return [
    'enabled' => false,

    // Platform operator ID => explicitly authorized tenant IDs; never inferred from membership.
    'platform_tenant_scopes' => [],

    // 异步训练执行器（WP-147a）。默认关闭：登记任务与真实烧 token 必须是两个动作。
    'training' => [
        'enabled' => false,
        // 模型可读工具白名单：不在名单内的 tool_deps 直接丢弃该草稿
        'tool_allowlist' => [],
        'max_candidates' => 3,
        'max_evidence_items' => 40,
        'max_output_tokens' => 4000,
        'temperature' => 0.2,
        'budget' => [
            'max_calls' => 1,
        ],
    ],

    // 独立评测（WP-147b）。判据只由本入口写入候选的 validation 字段。
    'evaluation' => [
        'enabled' => false,
        // 采用门最低 holdout 样本数，与 AdoptionPolicy 的数值下限同源
        'min_holdout_cases' => 10,
        'max_cases' => 50,
        'repeat' => 1,
        'max_output_tokens' => 2000,
        'tool_allowlist' => [],
    ],
];
