<?php
return [
    'default_username' => 'USER :id',
    'add_node' => [
        'structure_token' => 'Struktur ID',
        'top_node_id' => 'Üst düyün ID',
        'position' => 'Şöbə',
        'sponsor_id' => 'Sponsor ID',
        'username' => 'Ad',
        'package_id' => 'Müqavilə',
    ],

    'validate' => [
        'top_node_not_exists' => 'Üst düyün tapılmadı',
        'sponsor_not_valid' => 'Sponsor düzgün seçilməmişdir',
        'username_not_unique' => 'Ad unikal olmalıdır',
        'position_busy' => 'Bu mövqe tutulmuşdur',
        'forbidden' => 'Bu hərəkət üçün icazəniz yoxdur',
        'not_found' => 'Struktur tapılmadı',
        'username_regex' => 'Adda yalnız hərflər, rəqəmlər, boşluqlar, defislər, nöqtələr, @ simvolu və alt xətt simvolları icazə verilir.',
    ],

    "branch_1" => 'sol şöbə',
    "branch_to_1" => 'sol şöbəyə',
    "branch_from_1" => 'sol şöbədən',

    "branch_2" => 'sağ şöbə',
    "branch_to_2" => 'sağ şöbəyə',
    "branch_from_2" => 'sağ şöbədən',
];
