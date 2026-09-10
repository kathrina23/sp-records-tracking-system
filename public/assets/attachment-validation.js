(() => {
    const validationMessage = (files, limits) => {
        const mb = bytes => (bytes / 1048576).toFixed(0) + ' MB';
        const oversized = files.find(file => file.size > limits.maxBytes);
        if (oversized) return '“' + oversized.name + '” is larger than the allowed size of ' + mb(limits.maxBytes) + ' per file. Please select a smaller file.';
        const empty = files.find(file => file.size === 0);
        if (empty) return '“' + empty.name + '” is empty. Please select a file with content.';
        if (files.length > limits.maxFiles) return 'You may upload up to ' + limits.maxFiles + ' files at a time.';
        if (files.reduce((total, file) => total + file.size, 0) > limits.maxTotalBytes) return 'The selected files exceed the allowed combined size of ' + mb(limits.maxTotalBytes) + '. Please remove some files or select smaller files.';
        return '';
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = validationMessage;
    if (typeof document === 'undefined') return;
    const config = document.currentScript.dataset;
    const limits = { maxBytes: Number(config.maxBytes), maxFiles: Number(config.maxFiles), maxTotalBytes: Number(config.maxTotalBytes) };
    const selector = 'input[type="file"][name="attachments[]"], input[type="file"][name="attachment"]';
    const validate = (form, preferredInput) => {
        const inputs = Array.from(form.querySelectorAll(selector));
        if (!inputs.length) return true;
        inputs.forEach(input => input.setCustomValidity(''));
        form.querySelectorAll('[data-attachment-error]').forEach(error => error.remove());
        const files = inputs.flatMap(input => Array.from(input.files || []));
        const message = validationMessage(files, limits);
        if (!message) return true;
        const input = preferredInput || inputs.find(field => field.files?.length) || inputs[0];
        input.setCustomValidity(message);
        const error = document.createElement('span');
        error.dataset.attachmentError = '';
        error.className = 'attachment-size-error';
        error.setAttribute('role', 'alert');
        error.textContent = message;
        input.after(error);
        input.reportValidity();
        return false;
    };
    document.addEventListener('change', event => {
        if (event.target.matches(selector) && event.target.form) validate(event.target.form, event.target);
    });
    document.addEventListener('submit', event => {
        if (!validate(event.target)) event.preventDefault();
    }, true);
})();
