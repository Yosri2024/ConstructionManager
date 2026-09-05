// ===========================================
// Construction Manager - Client utilities
// ===========================================

document.addEventListener('DOMContentLoaded', function () {

    // Confirm delete forms
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!confirm(f.dataset.confirm)) e.preventDefault();
        });
    });

    // Live search filter on any table with [data-searchable]
    document.querySelectorAll('[data-searchable]').forEach(function (input) {
        var targetSel = input.getAttribute('data-searchable');
        var rows = document.querySelectorAll(targetSel + ' tbody tr');
        input.addEventListener('input', function () {
            var q = input.value.toLowerCase();
            rows.forEach(function (r) {
                r.style.display = r.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
            });
        });
    });

    // Tabs
    document.querySelectorAll('.tab-bar').forEach(function (bar) {
        bar.querySelectorAll('.tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                bar.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
                var group = bar.getAttribute('data-tabs');
                document.querySelectorAll('[data-tab-group="' + group + '"]').forEach(function (panel) {
                    panel.style.display = panel.getAttribute('data-tab') === tab.getAttribute('data-target') ? '' : 'none';
                });
            });
        });
    });

    // Auto-dismiss alerts
    document.querySelectorAll('.alert.auto-dismiss').forEach(function (a) {
        setTimeout(function () { a.style.transition = 'opacity .4s'; a.style.opacity = '0'; }, 3500);
        setTimeout(function () { a.remove(); }, 4000);
    });

    // Mobile nav: close menu when a nav link is clicked
    document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                document.body.classList.remove('nav-open');
            }
        });
    });
});
