# Graph Report - .  (2026-05-13)

## Corpus Check
- Corpus is ~3,359 words - fits in a single context window. You may not need a graph.

## Summary
- 45 nodes · 46 edges · 12 communities (5 shown, 7 thin omitted)
- Extraction: 91% EXTRACTED · 9% INFERRED · 0% AMBIGUOUS · INFERRED: 4 edges (avg confidence: 0.9)
- Token cost: 15,435 input · 10,291 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Project Overview & Tech Stack|Project Overview & Tech Stack]]
- [[_COMMUNITY_Dashboard & Authentication|Dashboard & Authentication]]
- [[_COMMUNITY_Delivery & Reports|Delivery & Reports]]
- [[_COMMUNITY_Tracking & Maps|Tracking & Maps]]
- [[_COMMUNITY_Payments Integration|Payments Integration]]
- [[_COMMUNITY_Driver Management|Driver Management]]
- [[_COMMUNITY_Emergency Response|Emergency Response]]
- [[_COMMUNITY_Customer Portal|Customer Portal]]
- [[_COMMUNITY_Fleet Management|Fleet Management]]
- [[_COMMUNITY_Deployment & Hosting|Deployment & Hosting]]
- [[_COMMUNITY_Notifications|Notifications]]
- [[_COMMUNITY_System Settings|System Settings]]

## God Nodes (most connected - your core abstractions)
1. `Multiple Courier Truck Management System` - 30 edges
2. `Tracking Module` - 4 edges
3. `Payment Module` - 3 edges
4. `Reports Module` - 3 edges
5. `Dashboard Module` - 3 edges
6. `Chart.js` - 2 edges
7. `Leaflet.js` - 2 edges
8. `Auth Module` - 2 edges
9. `Fleet Module` - 2 edges
10. `Driver Module` - 2 edges

## Surprising Connections (you probably didn't know these)
- `Multiple Courier Truck Management System` --uses--> `Chart.js`  [EXTRACTED]
  README.md → README.md  _Bridges community 0 → community 1_
- `Multiple Courier Truck Management System` --uses--> `Leaflet.js`  [EXTRACTED]
  README.md → README.md  _Bridges community 0 → community 3_
- `Multiple Courier Truck Management System` --implements--> `Fleet Module`  [EXTRACTED]
  README.md → README.md  _Bridges community 0 → community 8_
- `Multiple Courier Truck Management System` --implements--> `Driver Module`  [EXTRACTED]
  README.md → README.md  _Bridges community 0 → community 5_
- `Multiple Courier Truck Management System` --implements--> `Delivery Module`  [EXTRACTED]
  README.md → README.md  _Bridges community 0 → community 2_

## Hyperedges (group relationships)
- **System Modules** — auth_module, fleet_module, driver_module, delivery_module, payment_module, emergency_module, reports_module, customer_portal, dashboard_module, tracking_module [EXTRACTED 1.00]
- **Database Schema** — users_table, trucks_table, drivers_table, customers_table, deliveries_table, tracking_logs_table, fuel_records_table, emergencies_table, payments_table, notifications_table, settings_table [EXTRACTED 1.00]
- **Frontend Tech Stack** — html5, css3, bootstrap5, javascript_es6, jquery, chartjs, leafletjs, font_awesome [EXTRACTED 1.00]

## Communities (12 total, 7 thin omitted)

### Community 0 - "Project Overview & Tech Stack"
Cohesion: 0.11
Nodes (18): Bootstrap 5, Bungoma National Polytechnic, CSS3, Font Awesome, Free Tools Strategy, HTML5, JavaScript (ES6), jQuery (+10 more)

### Community 1 - "Dashboard & Authentication"
Cohesion: 0.5
Nodes (4): Auth Module, Chart.js, Dashboard Module, users Table

### Community 2 - "Delivery & Reports"
Cohesion: 0.5
Nodes (4): deliveries Table, Delivery Module, fuel_records Table, Reports Module

### Community 3 - "Tracking & Maps"
Cohesion: 0.5
Nodes (4): Leaflet.js, OpenStreetMap, tracking_logs Table, Tracking Module

### Community 4 - "Payments Integration"
Cohesion: 0.67
Nodes (3): M-Pesa Daraja API, Payment Module, payments Table

## Knowledge Gaps
- **29 isolated node(s):** `Bungoma National Polytechnic`, `Web Application`, `HTML5`, `CSS3`, `Bootstrap 5` (+24 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **7 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Multiple Courier Truck Management System` connect `Project Overview & Tech Stack` to `Dashboard & Authentication`, `Delivery & Reports`, `Tracking & Maps`, `Payments Integration`, `Driver Management`, `Emergency Response`, `Customer Portal`, `Fleet Management`, `Deployment & Hosting`?**
  _High betweenness centrality (0.885) - this node is a cross-community bridge._
- **Why does `Payment Module` connect `Payments Integration` to `Project Overview & Tech Stack`?**
  _High betweenness centrality (0.086) - this node is a cross-community bridge._
- **Why does `Tracking Module` connect `Tracking & Maps` to `Project Overview & Tech Stack`?**
  _High betweenness centrality (0.086) - this node is a cross-community bridge._
- **Are the 4 inferred relationships involving `Multiple Courier Truck Management System` (e.g. with `Three-Tier Architecture` and `Kenyan Logistics Context`) actually correct?**
  _`Multiple Courier Truck Management System` has 4 INFERRED edges - model-reasoned connections that need verification._
- **What connects `Bungoma National Polytechnic`, `Web Application`, `HTML5` to the rest of the system?**
  _29 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Project Overview & Tech Stack` be split into smaller, more focused modules?**
  _Cohesion score 0.11 - nodes in this community are weakly interconnected._