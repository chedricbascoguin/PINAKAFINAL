# ResiHUnity RHU Management System

## System Presentation and Demonstration Script

This is a continuous presentation script for demonstrating the actual system. It is organized according to each user and dashboard, not according to PowerPoint slides.

---

## Opening

Good day, everyone.

Today, we will present our project called **ResiHUnity RHU Management System**.

ResiHUnity is a web-based system created for the Rural Health Unit of Nasugbu. It provides one centralized platform for residents, RHU administrators, nurses, midwives, medical technologists, Barangay Health Workers, and sanitary inspectors.

The system was developed because Rural Health Units manage many different records and services every day. These include resident information, consultations, vaccinations, maternal care, disease monitoring, laboratory services, blood donation activities, sanitation inspections, certificates, staff records, and government health reports.

When this information is recorded only on paper or stored in separate files, retrieving and updating records becomes difficult. It can also cause duplicated records, delayed services, and poor coordination among healthcare personnel.

ResiHUnity addresses this problem by centralizing RHU information and giving every user a dashboard based on their responsibilities.

We will now demonstrate the system according to each type of user.

---

# 1. Public User and Landing Page

We will begin with the public landing page.

This is the first page that users see when they access ResiHUnity. A visitor does not need to log in to view the public information presented here.

The landing page introduces the Rural Health Unit and its available healthcare services. It presents public announcements, scheduled health events, RHU personnel, contact information, and the locations of health facilities or stations.

The public page also provides options for resident registration, resident login, staff login, Barangay Health Worker login, and administrator login.

The purpose of this page is to make basic RHU information accessible to the community while directing each user to the correct portal.

If a person does not have an account, they can select the resident-registration option.

---

# 2. Resident Registration

The resident-registration page allows a qualified resident to create a personal portal account.

The resident enters the required identity and contact information. The system validates the submitted details before saving the account and resident profile in the database.

After successful registration, the resident can use their login credentials to access the Resident Dashboard.

This process is important because the account connects the resident to RHU services and records. It also helps ensure that health information is associated with the correct person.

---

# 3. Resident User and Resident Dashboard

We will now log in as a resident.

After the user enters valid credentials, the system opens the Resident Dashboard. This dashboard is intended only for the authenticated resident and does not provide access to restricted staff or administrative records.

## Resident Overview

The Overview section gives the resident a summary of their portal account and health information.

The resident can see basic profile information, recent records, vaccination information, health-service updates, and important RHU announcements. Notifications inform the resident about actions or updates related to their account.

The purpose of the Overview is to present the most useful information without requiring the resident to search through different files or visit the RHU personally.

## Personal and Dependent Profiles

The resident can review personal details and maintain supported dependent records.

The dependent feature is useful for parents or primary residents who need to manage eligible family members, such as children, under one account.

The system applies ownership checks so one resident cannot manage dependents belonging to another account.

## Health Records and History

The records and history sections allow the resident to review health information recorded by authorized RHU personnel.

These records may include previous consultations, diagnoses, treatment information, vaccination records, and other services received from the RHU.

This improves continuity of care because the resident and authorized healthcare workers can refer to previously recorded information.

## Appointment Request

To request an appointment, the resident selects the appointment option.

The resident enters a preferred date and briefly describes the health concern or reason for consultation. After the form is submitted, the request is saved in the system and becomes available to the appropriate RHU personnel.

This feature reduces unnecessary visits because the resident can begin the appointment process online.

## Immunization

The Immunization section displays vaccination records verified by the RHU.

It helps the resident monitor which vaccines have already been administered and supports follow-up for future or overdue doses.

## Certificate Request

The resident can request an available health certificate through the portal.

The user selects the type of certificate and provides the required purpose or information. RHU staff can then review and process the request.

The request remains part of the system, allowing its status to be monitored instead of relying only on verbal follow-ups.

## Events

The Events section displays RHU activities such as health programs, vaccination campaigns, seminars, or blood-donation drives.

If registration is available, the resident can register directly through the dashboard.

## Contact and Notifications

The resident can send a general inquiry, appointment concern, certificate inquiry, health concern, feedback, or complaint to the RHU.

The notification area provides updates and reminders. Notifications can direct the resident to the relevant section of the portal.

## Emergency Referral Request

