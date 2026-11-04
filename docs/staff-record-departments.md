# Department patient folders

StaffResidentRecords.php derives its service scope from the signed-in session, never from request parameters. Dental staff see all dental entries for the selected patient; medtechs see laboratory consultations/history; midwives see maternal, prenatal, family-planning and related records; nurses see nursing/medical, immunization and disease records; sanitary staff see sanitary history. Physicians and administrative staff receive their relevant medical or administrative sources. System admins can view the full non-confidential set.

History covers earlier and current entries from all staff within that service, rather than only the current examiner. The patient name/ID appears once. Different patients with identical names remain separate IDs. Shared consultations and health-history snapshots are classified by service/type and provider department before rendering. Helper columns used for classification are not shown in the page.

Confidential laboratory tables are never loaded by the shared folder. Confidential flags exclude records even for admins; examining-medtech ownership remains enforced in the confidential testing view. Existing clinical department dashboards retain their dedicated workflows. No saved history was deleted or merged by this change.

Tests: php tests/staff-record-departments-test.php, dental-record-history-test.php and record-access-test.php. Dental, medtech and nursing page renders were checked against the local database without modifying patient records.
