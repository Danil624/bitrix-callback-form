<?php

namespace Sprint\Migration;

class Version20260903170000 extends Version
{
    protected $description = 'Веб-форма «Обратный звонок» для тестового задания';
    protected $moduleVersion = '5.14.0';

    public function up()
    {
        if (!\Bitrix\Main\Loader::includeModule('form')) {
            $this->outError('Модуль form не установлен');
            return false;
        }

        $formHelper = $this->getHelperManager()->Form();

        $siteIds = [];
        $by = 'sort';
        $order = 'asc';
        $siteDb = \CSite::GetList($by, $order, ['ACTIVE' => 'Y']);
        while ($site = $siteDb->Fetch()) {
            $siteIds[] = $site['ID'];
        }

        if ($siteIds === []) {
            $siteIds = ['s1'];
        }

        $formId = $formHelper->saveForm([
            'NAME' => 'Обратный звонок',
            'SID' => 'CALLBACK_FORM',
            'C_SORT' => 100,
            'BUTTON' => 'Отправить',
            'USE_CAPTCHA' => 'N',
            'DESCRIPTION' => 'Форма обратного звонка. UI-метаданные полей задаются в COMMENTS.',
            'DESCRIPTION_TYPE' => 'text',
            'arSITE' => $siteIds,
            'arGROUP' => [
                'everyone' => 10,
                'administrators' => 30,
            ],
        ]);

        if (!$formId) {
            $this->outError('Не удалось создать веб-форму');
            return false;
        }

        $formHelper->saveFields((int)$formId, [
            [
                'SID' => 'NAME',
                'TITLE' => 'Имя',
                'C_SORT' => 100,
                'REQUIRED' => 'Y',
                'COMMENTS' => 'type=text;placeholder=Ваше имя;autocomplete=name',
                'ANSWERS' => [
                    [
                        'MESSAGE' => 'Имя',
                        'VALUE' => '',
                        'FIELD_TYPE' => 'text',
                        'FIELD_WIDTH' => 40,
                        'C_SORT' => 100,
                        'ACTIVE' => 'Y',
                    ],
                ],
            ],
            [
                'SID' => 'PHONE',
                'TITLE' => 'Телефон',
                'C_SORT' => 200,
                'REQUIRED' => 'Y',
                'COMMENTS' => 'type=tel;placeholder=+7 999 123-45-67;autocomplete=tel;validation=phone',
                'ANSWERS' => [
                    [
                        'MESSAGE' => 'Телефон',
                        'VALUE' => '',
                        'FIELD_TYPE' => 'text',
                        'FIELD_WIDTH' => 40,
                        'C_SORT' => 100,
                        'ACTIVE' => 'Y',
                    ],
                ],
            ],
            [
                'SID' => 'COMMENT',
                'TITLE' => 'Комментарий',
                'C_SORT' => 300,
                'REQUIRED' => 'N',
                'COMMENTS' => 'placeholder=Ваш комментарий',
                'ANSWERS' => [
                    [
                        'MESSAGE' => 'Комментарий',
                        'VALUE' => '',
                        'FIELD_TYPE' => 'textarea',
                        'FIELD_WIDTH' => 40,
                        'FIELD_HEIGHT' => 5,
                        'C_SORT' => 100,
                        'ACTIVE' => 'Y',
                    ],
                ],
            ],
            [
                'SID' => 'CONSENT',
                'TITLE' => 'Я согласен на обработку персональных данных',
                'C_SORT' => 400,
                'REQUIRED' => 'Y',
                'ANSWERS' => [
                    [
                        'MESSAGE' => 'Да',
                        'VALUE' => 'Y',
                        'FIELD_TYPE' => 'checkbox',
                        'C_SORT' => 100,
                        'ACTIVE' => 'Y',
                    ],
                ],
            ],
        ]);

        $formHelper->saveStatuses((int)$formId, [
            [
                'TITLE' => 'Новый',
                'DESCRIPTION' => 'Новая заявка на обратный звонок',
                'C_SORT' => 100,
                'ACTIVE' => 'Y',
                'DEFAULT_VALUE' => 'Y',
            ],
        ]);

        $this->outSuccess('Создана/обновлена форма CALLBACK_FORM (ID: %d)', $formId);
        return true;
    }

    public function down()
    {
        if (!\Bitrix\Main\Loader::includeModule('form')) {
            $this->outError('Модуль form не установлен');
            return false;
        }

        $formHelper = $this->getHelperManager()->Form();
        $formHelper->deleteFormIfExists('CALLBACK_FORM');

        $this->outSuccess('Форма CALLBACK_FORM удалена');
        return true;
    }
}