The resident can submit an emergency referral request by describing the urgent concern and providing a pickup location.

This function assists communication with the RHU. However, it does not replace an emergency hotline, ambulance service, or immediate emergency medical care.

Overall, the Resident Dashboard makes RHU services more accessible and gives residents a clearer view of their health-related transactions.

---

# 4. RHU Staff Login and Role-Based Access

We will now proceed to the staff side of the system.

RHU personnel use the staff-login page. After valid credentials are submitted, the system checks the staff member's assigned role.

The system then redirects the user to the correct dashboard.

This is called role-based access. A nurse, midwife, medical technologist, sanitary inspector, and Barangay Health Worker have different responsibilities, so they should not receive exactly the same functions or permissions.

Role-based dashboards make the system easier to use and help protect confidential information.

---

# 5. Nurse User and Nurse Dashboard

We will first demonstrate the Nurse Dashboard.

## Nurse Overview

The Overview displays the nurse's current workload and important health-program information. It summarizes consultations, patients, vaccination activities, nutrition cases, TB records, disease alerts, and pending actions.

## OPD Triage

The OPD Triage section allows the nurse to review incoming consultation or appointment requests.

The nurse can record vital signs such as blood pressure, temperature, weight, respiratory rate, and heart rate. The nurse can also document triage findings and update the consultation status.

This information prepares the patient's record for further assessment and treatment.

## Patient Records

The Patient Records section allows the nurse to search and review registered residents and their relevant health history.

It supports faster retrieval of information during consultations and follow-up visits.

## Immunization

The nurse can record vaccines administered to residents and children. The module includes the vaccine, dose, date, batch or lot information, administering staff member, and current status.

The section also helps identify due or overdue vaccinations.

## Nutrition or OPT+

The Nutrition section supports child-growth and nutrition monitoring.

The nurse can review nutrition assessments and identify children who may require intervention, including cases of moderate or severe acute malnutrition.

## TB-DOTS

The TB-DOTS section supports the monitoring of tuberculosis patients, treatment progress, adherence, schedules, and outcomes.

## Disease Surveillance

The Disease Surveillance section records and monitors reportable diseases. This assists the RHU in identifying unusual case increases and affected barangays.

## BHW Management

The nurse can review Barangay Health Worker assignments and community coverage when coordination is required.

## Certificates

The nurse can review and process certificate-related records that are within the role's authority.

The Nurse Dashboard is important because it combines direct patient care, preventive programs, and community-health monitoring.

---

# 6. Midwife User and Midwife Dashboard

We will now log in as a midwife.

## Midwife Overview

The Overview summarizes active prenatal patients, high-risk pregnancies, postpartum cases, upcoming visits, and other maternal-health priorities.

## Maternal Health

The Maternal Health section is used to register and monitor pregnancy cases.

The midwife can record pregnancy information, expected delivery date, risk classification, prenatal visits, care plans, supplements, clinical findings, and the assigned healthcare provider.

High-risk cases can be identified so they receive closer monitoring and appropriate referral.

## Family Planning

The Family Planning section records the selected family-planning method, client or acceptor type, supply dates, next visit, status, and clinical notes.

This helps the RHU monitor follow-up schedules and continuity of family-planning services.

## Immunization

The midwife can record or monitor relevant maternal and child immunization services.

## Vital Statistics

The Vital Statistics section supports the recording of births, deaths, and related community information within the authorized workflow.

## Prenatal OPD

The Prenatal OPD section displays consultations assigned to or relevant to the midwife.

The midwife can review the patient's concern, provide prenatal advice, add clinical notes, and update the consultation.

## Certificates

The midwife can review and process eligible certificate records connected with maternal or community services.

The Midwife Dashboard is important because it supports continuous care from pregnancy monitoring to delivery, postpartum follow-up, family planning, and child health.

---

# 7. Medical Technologist User and Dashboard

We will now demonstrate the Medical Technologist Dashboard.

## Medical Technologist Overview

The Overview displays pending laboratory requests, recent test activities, referrals, and laboratory-supply information.

## Rapid Diagnostic Tests

The Rapid Diagnostic Tests section is used to manage on-site laboratory tests and diagnostic findings.

The medical technologist selects the patient or consultation, records the requested test, enters the result, identifies whether the result is normal or requires attention, and adds specimen or clinical notes.

