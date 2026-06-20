<?php
return [
    'default_username' => 'USER :id',
    'add_node' => [
        'structure_token' => 'Бүтцийн ID',
        'top_node_id' => 'Эцэг ячейкний ID',
        'position' => 'Салбар',
        'sponsor_id' => 'Ивээн тэтгэгчийн ID',
        'username' => 'Нэр',
        'package_id' => 'Гэрээ',
    ],

    'validate' => [
        'top_node_not_exists' => 'Эцэг ячейк олдсонгүй',
        'sponsor_not_valid' => 'Ивээн тэтгэгч буруу сонгогдсон',
        'username_not_unique' => 'Нэр дахин давтагдашгүй байх ёстой',
        'position_busy' => 'Энэ байр суурь аль хэдийн эзэлсэн байна',
        'forbidden' => 'Танд энэ үйлдлийг хийх эрх байхгүй',
        'not_found' => 'Бүтэц олдсонгүй',
        'username_regex' => 'Нэрэнд зөвхөн үсэг, тоо, зай, зураас, цэг, @ тэмдэг болон доогуур зураас ашиглах боломжтой.',
    ],

    "branch_1" => 'зүүн салбар',
    "branch_to_1" => 'зүүн салбар руу',
    "branch_from_1" => 'зүүн салбараас',

    "branch_2" => 'баруун салбар',
    "branch_to_2" => 'баруун салбар руу',
    "branch_from_2" => 'баруун салбараас',
];
