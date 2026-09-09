<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

use Bitrix\Main\Loader;

$APPLICATION->SetTitle('Обратный звонок');

if (!Loader::includeModule('form')) {
    ShowError('Модуль «Веб-формы» не установлен.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

$form = CForm::GetBySID('CALLBACK_FORM')->Fetch();

if (!$form) {
    ShowError('Форма CALLBACK_FORM не найдена. Выполните миграцию sprint.migration.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}
?>
<div style="max-width: 760px; margin: 40px auto; padding: 0 20px;">
    <h1>Демо формы обратного звонка</h1>
    <p>Модальное окно откроется автоматически через 20 секунд.</p>
</div>
<?php
$APPLICATION->IncludeComponent(
    'bitrix:form.result.new',
    'callback_vue',
    [
        'WEB_FORM_ID' => (string)$form['ID'],
        'IGNORE_CUSTOM_TEMPLATE' => 'Y',
        'USE_EXTENDED_ERRORS' => 'Y',
        'SEF_MODE' => 'N',
        'CACHE_TYPE' => 'N',
        'LIST_URL' => '',
        'EDIT_URL' => '',
        'SUCCESS_URL' => '',
        'CHAIN_ITEM_TEXT' => '',
        'CHAIN_ITEM_LINK' => '',
    ],
    false
);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
