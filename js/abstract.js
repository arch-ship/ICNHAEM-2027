/* ICNHAEM-2027 — abstract form (AJAX) */
(function () {
    'use strict';
    var form = document.getElementById('abstractForm');
    if (!form) return;

    var button = form.querySelector('[type="submit"]');
    var errorBox = document.getElementById('formErrors');
    var successBox = document.getElementById('formSuccess');
    var successId = document.getElementById('successTempId');

    function hide(el) { if (el) el.classList.remove('show'); }
    function show(el) { if (el) el.classList.add('show'); }

    /* Corresponding-author fields are only required when that option is chosen. */
    var commToggle = document.getElementById('comm_author_check');
    var commFields = document.getElementById('commFields');
    function syncCommFields() {
        if (!commToggle || !commFields) return;
        commFields.style.display = commToggle.checked ? 'grid' : 'none';
        commFields.querySelectorAll('input').forEach(function (input) {
            input.required = commToggle.checked;
        });
    }
    if (commToggle) {
        commToggle.addEventListener('change', syncCommFields);
        syncCommFields();
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hide(errorBox);
        hide(successBox);
        if (!form.reportValidity()) return;

        if (button) { button.disabled = true; button.textContent = 'Submitting…'; }

        fetch('abstract.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json().catch(function () { throw new Error('Unexpected server response.'); }); })
            .then(function (data) {
                if (data.success) {
                    if (successId) successId.textContent = data.temp_id || '';
                    show(successBox);
                    form.reset();
                    syncCommFields();
                    if (successBox) successBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    var list = document.getElementById('errorList');
                    if (list) {
                        list.innerHTML = '';
                        var items = Array.isArray(data.errors) ? data.errors : [data.error || 'Please check the form and try again.'];
                        items.forEach(function (msg) {
                            var li = document.createElement('li');
                            li.textContent = msg;
                            list.appendChild(li);
                        });
                    }
                    show(errorBox);
                    if (errorBox) errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            })
            .catch(function (err) {
                var list = document.getElementById('errorList');
                if (list) {
                    list.innerHTML = '';
                    var li = document.createElement('li');
                    li.textContent = err.message || 'Network error. Please try again.';
                    list.appendChild(li);
                }
                show(errorBox);
            })
            .finally(function () {
                if (button) { button.disabled = false; button.textContent = 'Submit Abstract'; }
            });
    });
})();
