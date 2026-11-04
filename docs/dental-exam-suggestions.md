# Automatic dental recommendations

Gum condition uses a predefined dropdown. The current odontogram, hygiene and gum observations populate editable findings. A local browser rule engine fills the recommendation directly from the findings as they change. No Generate button, draft preview panel, historical-record request or suggestion API is used.

Dentists can edit both fields. Editing a recommendation switches to manual mode so later findings changes do not overwrite it. Restore automatic recommendation opts back into automatic updates and replaces the recommendation with the current generated text. Editing findings, recommendations or structured observations clears approval; authenticated dental staff must review and approve before saving. Saved metadata records reviewer/time and whether the final recommendation was automatic or manually edited.

Rules recognize recorded decay, extraction assessment, missing teeth, restorations, gum concerns, incomplete gum assessment and hygiene concerns. They provide assessment/follow-up prompts without prescriptions or automatic procedure authorization. Basic negation is supported, but free text is not a full clinical language parser. Unrecognized findings receive a general individualized clinical review prompt. Clinical references: https://www.nidcr.nih.gov/health-info/gum-disease and https://www.nidcr.nih.gov/health-info/oral-hygiene .

Verification: node tests/dental-auto-recommendation-test.cjs and php tests/dental-exam-suggestions-test.php. Tests include findings changes, negation, tooth marks, preserving manual recommendations, restoring automatic mode and approval reset. DOM behavior was exercised using a test harness; live browser interaction was not available.

Recommendations use short, patient-friendly sentences without numbered clinical sections. They retain relevant FDI tooth numbers, describe possible fillings/removal or gum care in plain language, and leave the final treatment decision to the dentist. Manual edits and approval remain unchanged.
