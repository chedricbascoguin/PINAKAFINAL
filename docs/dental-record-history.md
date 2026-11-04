# Complete dental patient history

New dental examinations, extractions, procedures, prescriptions, referrals and queue entries are inserted as separate dental_records rows. New exams no longer overwrite the last exam, and a later prescription for the same medicine no longer replaces the earlier prescription. Explicit workflow updates to a particular appointment/referral still update that identified record.

The dental patient folder loads every stored dental record, ordered by record date and ID, without the previous latest-per-type filter or 100-record cap. Service tab summaries remain bounded lists, with the full history available through the patient folder. The patient list has one row per resident ID; same-name people with different IDs remain distinct. The patient picker/list no longer silently stop at 500/80 people.

Patient name, ID and address appear once at the folder header. Each exam shows its own date and dentist. Same-date prescriptions remain below the matching patient's teeth map; unmatched prescriptions remain visible as individual sheets. Prescription sheets in the folder omit repeated patient demographics. The folder links to a role-scoped history page: dental staff see dental history, laboratory staff see laboratory history, and other staff see their service records. Admins retain the broader non-confidential view. Existing confidential-record access rules are unchanged.

Shared health-record entries for new dental exams also append rather than replacing the latest dental health-record snapshot. Already overwritten database values cannot be recovered by this change; recovery would require an existing backup or another reliable historical source. This change does not fabricate missing older records or automatically merge different resident IDs.

Verification: php tests/dental-record-history-test.php, prescription-sheet tests, confidential-access tests and PHP syntax checks. A live-schema transaction verified that two exams for the same patient retain different IDs and findings, then rolled back both temporary test records.
