# FHSIS reporting with existing tables

The report remains a draft for RHU review. The supplied template was recreated from photographs; its official version, scope and complete clinical definitions still need coordinator verification. An RHU aggregate is not a barangay report. DOH CY 2026 guidance reference: https://sites.google.com/view/doh-hfdb/2025-q4-updates/dc-2025-0525 . The detailed annex definitions have not been verified against every mapping.

## Installation

Import `outputs/fhsis-schema-only.sql` into the existing application database and deploy the matching existing-table PHP update. The SQL adds columns only, skips existing columns, reports missing existing tables, and creates no tables or sample patients. Runtime FHSIS functions do not run DDL.

`fhsis-services.php` now bundles the complete native PHP indicator catalog. Normal reporting does not require either `fhsis-indicators.php` or `assets/fhsis-indicators.json` to be uploaded separately. These external copies remain maintenance/compatibility references; the indicator-loading test verifies equality. The workbook hash remains mandatory. The isolated bundled-export test exercises actual report generation with both external files absent.

The new FHSIS tables from the earlier implementation are no longer required. This update does not delete them or convert any observations that may already have been saved in them. Back up and reconcile any earlier observations before retiring those old stores. The current update reads source-linked documentation stored in the existing clinical records.

## Storage

| Existing table | FHSIS storage |
| --- | --- |
| consultations, health_records, pregnancy_records, vaccination_records, vital_statistics | `fhsis_details_json`, with a reserved `_fhsis_services` map |
| dental_records | Existing `meta_json`, preserving clinical chart fields and adding `_fhsis_services` |
| sanitary_records | Existing `details_json`, preserving assessment fields and adding `_fhsis_services` |
| confidential_lab_tests | Encrypted reporting documentation and explicit approval fields; never read/decrypted by the admin report export |
| fhsis_reports | Monthly `FHSIS_SCREENING_AGGREGATE` entries in existing `report_data`, containing only keyed subject/source tokens and approved metric/age-group contributions |
| portal_settings | Private stable pseudonymization key `_private_fhsis_reporting_key`, excluded from normal `portalSettings()` results |

The SQL changes eight existing clinical tables. The existing report/settings tables already have the needed columns, so no schema alteration is needed for those two tables. Preserve the private reporting key in database backups. If `RHU_FHSIS_REPORTING_KEY` is configured, it must match the initialized key; mismatches fail instead of mixing patient identities.

## Clinical recording workflow

The additional completed-services section has been removed from departmental forms. Reporting uses supported facts saved through the normal clinical workflows and reads previously verified source-linked documentation. Staff do not enter a second service list when processing appointments. Private screening still uses the existing MedTech approval fields. Unsupported or incomplete evidence is identified in the review sheets, while report count cells default to 0.

Automatic source facts remain enabled and are unioned with documented outcomes to prevent duplicate counts. A completed appointment alone is not proof of every program outcome. Existing source records without the required clinical details are not backfilled by guessing. These forms still require the existing ALTER-only reporting columns described above.

On a resident's completed departmental record, choose **Document qualifying FHSIS services**. A clinician selects the documented criterion, actual service date and clinical reference, plus required pregnancy, visit, household or mother/newborn details. These observations are saved on that original record, not as new patient or consultation records. A row lock prevents concurrent edits from dropping another observation. Repeat saves update the same indicator/date entry. The recording clinician can withdraw it; saves and withdrawals are audited.

The supplied-template registry has 559 indicator/observation definitions across all program sections. It is bound to the template SHA-256. A different template requires matching definitions. Source-linked documentation is structured clinical attestation, not independently inferred proof of every clinical schedule or external service. Staff must verify the complete criterion against the reference. Pending appointments, prescriptions and counseling do not establish treatment completion or acceptance.

The reader checks source eligibility, department access, demographics, pregnancy ownership, explicit visit counts/dates, mother/newborn subjects and applicable observation dates. Clinical source changes invalidate previous verification. Reporting-only metadata/timestamp changes do not invalidate other observations on the same source. Existing automatic source facts and documentation are unioned by identity; a repeated annotation is not an additional patient.

Private maternal screening is approved only by the recording MedTech, from a new or existing private test. Exact test type, linked pregnancy and actual completed screening are required. Optional syphilis treatment requires its own actual completion date and clinical reference. The private payload stays encrypted. The admin export reads the approved aggregate in `fhsis_reports`, without querying/decrypting private laboratory records. Repeated tests count a woman once per metric/month. Explicit non-reactive findings can establish reactive counts of zero for those contributions; unknown findings cannot. Updating approval replaces that source's earlier contributions; withdrawal removes them. Updates to monthly aggregate reports are serialized using the existing private settings row in the same transaction as source approval.

## Automatic sources and limits

- FP acceptor/dropout events use the explicit `FHSIS_FP_V1` service event captured by the midwife form. Older counseling notes are not acceptance evidence. Source-linked observations can document verified opening/closing registers and demand satisfied. A closing register observation cannot be dated before the actual month-end. Partner linkage is required for assigning male services to female WRA counts.
- Infant vaccination uses explicit supported vaccine/dose records, birth date, sex and administration date. Senior vaccination distinguishes PPV/PPSV23 from PCV and counts recorded influenza. FIC/CIC, birth-timing and other completion criteria need documented evidence; no single dose implies a completed series. Birth-year cohort interpretation needs official-template verification.
- Annual dental first/second visits use completed examination records and January-to-current-month history; same-day charts count once and prescriptions do not count as visits. Additional setting/pregnancy criteria require documentation.
- NCD current-month counts deduplicate resident/disease/age group. Prior-month rows sum each earlier month's distinct counts. A recorded diagnosis does not imply PhilPEN assessment or medicine supply.
- Mortality uses actual linked death events and age at death. Neonatal is under 28 days; early neonatal is under 7. Fetal observations use the individual event identifier and documented fetal sex, not the mother's sex. Unknown fetal sex retains the total and withholds sex disaggregation for review. The perinatal subtotal remains unavailable pending verification of the applicable gestational/event definition.
- M2 uses disease case dates and resident demographics, preserves overflow diseases and deduplicates repeated linked consultation/disease/date entries. Voided/cancelled/ruled-out cases are excluded. Missing demographics remain flagged and unclassified; case-status/reporting requirements still need coordinator verification.

## Zero display and missing evidence

Importing the schema adds empty fields, not clinical events. The report still needs qualifying dated records for its selected period. FP prior-month new acceptors use the preceding month; current-month events use the selected month. All registered report count cells now display numeric 0 when no calculated value is available, as requested. Calculated nonzero values are preserved. These display defaults do not establish that a service was absent: the Indicator Review and Data Quality sheets still identify unavailable evidence and unverified rules. The internal calculation data retains missing values separately from calculated zeros. Resident demographics alone cannot prove screening, acceptance, treatment or completion.

**Indicator Review**, **Data Quality** and **Report Notes** distinguish these gaps. The normal export excludes resident names and detailed clinical-source listings. `submission_ready` remains false until official template/rules, source completeness and coordinator approval have been established.

## Validation

Tests cover source-linked writes/readback/withdrawal in existing tables, repeat-save deduplication, changed-source invalidation, private approval and ownership, encrypted-source storage, pseudonymized aggregate privacy, repeat tests, treatment month and runtime table-count invariance. A verified FP observation is tested through the actual Excel writer and its resulting cell. Existing automatic-source, archive, template, form and department/access tests remain applicable.

The hosted database has not been verified from this environment. No fictional records have been inserted into the live database. The earlier downloadable sample-data files were removed at the user's request.
