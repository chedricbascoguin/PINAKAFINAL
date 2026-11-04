(() => {
    'use strict';
    function recommendationFor(findings) {
        if (!findings.trim()) return '';
        const clauses = findings.toLowerCase().split(/[\n.;]|\bbut\b|\bhowever\b/);
        const matching = pattern => clauses.filter(clause => {
            const match = pattern.exec(clause);
            if (!match) return false;
            const before = clause.slice(Math.max(0, match.index - 45), match.index);
            const after = clause.slice(match.index + match[0].length);
            return !/\b(no|without|denies|negative for|not observed|not present)\b[^,:]*$/.test(before)
                && !/^\s*(?:is |are )?(absent|not present|not observed|negative)\b/.test(after);
        });
        const target = rows => {
            const teeth = new Set();
            rows.forEach(row => {
                for (const match of row.matchAll(/#([1-4][1-8])\b/g)) teeth.add(match[1]);
                for (const match of row.matchAll(/\b(?:tooth|teeth)\s+#?([1-4][1-8])\b/g)) teeth.add(match[1]);
            });
            const numbers = [...teeth].sort((a,b) => Number(a)-Number(b));
            return numbers.length ? (numbers.length === 1 ? 'tooth ' : 'teeth ') + numbers.map(tooth => '#' + tooth).join(', ') : 'the affected teeth';
        };
        const caries = matching(/\b(caries|carious|decay|cavit(?:y|ies))\b/);
        const extraction = matching(/\b(for extraction|extraction assessment|to_extract)\b/);
        const missing = matching(/\b(extracted|missing)\b/).filter(row => !row.includes('performed this visit'));
        const restorations = matching(/\b(filled|filling|pasta|crown|prosthesis|restoration)\b/).filter(row => !row.includes('performed this visit'));
        const performed = clauses.filter(row => row.includes('performed this visit'));
        const gums = matching(/\b(bleeding|swollen|swelling|redness|inflammation|recession|receding|gingivitis|periodont(?:al|itis))\b/);
        const incomplete = matching(/\b(gum condition: not assessed)\b/);
        const hygiene = matching(/\b(oral hygiene: (?:fair|poor)|plaque|calculus|tartar)\b/);
        const infection = matching(/\b(abscess|pus|purulent)\b/);
        const plan = [];
        if (performed.length) plan.push('Follow the dentist?s aftercare instructions for the treatment performed on ' + target(performed) + '.');
        if (infection.length) plan.push('See the dentist promptly to check the possible infection.');
        if (caries.length) plan.push('Have ' + target(caries) + ' checked for possible fillings.');
        if (extraction.length) plan.push('The dentist will check whether ' + target(extraction) + ' can be saved or need removal.');
        if (gums.length) plan.push('Have your gums checked and get a dental cleaning if needed.');
        if (incomplete.length) plan.push('Have your gums checked to complete the examination.');
        if (restorations.length) plan.push('Have the existing fillings or crowns on ' + target(restorations) + ' checked.');
        if (missing.length) plan.push('Ask about options to replace ' + target(missing) + ' if needed.');
        if (hygiene.length || caries.length || gums.length) plan.push('Brush twice a day with fluoride toothpaste and clean between your teeth.');
        if (!plan.length) plan.push('Keep up daily brushing and cleaning between your teeth.');
        plan.push('Return for treatment or a check-up as advised by your dentist.');
        return plan.join('\n');
    }
    if (typeof module !== 'undefined') module.exports = { recommendationFor };
    if (typeof document === 'undefined') return;
    const action = document.querySelector('input[name="action"][value="save_examination"]');
    if (!action) return;
    const form = action.closest('form');
    const findings = form.elements.findings;
    const recommendation = form.elements.recommendation;
    const approval = form.elements.exam_approved;
    const mode = form.elements.recommendation_mode;
    const help = document.getElementById('dental_recommendation_help');
    const restore = document.getElementById('restore_dental_recommendation');
    let automatic = true, generatedLines = [], lastContext = '';
    function updateRecommendation() {
        if (automatic) recommendation.value = recommendationFor(findings.value);
        mode.value = automatic ? 'automatic' : 'manual';
        restore.hidden = automatic;
        help.textContent = automatic ? 'Updates automatically when tooth conditions, oral hygiene, or gums change. Review before saving.' : 'Your edited recommendation is preserved. Review it if the findings change.';
        if (typeof form.dispatchEvent === 'function') form.dispatchEvent(new Event('dental:summary-updated'));
    }
    function updateFindings() {
        const procedureTeeth = form.elements.care_procedure?.checked && /Filling/.test(form.elements.care_procedure_type?.value || '') ? (form.elements.care_procedure_teeth?.value || '') : '';
        const extractionTeeth = form.elements.care_extraction?.checked ? (form.elements.care_extraction_teeth?.value || '') : '';
        const context = [form.elements.oral_hygiene.value, form.elements.gum_condition.value, form.elements.odontogram_json.value, procedureTeeth, extractionTeeth];
        if (JSON.stringify(context) === lastContext) return;
        lastContext = JSON.stringify(context);
        const notes = findings.value.split('\n');
        generatedLines.forEach(line => { const index = notes.indexOf(line); if (index !== -1) notes.splice(index, 1); });
        generatedLines = ['Oral hygiene: ' + context[0] + '.', 'Gum condition: ' + context[1] + '.'];
        let marks = {};
        try { marks = JSON.parse(context[2] || '{}'); } catch (_) { }
        const labels = {caries:'Caries / decay', filling:'Filled / Pasta', missing:'Extracted / missing', to_extract:'For extraction assessment', crown:'Crown / prosthesis'};
        Object.entries(labels).forEach(([code, label]) => {
            const teeth = Object.keys(marks).filter(tooth => Array.isArray(marks[tooth]) && marks[tooth].includes(code)).sort((a,b) => Number(a)-Number(b));
            const performedTeeth = new Set((code === 'filling' ? procedureTeeth : code === 'missing' ? extractionTeeth : '').match(/[1-4][1-8]/g) || []);
            const previous = teeth.filter(tooth => !performedTeeth.has(tooth));
            const performed = teeth.filter(tooth => performedTeeth.has(tooth));
            [[previous, label], [performed, label + ' (performed this visit)']].forEach(([group, caption]) => {
                if (group.length) generatedLines.push((group.length === 1 ? 'Tooth ' : 'Teeth ') + group.map(tooth => '#' + tooth).join(', ') + ': ' + caption + '.');
            });
        });
        findings.value = [notes.join('\n').trim(), generatedLines.join('\n')].filter(Boolean).join('\n\n');
        approval.checked = false;
        automatic = true;
        updateRecommendation();
    }
    form.addEventListener('dental:chart-loaded', () => {
        lastContext = '';
        automatic = true;
        updateFindings();
    });
    findings.addEventListener('input', () => { approval.checked = false; updateRecommendation(); });
    recommendation.addEventListener('input', () => { automatic = false; approval.checked = false; updateRecommendation(); });
    restore.addEventListener('click', () => { automatic = true; approval.checked = false; updateRecommendation(); });
    form.addEventListener('change', event => {
        if (event.target === approval) return;
        if (['oral_hygiene','gum_condition','odontogram_json','care_procedure','care_extraction','care_procedure_type','care_procedure_teeth','care_extraction_teeth'].includes(event.target.name)) updateFindings();
        else approval.checked = false;
    });
    updateFindings();
})();