## Specimen Referrals

If a test cannot be completed within the RHU, the specimen or patient can be referred to another laboratory or facility.

The system records the requested test, destination facility, referral date, status, clinical notes, and referring personnel.

## Test Kit Supplies

The Test Kit Supplies section monitors laboratory materials and rapid-test kits.

It records the item name, category, available quantity, unit, reorder level, and expiration date. This assists the medical technologist in identifying low-stock or expiring supplies.

## Laboratory Reports

The Reports section summarizes laboratory activity and recorded results for monitoring and reporting.

## Certificates

The medical technologist can review eligible certificate records that require laboratory findings or verification.

This dashboard helps ensure that laboratory requests, results, referrals, and supplies are managed in an organized and traceable way.

---

# 8. Barangay Health Worker User and BHW Dashboard

We will now demonstrate the Barangay Health Worker Dashboard.

The Barangay Health Worker acts as a link between the community and the RHU.

## BHW Overview

The Overview displays assigned community information, donor statistics, upcoming blood drives, and reported health needs.

## My Donors

The My Donors section contains residents recruited or referred as potential blood donors.

The BHW can review donor details and blood types to help coordinate community blood-donation activities.

## Blood Drives

The Blood Drives section displays scheduled and completed drives for the assigned barangay.

It presents the date, time, venue, target number of donors, actual participation, blood types needed, and activity status.

The dashboard also provides action items, such as informing households, confirming the venue, coordinating with organizers, and recruiting donors.

## Report Need

The Report Need section allows the BHW to communicate a community health concern or resource need to the RHU.

The BHW Dashboard is designed to work well on smaller devices because Barangay Health Workers may use the system while working in the community.

---

# 9. Sanitary Inspector User and Dashboard

We will now log in as a sanitary inspector.

## Sanitary Overview

The Overview presents recent inspections, establishments requiring attention, compliance information, and disease-related concerns.

## Inspections

The inspector can record the establishment, barangay, inspection date, next inspection date, status, compliance rate, violations, and detailed findings.

The system can also generate a printable official inspection report.

## Sanitation Notices

If violations require formal action, the inspector can issue a sanitation notice connected to the inspection record.

The notice includes a unique number, issuing officer, date, violations, findings, and notice status. Administrators can also be notified about the issued notice.

## Certificates

The Certificates section supports the review of sanitation-related certificates and clearances.

## Disease

The Disease section helps connect environmental or sanitation concerns with relevant community disease information.

This dashboard is important because environmental health conditions can directly affect disease prevention and public safety.

---

# 10. RHU General Staff Dashboard

The general RHU dashboard brings together the RHU's major clinical and public-health services.

## Overview

The Overview presents key performance indicators and urgent priorities, including consultations, pending referrals, vaccination status, maternal cases, disease alerts, staff activity, and community-program information.

## OPD and Hospital Referrals

Staff can record consultations, diagnoses, treatments, follow-up instructions, and referrals to higher-level hospitals or facilities.

## Immunization

The immunization module manages EPI schedules, administered vaccines, and due or overdue doses.

## Maternal Health and Family Planning

These modules track pregnancy, prenatal care, risk level, delivery planning, family-planning services, and follow-up schedules.

## TB-DOTS, Nutrition, and Disease Surveillance

The dashboard supports tuberculosis-treatment monitoring, child nutrition assessments, and reportable-disease surveillance.

## Vital Statistics and Certificates

Authorized personnel can manage births, deaths, health certificates, and related records.

## Medicine Inventory

The Medicine Inventory monitors stock quantities, medicine distribution, expiration dates, consumption, and reorder needs.

## Sanitation

The sanitation module summarizes establishment inspections, compliance, violations, and required follow-up.

## BHW and Staff Directory

These sections support the management of Barangay Health Workers, RHU personnel, credentials, roles, assignments, and duty schedules.

## DOH Reports

The system can organize information for Department of Health reports, including FHSIS and other program reports.

## Analytics and Predictions

The Analytics section uses database records to present trends related to consultations, diseases, medicine use, and other RHU activities.

These results are decision-support information only. They must still be reviewed and interpreted by qualified healthcare professionals.

## Audit Logs

The Audit Logs record authorized staff and administrator actions. Residents cannot access this section.

