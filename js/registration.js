/* ICNHAEM-2027 — registration form (AJAX + abstract lookup) */
(function () {
    'use strict';
    var form = document.getElementById('registrationForm');
    if (!form) return;

    var button = form.querySelector('[type="submit"]');
    var errorBox = document.getElementById('formErrors');
    var successBox = document.getElementById('formSuccess');
    var successId = document.getElementById('successRegId');
    var lookupInput = document.getElementById('abstract_id');
    var lookupResult = document.getElementById('lookupResult');

    function hide(el) { if (el) el.classList.remove('show'); }
    function show(el) { if (el) el.classList.add('show'); }

    /* Conditional fields: student proof and abstract ID. */
    var feeSelect = document.getElementById('fee_category');
    var studentField = document.getElementById('studentDocField');
    function syncStudentDoc() {
        if (!feeSelect || !studentField) return;
        var isStudent = feeSelect.value.indexOf('PhD') !== -1;
        studentField.style.display = isStudent ? 'flex' : 'none';
        var input = studentField.querySelector('input');
        if (input) input.required = isStudent;
    }
    if (feeSelect) {
        feeSelect.addEventListener('change', syncStudentDoc);
        syncStudentDoc();
    }

    var pubSelect = document.getElementById('publish_journal');
    function syncAbstractRequirement() {
        if (!pubSelect || !lookupInput) return;
        lookupInput.required = pubSelect.value !== '' && pubSelect.value !== 'Registration without publication';
    }
    if (pubSelect) {
        pubSelect.addEventListener('change', syncAbstractRequirement);
        syncAbstractRequirement();
    }

    /* Look up an abstract ID as soon as the organiser finishes typing. */
    if (lookupInput && lookupResult) {
        var lookupTimer = null;
        lookupInput.addEventListener('input', function () {
            window.clearTimeout(lookupTimer);
            lookupResult.textContent = '';
            var id = lookupInput.value.trim();
            if (!id) return;
            lookupTimer = window.setTimeout(function () {
                fetch('registration.php?lookup_abstract=' + encodeURIComponent(id), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.found) {
                            lookupResult.style.color = 'var(--success)';
                            lookupResult.textContent = '✓ ' + (data.title || 'Abstract found') +
                                (data.author ? ' — ' + data.author : '');
                        } else {
                            lookupResult.style.color = 'var(--danger)';
                            lookupResult.textContent = data.error || 'No matching abstract found.';
                        }
                    })
                    .catch(function () { lookupResult.textContent = ''; });
            }, 450);
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hide(errorBox);
        hide(successBox);
        if (!form.reportValidity()) return;

        if (button) { button.disabled = true; button.textContent = 'Submitting…'; }

        fetch('registration.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json().catch(function () { throw new Error('Unexpected server response.'); }); })
            .then(function (data) {
                if (data.success) {
                    if (successId) successId.textContent = data.reg_temp_id || '';
                    show(successBox);
                    form.reset();
                    syncStudentDoc();
                    syncAbstractRequirement();
                    if (lookupResult) { lookupResult.textContent = ''; lookupResult.style.color = ''; }
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
                if (button) { button.disabled = false; button.textContent = 'Complete Registration'; }
            });
    });
})();
