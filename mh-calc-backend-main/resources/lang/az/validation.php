<?php
return [
    'required' => '":attribute" sahəsi doldurulması mütləqdir.',
    'exists' => 'Seçilmiş ":attribute" dəyəri yanlışdır.',
    'between' => [
        'numeric' => '":attribute" sahəsi :min ilə :max arasında olmalıdır.',
        'file' => '":attribute" sahəsindəki faylın ölçüsü :min ilə :max Kilobayt arasında olmalıdır.',
        'string' => '":attribute" sahəsindəki simvolların sayı :min ilə :max arasında olmalıdır.',
        'array' => '":attribute" sahəsindəki elementlərin sayı :min ilə :max arasında olmalıdır.',
    ],
    'int' => '":attribute" sahəsi tam ədəd olmalıdır.',
    'integer' => '":attribute" sahəsi tam ədəd olmalıdır.',
    'max' => [
        'numeric' => '":attribute" sahəsi :max-dan çox ola bilməz.',
        'file' => '":attribute" sahəsindəki faylın ölçüsü :max Kilobayt-dan çox ola bilməz.',
        'string' => '":attribute" sahəsindəki simvolların sayı :max-dan çox ola bilməz.',
        'array' => '":attribute" sahəsindəki elementlərin sayı :max-dan çox ola bilməz.',
    ],
    'min' => [
        'numeric' => '":attribute" sahəsi :min-dən az olmamalıdır.',
        'file' => '":attribute" sahəsindəki faylın ölçüsü :min Kilobayt-dan az olmamalıdır.',
        'string' => '":attribute" sahəsindəki simvolların sayı :min-dən az olmamalıdır.',
        'array' => '":attribute" sahəsindəki elementlərin sayı :min-dən az olmamalıdır.',
    ],

    'string' => '":attribute" sahəsi mətn olmalıdır.',
    'unique' => 'Belə bir ":attribute" dəyəri artıq mövcuddur.',
];
