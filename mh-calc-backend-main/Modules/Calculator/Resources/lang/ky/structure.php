<?php
return [
    'default_username' => 'USER :id',
    'add_node' => [
        'structure_token' => 'Түзүмдүн ID',
        'top_node_id' => 'Башкы түйүн ID',
        'position' => 'Бутак',
        'sponsor_id' => 'Спонсордун ID',
        'username' => 'Аты',
        'package_id' => 'Контракт',
    ],

    'validate' => [
        'top_node_not_exists' => 'Башкы түйүн табылган жок',
        'sponsor_not_valid' => 'Спонсор туура эмес тандалган',
        'username_not_unique' => 'Аты уникалдуу болушу керек',
        'position_busy' => 'Бул позиция бош эмес',
        'forbidden' => 'Сиздин бул аракетти жасоого укугуңуз жок',
        'not_found' => 'Түзүм табылган жок',
        'username_regex' => 'Аталышта арип, сан, боштук, дефис, чекит, @ белгиси жана астыңкы сызык гана колдонулат.',
    ],

    "branch_1" => 'сол бутак',
    "branch_to_1" => 'сол бутакка',
    "branch_from_1" => 'сол бутактан',

    "branch_2" => 'оң бутак',
    "branch_to_2" => 'оң бутакка',
    "branch_from_2" => 'оң бутактан',
];
