<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\UI\Extension;

Extension::load('ui.vue3');

/**
 * Служебные UI-настройки храним в COMMENTS вопроса веб-формы.
 * Пример: type=tel;placeholder=+7 999 123-45-67;autocomplete=tel;validation=phone
 * Это позволяет менять поля и их отображение в админке, не правя Vue-код.
 */
$parseMeta = static function (string $comments): array {
    $result = [];
    foreach (explode(';', $comments) as $part) {
        $part = trim($part);
        if ($part === '' || !str_contains($part, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $part, 2));
        if ($key !== '') {
            $result[$key] = $value;
        }
    }

    return $result;
};

$makeInputName = static function (string $fieldType, string $sid, int $answerId): string {
    return match ($fieldType) {
        'radio', 'dropdown' => 'form_' . $fieldType . '_' . $sid,
        'checkbox', 'multiselect' => 'form_' . $fieldType . '_' . $sid . '[]',
        default => 'form_' . $fieldType . '_' . $answerId,
    };
};

$isAjaxRequest =
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string)($_POST['callback_vue_ajax'] ?? '') === 'Y'
    && (int)($_POST['WEB_FORM_ID'] ?? 0) === (int)$arResult['arForm']['ID'];

if ($isAjaxRequest) {
    global $APPLICATION;
    $APPLICATION->RestartBuffer();

    header('Content-Type: application/json; charset=UTF-8');

    if (($arResult['isFormErrors'] ?? 'N') === 'Y') {
        $errors = [];
        foreach ((array)($arResult['FORM_ERRORS'] ?? []) as $sid => $message) {
            $errors[(string)$sid] = trim(strip_tags((string)$message));
        }

        $errorText = trim(strip_tags((string)($arResult['FORM_ERRORS_TEXT'] ?? '')));

        echo json_encode(
            [
                'success' => false,
                'message' => $errorText !== '' ? $errorText : 'Проверьте заполнение формы.',
                'errors' => $errors,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        die();
    }

    if (($arResult['isFormNote'] ?? 'N') === 'Y') {
        echo json_encode(
            [
                'success' => true,
                'message' => trim(strip_tags((string)($arResult['FORM_NOTE'] ?? 'Спасибо! Мы вам перезвоним.'))),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        die();
    }

    echo json_encode(
        [
            'success' => false,
            'message' => 'Не удалось отправить форму.',
            'errors' => [],
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    die();
}

$formId = (int)$arResult['arForm']['ID'];
$fields = [];

foreach ((array)($arResult['QUESTIONS'] ?? []) as $sid => $question) {
    // form.result.new не во всех версиях Bitrix кладёт COMMENTS вопроса в arResult.
    // Поэтому дочитываем настройки вопроса через штатный API веб-форм.
    $dbQuestion = [];
    $rsQuestion = \CFormField::GetBySID((string)$sid, $formId);
    if ($rsQuestion) {
        $dbQuestion = (array)$rsQuestion->Fetch();
    }
    $answers = [];

    foreach ((array)($question['STRUCTURE'] ?? []) as $answer) {
        if (($answer['ACTIVE'] ?? 'Y') !== 'Y') {
            continue;
        }

        $answers[] = [
            'id' => (int)($answer['ID'] ?? 0),
            'message' => (string)($answer['MESSAGE'] ?? ''),
            'value' => (string)($answer['VALUE'] ?? ''),
            'fieldType' => strtolower((string)($answer['FIELD_TYPE'] ?? 'text')),
        ];
    }

    if ($answers === []) {
        continue;
    }

    $firstAnswer = $answers[0];
    $fieldType = $firstAnswer['fieldType'];

    // В разных версиях/настройках веб-форм подпись вопроса может прийти
    // в разных ключах. CAPTION — основной вариант, остальные — безопасные fallback.
    $label = '';
    foreach (['CAPTION', 'TITLE', 'QUESTION', 'NAME'] as $labelKey) {
        $candidate = trim(strip_tags((string)($question[$labelKey] ?? $dbQuestion[$labelKey] ?? '')));
        if ($candidate !== '') {
            $label = $candidate;
            break;
        }
    }

    // Если заголовок вопроса в админке оставили пустым, используем текст ответа.
    // Это по-прежнему данные из Bitrix, а не хардкод во Vue.
    if ($label === '') {
        $label = trim(strip_tags((string)($firstAnswer['message'] ?? '')));
    }
    if ($label === '') {
        $label = (string)$sid;
    }

    $comments = '';
    foreach (['COMMENTS', 'COMMENTS_TEXT', 'DESCRIPTION'] as $commentKey) {
        // COMMENTS надёжнее брать из CFormField: это штатное поле вопроса веб-формы.
        $candidate = trim((string)($dbQuestion[$commentKey] ?? $question[$commentKey] ?? ''));
        if ($candidate !== '') {
            $comments = $candidate;
            break;
        }
    }
    $meta = $parseMeta($comments);

    // Основной источник UI/валидации — метаданные вопроса из админки.
    // Fallback по SID нужен для старых/нестандартных сборок Bitrix, где COMMENTS
    // не попадает в результат CFormField::GetBySID(). Сам список полей при этом
    // по-прежнему полностью приходит из веб-формы, во Vue ничего не зашито.
    $inputType = strtolower((string)($meta['type'] ?? ($fieldType === 'text' ? 'text' : $fieldType)));
    $validation = strtolower((string)($meta['validation'] ?? ''));
    $normalizedSid = strtoupper((string)$sid);

    if ($validation === '' && preg_match('/(^|_)(PHONE|TEL|MOBILE)(_|$)/', $normalizedSid)) {
        $validation = 'phone';
    }

    if ($validation === 'phone' && $inputType === 'text') {
        $inputType = 'tel';
    }

    $requiredRaw = $dbQuestion['REQUIRED'] ?? $question['REQUIRED'] ?? 'N';
    $required = in_array($requiredRaw, ['Y', '1', 1, true], true);

    // В некоторых сборках признак обязательности может встретиться в структуре ответа.
    if (!$required) {
        foreach ((array)($question['STRUCTURE'] ?? []) as $answerStructure) {
            $answerRequired = $answerStructure['REQUIRED'] ?? 'N';
            if (in_array($answerRequired, ['Y', '1', 1, true], true)) {
                $required = true;
                break;
            }
        }
    }

    $fields[] = [
        'sid' => (string)$sid,
        'label' => $label,
        'required' => $required,
        'fieldType' => $fieldType,
        'inputType' => $inputType,
        'placeholder' => (string)($meta['placeholder'] ?? ''),
        'autocomplete' => (string)($meta['autocomplete'] ?? ($validation === 'phone' ? 'tel' : 'off')),
        'validation' => $validation,
        'name' => $makeInputName($fieldType, (string)$sid, (int)$firstAnswer['id']),
        'answers' => $answers,
    ];
}

$rootId = 'callback-form-app-' . $formId;
$configId = 'callback-form-config-' . $formId;

$config = [
    'formId' => $formId,
    'title' => (string)($arResult['arForm']['NAME'] ?? 'Обратный звонок'),
    'buttonText' => (string)($arResult['arForm']['BUTTON'] ?? 'Отправить'),
    'delayMs' => 20000,
    'sessid' => bitrix_sessid(),
    'fields' => $fields,
];

$configJson = json_encode(
    $config,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);
?>
<div
    id="<?=htmlspecialcharsbx($rootId)?>"
    data-callback-form-app
    data-config-id="<?=htmlspecialcharsbx($configId)?>"
></div>
<script type="application/json" id="<?=htmlspecialcharsbx($configId)?>"><?=$configJson?></script>
