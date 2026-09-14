# Problem Statement & Context: SIH26034

**Problem ID:** SIH26034[cite: 2, 8]  
**Problem Owner:** Ministry of Consumer Affairs, Food & Public Distribution[cite: 8]  
**Official Statement:** "Software System to check compliance of Packaged Commodities under the Legal Metrology (Packaged Commodities) Rules, 2011 by scanning products, images and labels."[cite: 2, 8]  
**Repository Classification:** Software; Agriculture, FoodTech & Rural Development[cite: 8]

---

## 1. Regulatory Background

The Legal Metrology (Packaged Commodities) Rules, 2011 (LMPC Rules), promulgated under the Legal Metrology Act, 2009, strictly mandate that all pre-packaged commodities intended for retail sale bear specific, legible, and unambiguous declarations[cite: 5]. Compliance is an absolute statutory requirement for any manufacturer, importer, packer, or marketer dealing in pre-packaged goods[cite: 5]. 

Rule 6 of the LMPC Rules defines the mandatory declarations, which include:
* The generic or common name of the commodity[cite: 5].
* The complete name and address of the manufacturer, packer, or importer[cite: 5].
* Net quantity expressed in standard SI units (e.g., g, kg, ml, l) without pluralization (e.g., "kg", not "kgs")[cite: 5].
* The month and year of manufacture, packing, or import, alongside explicit "Best before" or "Use by" dates for perishable goods[cite: 5].
* The Maximum Retail Price (MRP), which must explicitly include the phrase "inclusive of all taxes"[cite: 5].
* Consumer care details, including contact numbers and email addresses[cite: 5].

Non-compliance with these rules constitutes a legal offense under Section 36 of the Legal Metrology Act, 2009[cite: 5]. Penalties are severe and compounding, starting at fines of up to ₹25,000 for a first offense, scaling up to ₹1,00,000 or imprisonment for subsequent infractions[cite: 5].

## 2. The Enforcement Bottleneck

Despite the strict liabilities associated with LMPC regulations, the current enforcement paradigm is heavily reliant on manual spot-checks conducted by metrology inspectors[cite: 5]. This manual verification protocol poses significant challenges:
* It is inherently unscalable and fundamentally insufficient for regulating the vast throughput of modern retail distribution and massive e-commerce networks[cite: 5].
* It is highly prone to human error and inconsistency when evaluating complex, dense, and physically degraded packaging labels[cite: 5, 8].

## 3. Technical Challenges

Building a computer vision and NLP system to automate this compliance scanning is vastly more complex than a standard document-OCR task. The system must navigate the physical realities of Fast-Moving Consumer Goods (FMCG) packaging:
* **Optical Distortion:** Retail packaging features specular highlights on glossy polymer films, anisotropic reflections on metallic foils, cylindrical curvature on bottles and cans, and physical wrinkles or damage incurred during transit[cite: 5].
* **Print Limitations:** Statutory text is frequently printed using low-contrast, dot-matrix hardware directly onto the production line, resulting in fragmented characters, or presented in extremely small type sizes that challenge standard camera resolution[cite: 5, 8].
* **The Task Chain:** The practical problem is not just text extraction, but a chain of sequential dependencies:
  1. Locate the correct declaration regions on a chaotic product image[cite: 8].
  2. Extract the text accurately despite severe optical noise[cite: 8].
  3. Normalize common OCR errors without inventing or hallucinating declarations[cite: 8].
  4. Apply repeatable, deterministic checks against complex LMPC rules[cite: 8].
  5. Provide an explainable result, rather than an opaque model score, to the inspecting officer[cite: 8].
  6. Securely preserve images and results as forensic evidence for later audit and verification[cite: 8].

## 4. Target Users and Operating Model

To sidestep the friction of requiring retailer or manufacturer adoption, this solution is explicitly positioned as an augmented-reality enforcement tool tailored for on-ground officers[cite: 5]. The operating model features a strict Role-Based Access Control (RBAC) hierarchy[cite: 2, 8]:
* **Legal Metrology Inspectors:** Field officers who capture images via upload or live camera streams, initiate the automated analysis, review explainable OCR diagnostics, and submit pending scans[cite: 2, 8].
* **Compliance Verifiers:** Human-in-the-loop reviewers who evaluate the pending scans, inspect the evidence, and formally approve or override the automated finding (with mandatory written reasons) to issue a legally final determination[cite: 2, 8].
* **Administrators:** Personnel responsible for managing analytics, tracking violation rates and potential penalties, managing user accounts, and overseeing the immutable system audit trail[cite: 2, 8].