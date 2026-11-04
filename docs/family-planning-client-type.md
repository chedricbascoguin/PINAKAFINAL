# Automatic family planning client type

The Midwife family planning form suggests a client type when the resident,
method, and actual service date are selected. Only earlier or same-day recorded
family planning services are considered; the appointment being completed is
excluded. Canceled, confidential, and unserved booking records are excluded.

- No recorded prior method use: suggests New Acceptor with a prompt to confirm prior care elsewhere.
- Same recorded method: Continuing User.
- Different exact recorded method: Changing Method.
- Latest recorded dropout: Restarting User.
- Missing history or an unknown previous method subtype: staff must select the type.

Staff can override the suggestion for changing clinic, counseling, dropout,
external history, and other actual services. Method/date changes preserve a
manual override; changing resident resets it. ?Use automatic suggestion? restores
the calculated value. Missed appointments alone never imply dropout.

Existing server-side service validation, duplicate-event protection, and the
staff Save Record action remain in force. No database schema change is required.

Deploy MidwifeDashboard.php, includes/family-planning-client-type.php, and
assets/js/family-planning-client-type.js together.
