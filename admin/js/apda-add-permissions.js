(() => {
    const url = new URL(window.location.href);
    url.searchParams.delete('apda_add_permissions');
    window.history.replaceState(window.history.state, '', url);

    const form = document.getElementById('your-profile');
    const submit = form?.querySelector('#submit');
    const email = form?.querySelector('#email');
    // WordPress runs forum profile hooks before it validates the email on save.
    if (!submit || !email?.value.trim() || !email.validity.valid) {
        return;
    }

    const checkboxes = [...form.querySelectorAll('input[type="checkbox"]')];
    const roles = apdaMemberPermissions.roles.map(role =>
        checkboxes.find(field => !field.disabled && field.name === 'members_user_roles[]' && field.value === role)
    );
    const group = checkboxes.find(field =>
        !field.disabled && field.name === apdaMemberPermissions.groupField && field.value === apdaMemberPermissions.group
    );
    if (!group || roles.includes(undefined)) {
        return;
    }

    for (const field of [...roles, group]) {
        field.checked = true;
    }
    form.requestSubmit(submit);
})();
