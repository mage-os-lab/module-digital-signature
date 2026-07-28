/**
 * Invia in AJAX la scelta opt-in firma digitale al cambiare del checkbox.
 * Usato dal tema Luma (Hyva usa Alpine inline nel proprio template).
 */
define(['jquery', 'Magento_Ui/js/modal/alert', 'mage/translate'], function ($, alert, $t) {
    'use strict';

    return function (config, element) {
        var $checkbox = $(element).find('input[type="checkbox"]'),
            $status = $(element).find('[data-role="status"]');

        $checkbox.on('change', function () {
            var $input = $(this),
                requested = $input.prop('checked') ? 1 : 0,
                previousChecked = !$input.prop('checked');

            $input.prop('disabled', true);
            $status.text($t('Saving...'));

            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    requested: requested,
                    form_key: config.formKey
                },
                showLoader: true
            }).done(function () {
                if (config.mandatory && !requested) {
                    $status.text($t('Warning: the signature is still required to complete the order.'));

                    return;
                }
                $status.text($t('Preference saved.'));
                setTimeout(function () {
                    $status.text('');
                }, 2000);
            }).fail(function () {
                $input.prop('checked', previousChecked);
                $status.text('');
                alert({
                    content: $t('We couldn\'t save your preference. Please try again.')
                });
            }).always(function () {
                $input.prop('disabled', false);
            });
        });
    };
});
