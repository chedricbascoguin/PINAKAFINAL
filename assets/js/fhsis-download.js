(() => {
    'use strict';
    if (window.fhsisDownloadInstalled) return;
    window.fhsisDownloadInstalled = true;
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-fhsis-download]')) return;
        event.preventDefault();
        const button = form.querySelector('button[type="submit"], button:not([type])');
        if (button.disabled) return;
        const status = form.nextElementSibling;
        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Generating…';
        status.textContent = 'Preparing your Excel download…';
        try {
            // The hidden input named "action" shadows HTMLFormElement.action.
            const endpoint = form.getAttribute('action') || window.location.href;
            const response = await fetch(endpoint, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }
            });
            const type = response.headers.get('Content-Type') || '';
            if (!response.ok || !type.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
                const message = type.includes('text/plain') ? (await response.text()).trim().slice(0, 600) : '';
                throw new Error(message || 'The report could not be downloaded. Your session may have expired; reload the dashboard and try again.');
            }
            const blob = await response.blob();
            if (!blob.size) throw new Error('The generated report is empty. Please try again.');
            const disposition = response.headers.get('Content-Disposition') || '';
            const filename = /filename="([^"]+)"/i.exec(disposition)?.[1] || 'FHSIS-report.xlsx';
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename.replace(/[\\/]/g, '_');
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
            status.textContent = 'Download started. Check your downloads.';
        } catch (error) {
            status.textContent = error.message || 'Unable to download the report. Please try again.';
        } finally {
            button.disabled = false;
            button.textContent = label;
        }
    });
})();
