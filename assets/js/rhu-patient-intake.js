(() => {
    const data = document.getElementById('rhu-saved-intakes');
    if (!data) return;
    const profiles = JSON.parse(data.textContent);
    const labels = {middle_name:'Middle name',civil_status:'Civil status',spouse_name:'Spouse',maiden_name:'Maiden name',parent_name:'Parent',birthplace:'Birthplace',education:'Education',employment:'Employment',tin:'TIN',philhealth_number:'PhilHealth number',membership:'Membership',category:'PhilHealth category',dswd_nhts:'DSWD NHTS member'};
    document.querySelectorAll('form select[name="resident_id"]').forEach(select => {
        const summary = document.createElement('details');
        summary.className = 'rounded-xl border border-slate-200 p-3 text-xs';
        const heading = document.createElement('summary');
        heading.textContent = 'Saved RHU patient intake';
        const content = document.createElement('div');
        content.className = 'mt-2 space-y-1';
        summary.append(heading, content);
        select.parentElement.after(summary);
        const update = () => {
            content.replaceChildren();
            const raw = profiles[select.value];
            summary.hidden = !raw || select.closest('form').querySelector('[name="patient_mode"]:checked')?.value === 'walkin';
            if (!raw) return;
            const intake = JSON.parse(raw);
            Object.entries(labels).forEach(([key, label]) => {
                if (!intake[key]) return;
                const row = document.createElement('p');
                row.textContent = `${label}: ${intake[key]}`;
                content.append(row);
            });
        };
        select.closest('form').addEventListener('change', update);
        update();
    });
})();
