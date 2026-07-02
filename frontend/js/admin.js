(function () {
    'use strict';

    document.querySelectorAll('.role-select').forEach(function (select) {
        select.addEventListener('change', function () {
            select.form.submit();
        });
    });
})();
