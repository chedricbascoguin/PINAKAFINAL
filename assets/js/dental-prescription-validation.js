(() => {
    const action = document.querySelector('input[name="action"][value="save_prescription"]');
    if (!action) return;
    const form = action.closest('form');
    const submit = form.querySelector('button[type="submit"]');
    const help = document.getElementById('rx_completion_help');
    const required = ['medicine_name','dosage','frequency','duration','quantity','prescription_date','instructions'];
    const walkinRequired = ['walkin_first_name','walkin_last_name','walkin_birthdate','walkin_sex','walkin_contact','walkin_barangay'];
    function validate() {
        const walkin = form.querySelector('input[name="patient_mode"]:checked')?.value === 'walkin';
        const inventory = !!form.elements.inventory_id.value;
        required.forEach(name => form.elements[name].required = true);
        form.elements.resident_id.required = !walkin;
        form.elements.resident_id.disabled = walkin;
        form.querySelectorAll('.dental-walkin-fields input, .dental-walkin-fields select').forEach(field => {
            field.disabled = !walkin;
            field.required = walkin && walkinRequired.includes(field.name);
        });
        ['dispense_location','dispensed_at'].forEach(name => form.elements[name].required = inventory);
        const fields = Array.from(form.elements).filter(field => !field.disabled && field.willValidate);
        fields.forEach(field => {
            field.setCustomValidity('');
            if (field.required && !field.value.trim()) field.setCustomValidity('Please complete this field.');
        });
        const quantity = form.elements.quantity;
        if (quantity.value.trim() && !/^([1-9][0-9]*)\s*(?:[a-zA-Z][a-zA-Z .()\/-]*)?$/.test(quantity.value.trim())) quantity.setCustomValidity('Enter a positive whole quantity, for example 21 caps.');
        const complete = fields.every(field => field.validity.valid);
        submit.disabled = !complete;
        submit.style.opacity = complete ? '' : '0.5';
        submit.style.cursor = complete ? '' : 'not-allowed';
        help.textContent = complete ? 'Ready to issue. Review the prescription before saving.' : 'Complete the required patient and prescription details to issue.';
        return complete;
    }
    form.addEventListener('input',validate);
    form.addEventListener('change',validate);
    form.addEventListener('submit',event => { if (!validate()) { event.preventDefault(); form.reportValidity(); } });
    validate();
})();
