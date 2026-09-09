(function () {
    'use strict';

    function boot() {
        if (!window.BX || !BX.Vue3 || !BX.Vue3.BitrixVue) {
            return;
        }

        document.querySelectorAll('[data-callback-form-app]').forEach(function (root) {
            if (root.dataset.mounted === 'Y') {
                return;
            }

            var configNode = document.getElementById(root.dataset.configId || '');
            if (!configNode) {
                return;
            }

            var config;
            try {
                config = JSON.parse(configNode.textContent || '{}');
            } catch (error) {
                console.error('Callback form config parse error', error);
                return;
            }

            root.dataset.mounted = 'Y';

            // Переносим приложение прямо в body. Это выводит модалку из stacking context
            // шаблона сайта, поэтому фиксированная шапка/меню не смогут оказаться поверх overlay.
            if (root.parentNode !== document.body) {
                document.body.appendChild(root);
            }

            var BitrixVue = BX.Vue3.BitrixVue;

            BitrixVue.createApp({
                data: function () {
                    var values = {};
                    (config.fields || []).forEach(function (field) {
                        if (field.fieldType === 'checkbox' || field.fieldType === 'multiselect') {
                            values[field.sid] = false;
                        } else {
                            values[field.sid] = '';
                        }
                    });

                    return {
                        config: config,
                        isOpen: false,
                        isSending: false,
                        isSent: false,
                        successMessage: '',
                        commonError: '',
                        values: values,
                        errors: {},
                        timerId: null
                    };
                },

                mounted: function () {
                    var delay = Number(this.config.delayMs);
                    if (!Number.isFinite(delay) || delay < 0) {
                        delay = 5000;
                    }

                    // Автооткрытие считаем от момента монтирования Vue-компонента.
                    // Передаём экземпляр компонента отдельным аргументом setTimeout,
                    // чтобы не зависеть от контекста this внутри callback.
                    this.timerId = window.setTimeout(function (vm) {
                        if (!vm.isOpen && !vm.isSent) {
                            vm.openModal();
                        }
                    }, delay, this);

                    document.addEventListener('keydown', this.onKeydown);
                },

                beforeUnmount: function () {
                    if (this.timerId) {
                        window.clearTimeout(this.timerId);
                    }
                    document.removeEventListener('keydown', this.onKeydown);
                },

                methods: {
                    openModal: function () {
                        this.isOpen = true;
                        document.documentElement.classList.add('callback-form-lock');
                    },

                    closeModal: function () {
                        this.isOpen = false;
                        document.documentElement.classList.remove('callback-form-lock');
                    },

                    onKeydown: function (event) {
                        if (event.key === 'Escape' && this.isOpen) {
                            this.closeModal();
                        }
                    },

                    fieldDomId: function (field) {
                        return 'callback-' + this.config.formId + '-' + field.sid;
                    },

                    clearFieldError: function (sid) {
                        if (this.errors[sid]) {
                            delete this.errors[sid];
                        }
                        this.commonError = '';
                    },

                    validate: function () {
                        var errors = {};

                        this.config.fields.forEach(function (field) {
                            var value = this.values[field.sid];

                            if (field.required) {
                                if (field.fieldType === 'checkbox' && value !== true) {
                                    errors[field.sid] = 'Необходимо подтвердить согласие.';
                                    return;
                                }

                                if (field.fieldType !== 'checkbox' && String(value || '').trim() === '') {
                                    errors[field.sid] = 'Заполните поле.';
                                    return;
                                }
                            }

                            var validationType = String(field.validation || '').toLowerCase();
                            var inputType = String(field.inputType || '').toLowerCase();
                            var needsPhoneValidation = validationType === 'phone' || inputType === 'tel';

                            if (needsPhoneValidation && String(value || '').trim() !== '') {
                                var rawPhone = String(value).trim();
                                var digits = rawPhone.replace(/\D/g, '');
                                var visibleFormatOk = /^\+?[0-9\s().-]+$/.test(rawPhone);

                                // Для формы обратного звонка принимаем обычные российские варианты:
                                // +7XXXXXXXXXX / 8XXXXXXXXXX или XXXXXXXXXX без кода страны.
                                // 10 цифр без кода страны должны начинаться с 9.
                                var russianPhoneOk =
                                    (digits.length === 11 && (digits.charAt(0) === '7' || digits.charAt(0) === '8'))
                                    || (digits.length === 10 && digits.charAt(0) === '9');

                                if (!visibleFormatOk || !russianPhoneOk) {
                                    errors[field.sid] = 'Введите номер в формате +7 999 123-45-67.';
                                }
                            }
                        }.bind(this));

                        this.errors = errors;
                        return Object.keys(errors).length === 0;
                    },

                    appendField: function (formData, field) {
                        var value = this.values[field.sid];

                        if (field.fieldType === 'checkbox') {
                            if (value === true && field.answers[0]) {
                                formData.append(field.name, String(field.answers[0].id));
                            }
                            return;
                        }

                        formData.append(field.name, String(value == null ? '' : value));
                    },

                    submit: async function () {
                        this.commonError = '';

                        if (!this.validate()) {
                            return;
                        }

                        this.isSending = true;

                        try {
                            var formData = new FormData();
                            formData.append('WEB_FORM_ID', String(this.config.formId));
                            formData.append('web_form_submit', String(this.config.buttonText || 'Отправить'));
                            formData.append('callback_vue_ajax', 'Y');
                            formData.append('sessid', String(this.config.sessid || ''));

                            this.config.fields.forEach(function (field) {
                                this.appendField(formData, field);
                            }.bind(this));

                            var response = await fetch(window.location.href, {
                                method: 'POST',
                                body: formData,
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                credentials: 'same-origin'
                            });

                            // Штатный form.result.new после успешного сохранения может сделать
                            // LocalRedirect на эту же страницу с formresult=addok. fetch следует
                            // редиректу и в итоге получает HTML, поэтому response.json() падал.
                            if (response.redirected) {
                                try {
                                    var redirectUrl = new URL(response.url, window.location.origin);
                                    if ((redirectUrl.searchParams.get('formresult') || '').toLowerCase() === 'addok') {
                                        this.isSent = true;
                                        this.successMessage = 'Спасибо! Мы вам перезвоним.';
                                        return;
                                    }
                                } catch (redirectError) {
                                    console.warn('Callback form redirect parse error', redirectError);
                                }
                            }

                            var responseText = await response.text();
                            var payload;
                            try {
                                payload = JSON.parse(responseText);
                            } catch (parseError) {
                                console.error('Callback form expected JSON, got:', responseText);
                                throw new Error('Server returned non-JSON response');
                            }

                            if (!response.ok || !payload.success) {
                                this.errors = payload.errors || {};
                                this.commonError = payload.message || 'Проверьте заполнение формы.';
                                return;
                            }

                            this.isSent = true;
                            this.successMessage = payload.message || 'Спасибо! Мы вам перезвоним.';
                        } catch (error) {
                            console.error(error);
                            this.commonError = 'Не удалось отправить форму. Попробуйте ещё раз.';
                        } finally {
                            this.isSending = false;
                        }
                    }
                },

                template: `
                    <div class="callback-form">
                        <button class="callback-form__trigger" type="button" @click="openModal">
                            Заказать звонок
                        </button>

                        <div
                            v-if="isOpen"
                            class="callback-form__overlay"
                            @mousedown.self="closeModal"
                        >
                            <section
                                class="callback-form__modal"
                                role="dialog"
                                aria-modal="true"
                                :aria-labelledby="'callback-title-' + config.formId"
                            >
                                <button
                                    class="callback-form__close"
                                    type="button"
                                    aria-label="Закрыть"
                                    @click="closeModal"
                                >×</button>

                                <template v-if="!isSent">
                                    <h2
                                        class="callback-form__title"
                                        :id="'callback-title-' + config.formId"
                                    >{{ config.title }}</h2>
                                    <p class="callback-form__subtitle">
                                        Оставьте контакты — мы свяжемся с вами.
                                    </p>

                                    <form class="callback-form__body" @submit.prevent="submit" novalidate>
                                        <div
                                            v-for="field in config.fields"
                                            :key="field.sid"
                                            class="callback-form__field"
                                            :class="{'callback-form__field--checkbox': field.fieldType === 'checkbox'}"
                                        >
                                            <template v-if="field.fieldType === 'textarea'">
                                                <label :for="fieldDomId(field)" class="callback-form__label">
                                                    {{ field.label }}<span v-if="field.required"> *</span>
                                                </label>
                                                <textarea
                                                    :id="fieldDomId(field)"
                                                    v-model="values[field.sid]"
                                                    :name="field.name"
                                                    :placeholder="field.placeholder"
                                                    :required="field.required"
                                                    class="callback-form__control callback-form__textarea"
                                                    :class="{'callback-form__control--error': errors[field.sid]}"
                                                    @input="clearFieldError(field.sid)"
                                                ></textarea>
                                            </template>

                                            <template v-else-if="field.fieldType === 'checkbox'">
                                                <label class="callback-form__check-label">
                                                    <input
                                                        v-model="values[field.sid]"
                                                        type="checkbox"
                                                        :name="field.name"
                                                        :required="field.required"
                                                        @change="clearFieldError(field.sid)"
                                                    >
                                                    <span>{{ field.label }}<span v-if="field.required"> *</span></span>
                                                </label>
                                            </template>

                                            <template v-else>
                                                <label :for="fieldDomId(field)" class="callback-form__label">
                                                    {{ field.label }}<span v-if="field.required"> *</span>
                                                </label>
                                                <input
                                                    :id="fieldDomId(field)"
                                                    v-model="values[field.sid]"
                                                    :type="field.inputType || 'text'"
                                                    :name="field.name"
                                                    :placeholder="field.placeholder"
                                                    :autocomplete="field.autocomplete || 'off'"
                                                    :required="field.required"
                                                    class="callback-form__control"
                                                    :class="{'callback-form__control--error': errors[field.sid]}"
                                                    @input="clearFieldError(field.sid)"
                                                >
                                            </template>

                                            <div v-if="errors[field.sid]" class="callback-form__error">
                                                {{ errors[field.sid] }}
                                            </div>
                                        </div>

                                        <div v-if="commonError" class="callback-form__common-error">
                                            {{ commonError }}
                                        </div>

                                        <button
                                            class="callback-form__submit"
                                            type="submit"
                                            :disabled="isSending"
                                        >
                                            {{ isSending ? 'Отправляем…' : config.buttonText }}
                                        </button>
                                    </form>
                                </template>

                                <div v-else class="callback-form__success" role="status">
                                    <div class="callback-form__success-icon">✓</div>
                                    <h2 class="callback-form__title">Заявка отправлена</h2>
                                    <p>{{ successMessage }}</p>
                                    <button class="callback-form__submit" type="button" @click="closeModal">
                                        Закрыть
                                    </button>
                                </div>
                            </section>
                        </div>
                    </div>
                `
            }).mount(root);
        });
    }

    if (window.BX && typeof BX.ready === 'function') {
        BX.ready(boot);
    } else {
        document.addEventListener('DOMContentLoaded', boot);
    }
}());
