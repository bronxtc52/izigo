<?php
return [
    'default_username' => 'USER :id',
    'add_node' => [
        'structure_token' => 'ID структуры',
        'top_node_id' => 'ID родительской ячейки',
        'position' => 'Ветка',
        'sponsor_id' => 'ID спонсора',
        'username' => 'Имя',
        'package_id' => 'Контракт',
    ],

    'validate' => [
        'top_node_not_exists' => 'Не найдена родительская ячейка',
        'sponsor_not_valid' => 'Неверно выбран спонсор',
        'username_not_unique' => 'Имя должно быть уникальным',
        'position_busy' => 'Данная позиция занята',
        'forbidden' => 'У Вас нет прав на это действие',
        'not_found' => 'Структура не найдена',
        'username_regex' => 'В названии допустимы только буквы, цифры, пробелы, дефисы, точки, @ и нижнее подчеркивание.',
    ],

    "branch_1" => 'левая ветка',
    "branch_to_1" => 'левую ветку',
    "branch_from_1" => 'левой ветки',

    "branch_2" => 'правая ветка',
    "branch_to_2" => 'правую ветку',
    "branch_from_2" => 'правой ветки',
];
