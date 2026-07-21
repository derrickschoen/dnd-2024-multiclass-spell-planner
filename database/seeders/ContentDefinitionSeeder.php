<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Grants\GrantRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ContentDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $magicInitiateRules = [
            [
                'kind' => 'choice_from_list',
                'rule_key' => 'magic-initiate-cantrips',
                'count' => 2,
                'bucket' => 'cantrip_known',
                'list' => '$config.chosen_list',
                'level_min' => 0,
                'level_max' => 0,
                'with_slots' => false,
                'distinct_config_by' => 'chosen_list',
            ],
            [
                'kind' => 'choice_from_list',
                'rule_key' => 'magic-initiate-level-one',
                'count' => 1,
                'bucket' => 'known',
                'list' => '$config.chosen_list',
                'level_min' => 1,
                'level_max' => 1,
                'with_slots' => true,
                'free_cast' => [
                    'uses' => 1,
                    'recovery' => 'long_rest',
                    'pool_scope' => 'per_spell',
                ],
            ],
        ];
        $humanRules = [[
            'kind' => 'grant_source',
            'rule_key' => 'human-origin-feat',
            'source_type' => 'feat',
            'definition_key_config' => 'origin_feat_key',
            'child_config_config' => 'origin_feat_config',
        ]];
        $backgroundRules = [[
            'kind' => 'grant_source',
            'rule_key' => 'background-origin-feat',
            'source_type' => 'feat',
            'definition_key_config' => 'origin_feat_key',
            'child_config_config' => 'origin_feat_config',
        ]];

        foreach ([$magicInitiateRules, $humanRules, $backgroundRules] as $rules) {
            foreach ($rules as $rule) {
                GrantRule::fromArray($rule);
            }
        }

        DB::table('feat_definitions')->updateOrInsert(
            ['content_key' => '2024:feat:magic-initiate'],
            [
                'name' => 'Magic Initiate',
                'rules_edition' => '2024',
                'category' => 'origin',
                'repeatable' => true,
                'grant_rules' => json_encode($magicInitiateRules, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('species_definitions')->updateOrInsert(
            ['content_key' => '2024:species:human'],
            [
                'name' => 'Human',
                'rules_edition' => '2024',
                'grant_rules' => json_encode($humanRules, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('background_definitions')->updateOrInsert(
            ['content_key' => '2024:background:custom'],
            [
                'name' => 'Custom Background',
                'rules_edition' => '2024',
                'grant_rules' => json_encode($backgroundRules, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