Audit records support accountability by showing what action was performed, who performed it, and when it occurred.

---

# 11. Administrator User and Administrator Dashboard

We will now demonstrate the Administrator Dashboard.

The administrator is responsible for system-level management rather than direct clinical treatment.

## Administrator Overview

The Overview displays the total number of residents, active staff, Barangay Health Workers, barangay statistics, requests, and other operational information.

## Resident Management

The administrator can review the resident registry and relevant account information.

This helps ensure that resident records are complete and properly connected to portal accounts.

## Staff Management

The administrator can create and maintain staff accounts.

Staff information includes the user's identity, role, position, professional license, specialization, contact information, status, and other employment details.

The assigned role determines which dashboard the staff member can access.

## Account and Security Management

The administrator can activate or deactivate authorized accounts, manage passwords, and review role or permission information.

These controls are important because health information must only be accessible to authorized users.

## Portal Content and Announcements

The administrator can manage public announcements, health events, gallery content, RHU information, and other settings displayed on the public portal.

## Reports

The administrator can review summaries based on residents, barangays, staff roles, consultations, vaccinations, disease cases, referrals, and other system records.

These reports support planning, resource allocation, and preparation of required health information.

## Audit and System Settings

The Audit section helps the administrator review accountable system activity.

The System section contains operational settings, email configuration, and system-management functions.

The Administrator Dashboard gives RHU management a centralized view of the system while keeping clinical workflows assigned to qualified staff.

---

# 12. How Information Moves Through the System

To show how the dashboards work together, we can use an appointment as an example.

First, a resident logs in and submits an appointment request with a preferred date and health concern.

Second, the request is saved in the central database.

Third, the appropriate RHU staff member sees the request in the assigned consultation queue.

Fourth, the nurse can perform triage and record vital signs.

Fifth, the assigned healthcare professional records findings, treatment, instructions, or a referral.

Finally, the completed information becomes part of the resident's health history, while authorized RHU dashboards and reports reflect the updated data.

The same principle applies to vaccinations, prenatal records, laboratory tests, certificates, disease reports, sanitation inspections, and other services.

This connected workflow is one of the most important parts of ResiHUnity.

---

# Importance of the Project

ResiHUnity is important for residents because it provides a more convenient way to access RHU information and begin service requests.

It is important for healthcare workers because it makes records easier to retrieve, update, and monitor. Role-specific dashboards also reduce unnecessary information and help personnel focus on their responsibilities.

It is important for RHU administrators because centralized data provides a clearer view of staff, residents, health programs, supplies, requests, and community health conditions.

The project can reduce duplicated records and repetitive paperwork. It can improve communication between residents and healthcare workers. It can also support earlier identification of overdue vaccinations, high-risk pregnancies, disease increases, low supplies, and other concerns requiring action.

Most importantly, it supports continuity of care. An updated resident history helps authorized healthcare workers understand the services already provided and the follow-up care still required.

The system does not replace doctors, nurses, midwives, or other healthcare professionals. Instead, it supports them by organizing information and improving coordination.

---

# Current Project Status

ResiHUnity is currently a functional prototype.

Most of the planned dashboards and core workflows are already implemented. However, the system still requires additional database integration fixes, complete end-to-end testing, production security hardening, data-privacy assessment, backup and recovery procedures, performance testing, and validation by the Rural Health Unit before actual public deployment.

For a capstone demonstration, the project can show the intended workflow and major system functions. For real healthcare use, every module must first be thoroughly tested and approved by authorized health and data-protection personnel.

---

# Closing

To conclude, ResiHUnity RHU Management System connects residents, healthcare workers, Barangay Health Workers, sanitary inspectors, and administrators through one centralized platform.

Each user receives a dashboard based on their role. Residents can access services and personal records. Nurses manage triage, immunization, nutrition, and disease programs. Midwives manage maternal care and family planning. Medical technologists manage laboratory services. Barangay Health Workers coordinate community activities. Sanitary inspectors manage environmental-health inspections. RHU staff manage clinical programs, while administrators manage users, content, reports, security, and system operations.

Through these connected dashboards, ResiHUnity can make RHU services more organized, accessible, accountable, and responsive to community needs.

Thank you for listening. We are now ready for your questions.
