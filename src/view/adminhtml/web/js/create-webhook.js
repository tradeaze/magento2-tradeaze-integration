define(['jquery'], function ($) {
    'use strict';

    return function (config) {
        var $btn = $('#tradeaze_create_webhook_btn');
        var $result = $('#tradeaze-create-webhook-result');

        $btn.on('click', function () {
            $btn.prop('disabled', true);
            $result.html('<span style="color: grey;">Syncing Webhooks...</span>');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    form_key: FORM_KEY,
                    api_token: $('#tradeaze_api_general_api_token').val()
                },
                success: function (response) {
                    if (response.success) {
                        $result.html('<span style="color: green;">&#10003; ' + response.message + '</span>');
                    } else {
                        $result.html('<span style="color: red;">&#10007; ' + response.message + '</span>');
                    }
                },
                error: function () {
                    $result.html('<span style="color: red;">&#10007; Request failed.</span>');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        });
    };
});
