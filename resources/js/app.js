const uploadForm = document.querySelector('[data-upload-form]');
if (uploadForm) {
    const input = uploadForm.querySelector('input[type=file]');
    const dropzone = uploadForm.querySelector('[data-dropzone]');
    const list = uploadForm.querySelector('[data-file-list]');
    const feedback = uploadForm.querySelector('[data-upload-feedback]');
    const target = uploadForm.querySelector('select');
    const capabilities = JSON.parse(uploadForm.dataset.capabilities);
    const update = () => {
        list.replaceChildren();
        for (const file of input.files) {
            const item = document.createElement('li');
            item.textContent = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
            list.append(item);
        }
        const sources = [...input.files].map(file => file.name.split('.').pop().toLowerCase());
        for (const option of target.options) {
            option.disabled = sources.some(source => !capabilities.some(pair => pair.source === source && pair.target === option.value));
        }
        const selected = target.selectedOptions[0];
        if (selected?.disabled) {
            const compatible = [...target.options].find(option => !option.disabled);
            if (compatible) target.value = compatible.value;
        }
        const unsupported = sources.some(source => !capabilities.some(pair => pair.source === source && pair.target === target.value));
        feedback.textContent = input.files.length > 5 ? 'Choose no more than five files.' : unsupported ? 'These files do not share a supported target. Separate them into compatible batches.' : '';
        input.setCustomValidity(feedback.textContent);
    };
    input.addEventListener('change', update);
    target.addEventListener('change', update);
    for (const event of ['dragenter', 'dragover']) {
        dropzone.addEventListener(event, e => { e.preventDefault(); dropzone.classList.add('dragging'); });
    }
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragging'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('dragging');
        input.files = e.dataTransfer.files;
        update();
    });
    uploadForm.addEventListener('submit', () => {
        const button = uploadForm.querySelector('button[type=submit]');
        button.disabled = true;
        button.textContent = 'Uploading...';
    });
}

const search = document.querySelector('[data-tool-search]');
if (search) {
    const filter = () => {
        const terms = search.value.toLowerCase().trim().split(/\s+/);
        let count = 0;
        for (const card of document.querySelectorAll('[data-tool]')) {
            card.hidden = !terms.every(term => card.dataset.tool.includes(term));
            if (!card.hidden) count++;
        }
        document.querySelector('[data-no-tools]').hidden = count !== 0;
    };
    search.addEventListener('input', filter);
    filter();
}

const statusPage = document.querySelector('[data-conversion-status]');
if (statusPage && ['queued', 'processing'].includes(statusPage.dataset.currentStatus)) {
    const poll = async () => {
        try {
            const response = await fetch(statusPage.dataset.conversionStatus, {headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error('Status unavailable');
            const data = await response.json();
            if (data.status !== statusPage.dataset.currentStatus) {
                location.reload();
                return;
            }
            setTimeout(poll, 2500);
        } catch {
            document.querySelector('[data-poll-error]').hidden = false;
        }
    };
    setTimeout(poll, 2500);
}
