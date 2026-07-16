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
            $status.text($t('Salvataggio in corso...'));

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
                    $status.text($t('Attenzione: la firma resta obbligatoria per completare l\'ordine.'));

                    return;
                }
                $status.text($t('Preferenza salvata.'));
                setTimeout(function () {
                    $status.text('');
                }, 2000);
            }).fail(function () {
                $input.prop('checked', previousChecked);
                $status.text('');
                alert({
                    content: $t('Non è stato possibile salvare la preferenza. Riprova.')
                });
            }).always(function () {
                $input.prop('disabled', false);
            });
        });
    };
});
