<?php
/**
 * You normally don't need this file: open setup.php (⚙ Setup in the app) and it writes lib/config.php for you.
 *
 * To configure by hand instead, copy this file to lib/config.php.
 * lib/ is kept private (.htaccess / router.php), and config.php is git-ignored.
 * Token: https://www.notion.so/profile/integrations → New integration (Internal), capability "Read content".
 * Then in Notion open each database → ••• → Connections → add the integration.
 * Ids are easiest to get by running setup.php once; 'database' is the id in the database's URL.
 */
return [
    'notion_token' => '',   // starts with "ntn_" or "secret_"

    // One entry per campaign = one Notion database of player characters.
    // 'columns' maps what the app needs to your column names ('' = not used).
    'campaigns' => [
        'my-campaign' => [
            'name'        => 'My Campaign',
            'data_source' => '00000000-0000-0000-0000-000000000000',
            'database'    => '00000000-0000-0000-0000-000000000000',
            'columns'     => [
                'name' => 'Name', 'player' => 'Player', 'level' => 'Level', 'ac' => 'AC',
                'hp_max' => 'Max HP', 'init_mod' => 'Init Mod', 'link' => 'Link',
            ],
        ],
    ],

    // Monster database (optional).
    'monsters' => [
        'data_source' => '00000000-0000-0000-0000-000000000000',
        'database'    => '00000000-0000-0000-0000-000000000000',
        'columns'     => [
            'name' => 'Name', 'cr' => 'CR', 'ac' => 'AC', 'hp_max' => 'Hit Points', 'init_mod' => 'Initiative',
            'type' => 'Type', 'source' => 'Source', 'legendary' => 'Legendary',
        ],
        'types' => ['cr' => 'number'],   // 'number', 'formula' or text; numbers make CR searches fastest
    ],
];
