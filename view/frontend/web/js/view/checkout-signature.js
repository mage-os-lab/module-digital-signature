/**
 * KO component for the one-page checkout (Luma): signature opt-in checkbox in the
 * payment step. The config (mandatory/requested/url/formKey/label/note) is
 * injected server-side by LayoutProcessorPlugin; the choice is persisted with
 * the same AJAX endpoint as cart/minicart.
 *
 * For mandatory templates the checkbox is pre-selected but deselectable:
 * if the user unchecks it, a validator registered on place order blocks
 * order completion (mandatory consent, Terms & Conditions style).
 */
define([
    'uiComponent',
    'jquery',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function (Component, $, additionalValidators, alert, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'MageOS_DigitalSignature/checkout/signature',
            mandatory: false,
            requested: false,
            url: '',
            formKey: '',
            label: '',
            note: ''
        },

        /** @returns {exports} */
        initObservable: function () {
            this._super().observe(['requested', 'saving', 'statusText']);

            return this;
        },

        /** @returns {exports} */
        initialize: function () {
            this._super();
            additionalValidators.registerValidator(this);

            return this;
        },

        /**
         * Blocks the place order if the signature is mandatory but not accepted.
         *
         * @returns {Boolean}
         */
        validate: function () {
            if (this.mandatory && !this.requested()) {
                alert({
                    content: $t('You must accept the digital signature of the contract to proceed.')
                });

                return false;
            }

            return true;
        },

        /**
         * Persists the choice (for optional templates; for mandatory ones the
         * document is generated server-side regardless).
         */
        toggle: function () {
            var previousRequested = !this.requested(),
                self = this;

            if (!this.url) {
                return true;
            }

            this.saving(true);
            this.statusText($t('Saving...'));

            $.ajax({
                url: this.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    requested: this.requested() ? 1 : 0,
                    form_key: this.formKey
                },
                showLoader: false
            }).done(function () {
                if (self.mandatory && !self.requested()) {
                    self.statusText($t('Warning: the signature is still required to complete the order.'));

                    return;
                }
                self.statusText($t('Preference saved.'));
                setTimeout(function () {
                    self.statusText('');
                }, 2000);
            }).fail(function () {
                self.requested(previousRequested);
                self.statusText('');
                alert({
                    content: $t('We couldn\'t save your preference. Please try again.')
                });
            }).always(function () {
                self.saving(false);
            });

            return true;
        }
    });
});
