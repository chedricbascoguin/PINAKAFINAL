# RHU System: Corrected Logical Data Flow Diagram

[Open the full diagram](capstone-dfd.svg) or [view it fitted to the browser](capstone-dfd-preview.html).

The diagram uses the reference image's 910 x 775 canvas and exact box positions and sizes. It preserves three external entities, seven processes, and four open-ended data stores, with one occurrence of each box. All flows are labeled. White clearance at crossings distinguishes separate flows; white clearance around inline labels keeps text readable.

## Request Workflow

1. The resident sends a health service request to Process 4, as in the reference image.
2. Process 4 stores the submitted request in D2.
3. Process 2 retrieves pending requests and sends assessment details to the health worker.
4. The health worker supplies an approval or rejection decision; Process 2 records it in D2.
5. Process 4 reads the decision and service status, retrieves resident contact details, and sends notifications and coordination details.
6. Process 5 uses approved requests and scheduling inputs from D2 and location input from the resident to compute routes and schedules. It sends results to the health worker and records scheduled request details in D2.
7. Process 7 receives approved request details from Process 2 and service outcomes from the health worker. It records consultation and service transactions in D3, updates completion status in D2, and returns coordination details to the health worker. Its name is changed to Record Service Outcomes and RHU Services Coordination to match these flows.

## Other Processes

Process 1 records resident profiles in D1 and account credentials and roles in D4. Process 3 uses resident health attributes and consultation history to produce demand and follow-up outputs for Process 6. Process 6 receives reporting parameters from the administrator and produces reports using resident summaries, transaction logs, and prediction outputs.

## Figure Caption

Figure X. Level 1 data flow diagram of the Rural Health Unit system.

## Scope

This is a corrected logical design based on the supplied diagram. Context-diagram balancing and correspondence to implemented prediction, routing, and request storage still require checking against the system documentation. D1-D4 are logical data stores, not necessarily individual database tables.

The SVG is the intended figure with the original box notation. The companion Mermaid source captures the same flows but uses Mermaid's default symbols and layout.
