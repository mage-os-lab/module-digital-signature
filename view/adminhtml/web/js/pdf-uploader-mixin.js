define([
    'jquery',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, alert, __) {
    'use strict';

    return function (Component) {
        return Component.extend({
            /**
             * Intercepts file upload response to open the visual builder modal when no tag is found.
             */
            onFileUploaded: function (event, data) {
                var response = data.result;

                if (response && response.warning === 'no_tag') {
                    var self = this;
                    require(['MageOS_DigitalSignature/js/pdf-builder-modal'], function (pdfBuilder) {
                        pdfBuilder.open({
                            file: response.file,
                            onSuccess: function (injectedResult) {
                                var newData = $.extend({}, data, { result: injectedResult });
                                self.onFileUploaded(event, newData);
                            },
                            onFailure: function (errorMessage) {
                                alert({
                                    title: __('Errore di Validazione'),
                                    content: errorMessage || __('Impossibile completare la validazione del file.')
                                });
                                self.clear();
                            }
                        });
                    });
                    return;
                }

                return this._super(event, data);
            }
        });
    };
});
