/**
 * Componente KO del checkout one-page (Luma): checkbox opt-in firma nello step
 * di pagamento. La config (mandatory/requested/url/formKey/label/note) è
 * iniettata server-side dal LayoutProcessorPlugin; la scelta è persistita con
 * lo stesso endpoint AJAX di carrello/minicart.
 *
 * Per i template obbligatori la spunta è pre-selezionata ma deselezionabile:
 * se l'utente la toglie, un validatore registrato sul place order blocca il
 * completamento dell'ordine (consenso obbligatorio, stile Termini & Condizioni).
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
         * Blocca il place order se la firma è obbligatoria ma non accettata.
         *
         * @returns {Boolean}
         */
        validate: function () {
            if (this.mandatory && !this.requested()) {
                alert({
                    content: $t('Per procedere devi accettare la firma digitale del contratto.')
                });

                return false;
            }

            return true;
        },

        /**
         * Persiste la scelta (per i template facoltativi; per gli obbligatori il
         * documento è generato comunque server-side).
         */
        toggle: function () {
            var previousRequested = !this.requested(),
                self = this;

            if (!this.url) {
                return true;
            }

            this.saving(true);
            this.statusText($t('Salvataggio in corso...'));

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
                    self.statusText($t('Attenzione: la firma resta obbligatoria per completare l\'ordine.'));

                    return;
                }
                self.statusText($t('Preferenza salvata.'));
                setTimeout(function () {
                    self.statusText('');
                }, 2000);
            }).fail(function () {
                self.requested(previousRequested);
                self.statusText('');
                alert({
                    content: $t('Non è stato possibile salvare la preferenza. Riprova.')
                });
            }).always(function () {
                self.saving(false);
            });

            return true;
        }
    });
});
