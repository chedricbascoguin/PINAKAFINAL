# RHU System: Core Entity Relationship Diagram

This figure shows selected core entities from `database_schema.sql`. It is a simplified logical ERD, not the complete 32-table physical schema. Attributes are selected for readability. PK marks a declared primary key; FK marks an inferred reference, because the supplied SQL does not declare foreign-key constraints. Relationships require confirmation against application behavior before submission.

```mermaid
erDiagram
    barangays ||..o{ residents : contains
    barangays |o..o{ health_workers : assigns
    residents ||..o{ health_records : has
    residents ||..o| resident_health_profiles : has
    residents ||..o| resident_locations : shares
    residents ||..o{ consultations : attends
    health_records |o..o{ consultations : documents
    health_workers |o..o{ consultations : handles
    residents ||..o{ pregnancy_records : has
    health_workers |o..o{ pregnancy_records : monitors
    residents ||..o{ vaccination_records : receives
    health_workers |o..o{ vaccination_records : administers
    medicine_inventory |o..o{ vaccination_records : supplies
    medicine_inventory ||..o{ stock_transactions : tracks
    health_workers |o..o{ stock_transactions : records
    residents ||..o{ disease_cases : has
    disease_types ||..o{ disease_cases : classifies
    barangays ||..o{ disease_cases : locates
    consultations |o..o{ disease_cases : identifies
    residents ||..o{ certificates : receives
    health_workers |o..o{ certificates : issues

    barangays {
        bigint id PK
        varchar name
        varchar municipality
        varchar province
    }
    residents {
        bigint id PK
        bigint barangay_id FK
        varchar first_name
        varchar last_name
        date birthdate
        varchar sex
        text address
        varchar contact_number
    }
    health_workers {
        bigint id PK
        bigint assigned_barangay_id FK
        varchar first_name
        varchar last_name
        varchar role
        varchar position_title
    }
    health_records {
        bigint id PK
        bigint resident_id FK
        varchar blood_type
        varchar blood_pressure
        text allergies
        text medical_conditions
        date last_checkup_date
    }
    resident_health_profiles {
        bigint id PK
        bigint resident_id UK,FK
        double height
        double weight
        int heart_rate
        date last_checkup_date
    }
    resident_locations {
        bigint resident_id PK,FK
        decimal latitude
        decimal longitude
        datetime shared_at
    }
    consultations {
        bigint id PK
        bigint resident_id FK
        bigint health_worker_id FK
        bigint health_record_id FK
        date consultation_date
        text chief_complaint
        text diagnosis
        varchar consultation_status
    }
    pregnancy_records {
        bigint id PK
        bigint resident_id FK
        bigint health_worker_id FK
        varchar pregnancy_status
        date expected_delivery_date
        varchar risk_level
    }
    vaccination_records {
        bigint id PK
        bigint resident_id FK
        bigint health_worker_id FK
        bigint vaccine_inventory_id FK
        varchar vaccine_name
        varchar dose_number
        date vaccination_date
    }
    medicine_inventory {
        bigint id PK
        varchar item_name
        varchar item_type
        varchar unit
        int quantity
        date expiration_date
    }
    stock_transactions {
        bigint id PK
        bigint medicine_inventory_id FK
        bigint health_worker_id FK
        varchar transaction_type
        int quantity
        date transaction_date
    }
    disease_types {
        bigint id PK
        varchar name
        varchar category
        varchar icd_code
    }
    disease_cases {
        bigint id PK
        bigint resident_id FK
        bigint disease_type_id FK
        bigint consultation_id FK
        bigint barangay_id FK
        date case_date
        varchar case_status
    }
    certificates {
        bigint id PK
        bigint resident_id FK
        bigint issued_by_id FK
        varchar certificate_number UK
        varchar certificate_type
        date issue_date
        varchar status
    }
```

## Notation

- `||`: exactly one parent.
- `|o`: zero or one parent, based on a nullable reference column.
- `o{`: zero or many child records.
- `o|`: zero or one child record, based on a unique or primary-key reference.
- Dashed lines represent non-identifying relationships, except that the resident location uses its resident reference as its identity; its line is also dashed here to keep this logical overview consistent.

The inferred `vaccination_records.vaccine_inventory_id` reference targets `medicine_inventory`, which includes an `item_type` column. Confirm this mapping in the application. Other reference columns and support modules are omitted from this overview.

## Suggested Figure Caption

Figure X. Core entity relationship diagram of the Rural Health Unit system.

## Suggested Paper Description

The core entity relationship diagram presents the main data structures of the Rural Health Unit system. Barangays group resident records and support health worker assignments. Residents are associated with health records, consultations, pregnancy records, vaccinations, disease cases, and certificates. Each resident may have one health profile and one location record. Medicine inventory supports vaccination references and stock transaction tracking, while disease types classify recorded disease cases. Primary keys identify records, and reference columns associate records across entities.

## Scope

The remaining tables cover audit logs, certificate workflow and approvals, confidential laboratory tests and access logs, dental records, events, FHSIS reports, portal content and settings, notifications, push subscriptions, sanitary records, and vital statistics. Include these in separate module diagrams or a full appendix ERD if required by the capstone format.
