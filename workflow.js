function shiftDay(value, days) {
    if (!value || !Number.isSafeInteger(days)) return '';
    const date = new Date(value + 'T00:00:00Z');
    date.setUTCDate(date.getUTCDate() + days);
    return Number.isNaN(date.valueOf()) ? '' : date.toISOString().slice(0, 10);
}

document.querySelectorAll('form[data-deadline]').forEach(form => {
    const fields = form.elements;
    function update(preview) {
        const manual = fields.start_mode.value === 'manual';
        fields.start_date.readOnly = !manual;
        fields.start_date.required = manual;
        fields.start_override_reason.disabled = !manual;
        fields.start_override_reason.required = manual;
        if (!preview) return;

        const ack = fields.acknowledged_date.value;
        const meeting = fields.first_meeting_date.value;
        const days = Number(fields.duration_days.value);
        if (!manual) {
            fields.start_date.value = form.dataset.deadline === 'discipline_serious'
                ? shiftDay(meeting, days === 180 ? 0 : 1)
                : shiftDay(ack && meeting ? ack : '', ack === meeting ? 0 : 1);
        }
        fields.preview_due.value = days >= 1 ? shiftDay(fields.start_date.value, days - 1) : '';
    }

    const dateInputs = ['acknowledged_date','first_meeting_date','duration_days','start_mode','start_date'];
    form.addEventListener('input', event => {
        if (dateInputs.includes(event.target.name)) update(true);
    });
    update(!fields.preview_due.value);
});
