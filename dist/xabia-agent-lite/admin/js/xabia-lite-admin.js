(function ($) {
    'use strict';

    function syncAuthPanels($root) {
        var mode = $root.find('input[name="xabia_lite_auth_mode"]:checked').val() || 'byok';
        $root.find('.xabia-lite-auth-panel').removeClass('is-active').attr('hidden', 'hidden');
        $root.find('.xabia-lite-auth-panel[data-auth-mode="' + mode + '"]').addClass('is-active').removeAttr('hidden');
    }

    $(function () {
        var $page = $('.xabia-page-lite');
        if (!$page.length) {
            return;
        }

        syncAuthPanels($page);

        $page.on('change', 'input[name="xabia_lite_auth_mode"]', function () {
            syncAuthPanels($page);
        });
    });
}(jQuery));
