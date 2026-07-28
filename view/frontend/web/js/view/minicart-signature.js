/**
 * Componente KO della minicart (tema Luma): mostra il checkbox opt-in firma
 * leggendo la customer-data section digitalsignature-signature e ne persiste la
 * scelta tramite l'endpoint AJAX, ricaricando la section.
 */
define([
    'ko',
    'uiComponent',
    'Magento_Customer/js/customer-data',
    'Magento_Ui/js/modal/alert',
    'jquery',
    'mage/translate'
], function (ko, Component, customerData, alert, $, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'MageOS_DigitalSignature/minicart/signature'
        },

        /** @returns {exports} */
        initialize: function () {
            this._super();
            this.signature = customerData.get('digitalsignature-signature');
            this.saving = ko.observable(false);
            this.statusText = ko.observable('');

            return this;
        },

        /**
         * Persiste la scelta e ricarica la section (che riallinea il checkbox
         * allo stato reale del quote se il salvataggio fallisce).
         *
         * @param {Object} data
         * @param {Event} event
         * @returns {Boolean} lascia aggiornare il checkbox dal browser
         */
        toggle: function (data, event) {
            var section = this.signature(),
                requested = event.target.checked ? 1 : 0,
                self = this;

            if (!section || !section.url) {
                return true;
            }

            this.saving(true);
            this.statusText($t('Saving...'));

            $.ajax({
                url: section.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    requested: requested,
                    form_key: section.formKey
                },
                showLoader: true
            }).done(function () {
                if (section.mandatory && !requested) {
                    self.statusText($t('Warning: the signature is still required to complete the order.'));

                    return;
                }
                self.statusText($t('Preference saved.'));
                setTimeout(function () {
                    self.statusText('');
                }, 2000);
            }).fail(function () {
                self.statusText('');
                alert({
                    content: $t('We couldn\'t save your preference. Please try again.')
                });
            }).always(function () {
                self.saving(false);
                customerData.reload(['digitalsignature-signature'], false);
            });

            return true;
        }
    });
});
