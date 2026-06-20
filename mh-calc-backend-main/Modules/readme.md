## Ранги

/docs/#calculator-GETapi-v1-calculator-ranks

## Ранги

/docs/#calculator-GETapi-v1-calculator-packages

## Локали

/docs/#configizigo-GETapi-v1-locales

## Структура и пользователи

/structure-create
/structure
/sponsors
/add-user
/update-user
/delete-user
/user-details

        // Пакеты
        Route::get('/packages', [PackageController::class, 'index']);

        // Ранги
        Route::get('/ranks', [RankController::class, 'index']);
