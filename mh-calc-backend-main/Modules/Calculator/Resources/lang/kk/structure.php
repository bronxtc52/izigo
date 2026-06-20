<?php
return [
    'default_username' => 'USER :id',
    'add_node' => [
        'structure_token' => 'Құрылымның ID',
        'top_node_id' => 'Ата-ана ұяшығының ID',
        'position' => 'Бөлім',
        'sponsor_id' => 'Демеушінің ID',
        'username' => 'Аты',
        'package_id' => 'Келісімшарт',
    ],

    'validate' => [
        'top_node_not_exists' => 'Ата-ана ұяшығы табылмады',
        'sponsor_not_valid' => 'Демеуші қате таңдалған',
        'username_not_unique' => 'Аты бірегей болуы керек',
        'position_busy' => 'Бұл орын бос емес',
        'forbidden' => 'Бұл әрекетті орындауға құқығыңыз жоқ',
        'not_found' => 'Құрылым табылмады',
        'username_regex' => 'Атауда тек әріптер, сандар, бос орындар, дефистер, нүктелер, @ таңбасы және төменгі сызық рұқсат етілген.',
    ],

    "branch_1" => 'сол жақ бөлім',
    "branch_to_1" => 'сол жақ бөлімге',
    "branch_from_1" => 'сол жақ бөлімнен',

    "branch_2" => 'оң жақ бөлім',
    "branch_to_2" => 'оң жақ бөлімге',
    "branch_from_2" => 'оң жақ бөлімнен',
];
