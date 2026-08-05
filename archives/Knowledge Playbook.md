# Smart India Hackathon Software Edition: Comprehensive Engineering Patterns and Success Strategies

## Problem Statement Selection Patterns
The foundation of a victorious campaign in the Smart India Hackathon (SIH) Software Edition is established long before a single line of code is written, beginning with the strategic selection of the problem statement (PS). Analyses of historical finalists, particularly those from highly competitive technology hubs such as Chennai and Bangalore where institutions often run rigorous mock internal hackathons, reveal that problem selection is rarely driven by superficial interest. Instead, it is a calculated decision matrix balancing domain expertise, dataset availability, and problem constraints.

A prevailing pattern among teams advancing to the Grand Finale is the deliberate avoidance of overly broad problem statements that lack defined constraints or success metrics. Problem statements categorized under Track 1 or Track 2 frequently demand extensive data processing. For instance, statements requiring Natural Language Processing (NLP) for localized or regional dialects often trap ambitious teams in data-gathering bottlenecks, as open-source corpora for these languages are extremely scarce. Consequently, successful teams tend to select highly constrained problems where the success criteria are objective and measurable.

Winning ideas are rarely characterized by unbounded, sprawling ambition. Instead, they exhibit a narrow, laser-focused scope that directly resolves the specific pain points articulated by the sponsoring ministry or organization. An illustrative example of this is the SIH 2019 victory by a team addressing the Hindustan Unilever (HUL) supply chain track-and-trace problem statement. The team initially proposed standard barcode scanning, which the corporate judges immediately dismissed as operationally impossible given the immense scale of HUL's supply chain. Rather than expanding the scope of the software, the team pivoted to a highly targeted, technologically difficult solution: bulk scanning of QR codes via a video feed processed by a custom Machine Learning (ML) model, an approach the judges initially deemed impossible. This demonstrates that teams succeeding at the highest levels choose difficult, technically demanding problem statements, but execute them with an exceptionally narrow and practical scope.

Domain familiarity acts as a significant multiplier for success. Teams that conduct extensive preliminary research into the sponsoring organization's operational realities outcompete those who treat the problem as a purely academic coding exercise. Successful participants actively interview domain experts to ensure their architecture accommodates real-world regulatory and workflow constraints. For instance, when addressing problem statement PS1286 for the Ministry of Law and Justice (developing a legal records e-vault), successful teams engaged directly with legal professionals to understand e-court workflows, thereby avoiding the proposal of technologically advanced but legally unviable solutions.

## Team Roadmap and Time Allocation
The lifecycle of a finalist team extends far beyond the highly publicized 36-hour Grand Finale, spanning several months of structured, phased development. Reconstructing the timelines of winning teams reveals a consistent methodology characterized by rigorous internal milestones and adaptive time allocation.

### Comprehensive Lifecycle Phase Breakdown

| Development Phase | Timeline Context | Approx. Time Allocation | Primary Objectives & Deliverables | Common Anti-Patterns & Mistakes |
| :--- | :--- | :--- | :--- | :--- |
| Research & Ideation | 1–3 weeks prior to internal selection | 15% | Problem selection, workflow mapping, domain expert consultations, architecture planning, and feasibility analysis. | Selecting problems outside the team's core technical competencies; failing to analyze the scale of the problem. |
| Internal Hackathon Prototyping | 2–4 days prior to college submission | 20% | Building a minimum viable frontend, mocking API responses, drafting the official SIH presentation template. | Presenting purely theoretical ideas without a functional UI; ignoring official presentation templates. |
| Pre-Finale Development | 20–30 days post-shortlisting | 40% | Core backend logic, database schema finalization, ML model training, establishing API contracts, CI/CD pipeline setup. | Working in strict silos leading to late-stage integration failures; exhausting team energy before the finale. |
| Grand Finale Execution | The 36-Hour Event | 25% | Implementing mentor feedback, integrating ML/Backend/Frontend, refining UI/UX, perfecting the final pitch and presentation. | Attempting complete architectural rewrites; failing to implement shift-sleeping; breaking the build in the final hours. |

The 36-hour Grand Finale itself operates on a highly compressed, dynamic schedule driven by alternating mentoring and judging rounds. Successful teams generally divide these 36 hours into three distinct blocks. The first 12 hours are dedicated to solidifying the core features developed during the pre-finale phase and setting up the staging environment. The middle 12 hours represent a critical pivot point; mentors frequently suggest "off-track" implementations or specific feature additions. Teams must rapidly adapt their architecture to accommodate these requests, as subsequent judging rounds heavily weight a team's ability to integrate feedback. The final 12 hours are strictly reserved for system integration, UI/UX polish, and pitch rehearsal. A widespread mistake observed across unsuccessful teams is coding until the final minute; winning teams often enforce a strict "code freeze" several hours before the final pitch to focus entirely on the business narrative, presentation slide adjustments, and demonstration flow.

## Novelty: Ideation and Implementation
The official SIH evaluation rubric allocates a significant 20% of the total score specifically to the novelty and originality of the approach. An analysis of the solutions presented by winners indicates that novelty in the context of SIH rarely equates to inventing entirely new technological paradigms or base algorithms. Instead, the most highly rewarded novelty stems from applying existing, robust technologies to traditional, antiquated workflows in unprecedented ways to drive operational efficiency.

Weak novelty is frequently characterized by the artificial inflation of a technology stack with contemporary buzzwords. Teams that unnecessarily integrate blockchain for a simple relational database problem, or utilize computationally heavy Large Language Models (LLMs) for basic conditional logic, are consistently penalized by expert judges. These approaches signal a fundamental misunderstanding of commercial viability and cost-effectiveness, which accounts for another 25% of the evaluation rubric.

Conversely, strong novelty solves existing problems with demonstrably better, faster, or cheaper methods, focusing heavily on user accessibility and offline capabilities. For example, in agricultural problem statements, novelty is often found not in complex yield prediction algorithms, but in the integration of regional language voice-to-text models that allow illiterate farmers to interact with contract systems audibly. In the domain of digital forensics, the UFDR Analyzer project demonstrated high novelty by automating the extraction and summarization of massive digital forensic reports using advanced Optical Character Recognition (OCR) and localized AI models, completely redefining the manual workflow of investigators. Furthermore, teams building for disaster management or rural healthcare often achieve novelty by ensuring their platforms function perfectly in low-bandwidth or offline environments, syncing data only when a connection is re-established.

## Architecture Decisions and Tech Stack
The technological foundation of successful SIH projects leans heavily toward proven, rapid-development frameworks rather than highly experimental languages. The necessity to build, debug, and deploy within a highly constrained timeframe dictates that teams choose stacks offering vast component reusability and extensive community support.

### System Architecture and Database Design
Historically, the MERN stack (MongoDB, Express.js, React, Node.js) has dominated the Software Edition due to its non-blocking asynchronous nature and unified language (JavaScript/TypeScript) across the entire stack. However, an analysis of repositories from 2023 to 2026 reveals a significant shift toward Next.js for unified frontend/backend rendering, coupled with Python-based microservices (using FastAPI or Flask) to handle computationally heavy AI and ML tasks. For mobile applications, Flutter and Dart remain the overwhelming preference for their ability to generate cross-platform iOS and Android binaries from a single codebase.

Database selection is strictly dictated by the problem's data structure requirements. Relational databases such as PostgreSQL or MySQL are implemented when ACID compliance, complex joins, and structured reporting are non-negotiable, such as in the tracking of legal records, financial compliance, or the AICTE model curriculum portal. NoSQL solutions, particularly MongoDB and Firebase, are favored for their schema-less flexibility. Firebase is exceptionally popular among hackathon teams because it provides out-of-the-box authentication (OAuth), real-time database syncing, and web socket management, saving teams hours of boilerplate configuration.

### Folder Structures and Modularization
Winning repositories exhibit a strict separation of concerns, which is a defensive engineering tactic designed to prevent merge conflicts during the 36-hour sprint. A standard modularized folder structure extracted from the HexxCode SIH 2023 winning repository, as well as modern Next.js implementations, demonstrates clear boundaries:
*   `client/` or `frontend/`: Houses the React/Next.js UI components, state management (Redux/Context), and CSS/Tailwind assets.
*   `server/` or `backend/`: Contains the core Node.js/Express REST API, database models, controllers, and authentication middleware.
*   `PythonBackend/` or `ml_engine/`: An isolated microservice handling data science workloads, interacting with the main server via internal HTTP requests.
*   `docs/`: Contains the architecture diagrams, API specifications, and workflow logic.

### Authentication and Deployment
Authentication must be robust but quickly implementable. The integration of JSON Web Tokens (JWT) for custom stateful sessions, or Firebase Auth for seamless Google OAuth integration, is standard practice.

Traditional server setups (like raw EC2 instances) are increasingly abandoned for the presentation layer in favor of Platform-as-a-Service (PaaS) providers. Vercel and Netlify dominate frontend deployments due to their continuous integration hooks with GitHub, while Render, Heroku, or managed AWS services handle the backend. This abstraction of DevOps responsibilities ensures high availability during the final demo, protecting teams from catastrophic server configuration errors in the final hours.

### Feature Prioritization
The chronological implementation of features is governed by a strict triage system, separating absolute necessities from high-impact visual additions. This ensures that a team always has a functional fallback version of the software.

The Minimum Viable Product (MVP) features are constructed long before the Grand Finale, often prior to the internal college review. During this phase, teams focus entirely on basic CRUD (Create, Read, Update, Delete) operations and a functional Graphical User Interface (GUI). Complex integrations are mocked; authentication may be bypassed with hardcoded logic, and dummy JSON data is heavily prevalent.

In the weeks preceding the Grand Finale, teams transition from mocked data to real integrations. Genuine authentication protocols are established, database schemas are finalized, and the core algorithmic logic is connected to the API. By the time the team arrives at the nodal center, the application must perfectly reflect the architecture detailed in their submitted presentation.

During the 36-hour event, feature prioritization becomes highly reactive. Mentors evaluate the prototype in the first round and frequently issue specific challenges or suggest workflow modifications. Teams must prioritize these mentor requests above all existing plans. Implementing a mentor's specific suggestion—such as adding Role-Based Access Control (RBAC), specific data filters, or enhanced security audits—proves to the judges that the team is adaptable and responsive to stakeholder feedback.

Conversely, highly complex integrations such as live payment gateways, hardware-in-the-loop IoT dependencies, or real-time distributed syncing are frequently abandoned mid-hackathon. When teams realize the time constraints and the risk of breaking the main branch, they strategically retreat, falling back on pre-recorded videos or mock endpoints to simulate these features during the final presentation.

## Project Management and Constraints
Executing a complex software project under extreme time constraints and sleep deprivation requires rigorous project management. While formal Agile sprints are too heavy for a 36-hour event, disciplined version control, continuous communication, and proactive constraint mitigation are mandatory.

### Git Branching Strategy and Code Review
An analysis of finalist GitHub commit histories reveals that mature teams do not push directly to the `main` or `master` branch. Instead, they utilize a structured feature-branching workflow (e.g., `git checkout -b feature/auth-module`). This strategy isolates unstable code. Once a feature is complete, developers open a Pull Request (PR) against the main branch.

Even in the chaotic environment of a hackathon, successful teams appoint a designated integration manager or team lead who reviews and merges these PRs (e.g., "Merge pull request #9 from Sameer-Bagul/main"). This formal merge strategy prevents catastrophic integration failures. Merge conflicts remain a primary cause of late-stage disaster; teams that fail to regularly pull upstream changes from the main branch frequently find themselves spending the final crucial hours resolving massive conflicts rather than polishing the UI. Advanced teams increasingly leverage GitHub Actions (`build.yml`) to run automated tests before allowing a merge, ensuring the production deployment remains stable.

### Mitigating Hackathon Constraints
Teams repeatedly face the same set of environmental and technical constraints, and their mitigation strategies distinguish winners from finalists.

| Constraint Type | Manifestation in the SIH Environment | Mitigation Strategy Employed by Winners |
| :--- | :--- | :--- |
| Time & Exhaustion | Sleep deprivation leads to critical syntax errors, poor logic, and broken builds on Day 2. | Enforcing rotational rest schedules. At least two members rest while others code, ensuring a fresh team member is alert for the final pitch. |
| Dataset Scarcity | Required government APIs are inaccessible, rate-limited, or specific training datasets do not exist. | Generating synthetic datasets prior to the event; utilizing hardcoded JSON mock files to simulate API calls perfectly for the demo. |
| Integration Failures | Frontend and backend teams work independently and fail to connect the API endpoints on Day 2. | Establishing strict JSON API contracts on Day 1. The frontend consumes mock data matching the contract until the backend is fully deployed. |
| Nodal Center Infrastructure | Intermittent WiFi, blocked ports, or hardware failure at the host venue. | Bringing physical backup routers, tethering phones, and ensuring all required NPM/Pip packages are cached locally prior to arrival. |
| Judge Expectations | Judges demand business viability and scale over technical complexity. | Halting development to refine the pitch; treating the solution as a startup pitch rather than a purely academic computer science project. |

### Documentation Produced
Documentation in SIH serves a dual purpose: establishing a rigid contract for internal engineering efforts and communicating technical depth to non-technical judges.

The most critical document is the **Presentation Deck (PPT)**. The PPT dictates the narrative of the final pitch and is heavily scrutinized. It must adhere strictly to the official SIH template; failure to do so results in direct point deductions. Winning presentations avoid dense paragraphs, instead utilizing precise bullet points, flowcharts, high-level architecture diagrams, and high-fidelity UI/UX screenshots of the prototype.

For code execution and technical judging, the `README.md` is vital. A comprehensive README outlines the project's purpose, tech stack, step-by-step local installation instructions (`npm install`, `npm start`), required environment variables, and deployment links.

While formal Software Requirement Specifications (SRS) are rarely updated during the event, successful teams maintain internal markdown files, such as `API.md`, which define the exact endpoints, request payloads, and expected JSON responses. This internal documentation acts as the source of truth, allowing frontend and backend developers to work asynchronously without constant verbal communication. `Architecture.md` and `Database.md` files are increasingly used not just for human developers, but to provide rigid context to AI coding assistants.

## Demo Strategy and Final Presentation Analysis
The final presentation, often termed the "Power Round," is the definitive moment of the hackathon. The delivery mechanism heavily influences the final scoring rubric, which allocates up to 25% of marks for Criticality/Impact, 25% for Commercial Viability, and 15% for the MVP Demo.

### Execution of the Demo
Relying entirely on a live demonstration is a high-risk maneuver. While successful live deployments impress judges, unexpected server latency, broken internet connections, or unforeseen bugs can instantly derail a pitch. Consequently, highly prepared teams execute a hybrid strategy: they perform a live walkthrough of the core, most stable features, but possess a pre-recorded, high-quality video demonstrating complex workflows, hardware integrations, or processing-heavy AI tasks. The video acts as an absolute fail-safe.

Presentations are aggressively timed, often strictly limited to 5 to 7 minutes. Teams must ensure they do not waste time explaining generic technologies (e.g., explaining how React works). Instead, the narrative flow must immediately establish the magnitude of the problem, demonstrate the solution via the prototype, highlight the specific technological novelty, and conclude with sustainability and market viability.

### Handling Cross-Questions
Judges consistently probe beyond the codebase, asking targeted questions regarding business sustainability, long-term value, and operational realities. Expected inquiries include:
*   What is the projected market size and commercial viability of this solution?
*   How will the platform achieve user acquisition and manage marketing costs?
*   How does this solution distinctly differentiate itself from existing commercial products?
*   What is the system's ability to scale under heavy concurrent user loads, and how resilient is the infrastructure?

Teams that stumble during cross-examination often do so because they viewed the hackathon strictly as an engineering challenge rather than a product development incubator.

## Post-Hackathon Retrospectives
Analyzing retrospectives from participants reveals stark lessons regarding the harsh realities of rapid software development.

A recurring sentiment among finalists is the realization that fundamental elements were ignored in favor of overly complex features. For example, teams have reported losing significant points because they neglected basic authentication security or deployed a visually unappealing interface, operating under the false assumption that judges would only evaluate the backend logic. Jhanvi's retrospective of SIH 2023 highlighted a "bitter realization" when judges heavily criticized their portal for looking basic and unprofessional, forcing a panicked, error-prone redesign in the final hours.

When asked, "If we had another month, what would we do differently?" participants universally point to testing and automated deployment. The rush to push code leads to fragile, tightly coupled architectures. The most universally cited "biggest mistake" is delaying the integration of the frontend and backend until the final hours of the hackathon. This delay inevitably results in broken dependencies and non-functional UIs just as the judges approach the table for the final evaluation.

## AI-Assisted Development (2024–2026 Context)
The landscape of hackathon development has fundamentally shifted with the mainstream integration of Artificial Intelligence. Engineering teams utilize AI in two distinct paradigms: AI used to build the product (developer tooling), and AI used inside the product (core features).

### AI for Rapid Prototyping
Teams leverage AI coding assistants (e.g., GitHub Copilot, Cursor) and generative UI tools to drastically compress development timelines. Tools like Vercel v0 are utilized to generate functional React components from text prompts in seconds, while platforms like Napkin.ai, Gamma, and Tome are used to instantly generate architecture diagrams and professional pitch decks. This automation of boilerplate allows teams to dedicate the majority of their 36 hours to complex backend logic, database optimization, and data integration rather than writing manual CSS and HTML.

### AI as the Core Product Feature
Inside the application, AI has moved beyond simple predictive models into sophisticated agentic and generative workflows. Recent winning repositories feature implementations of Retrieval-Augmented Generation (RAG) using vector databases (like ChromaDB or Pinecone) and multimodal LLMs to parse complex documents, satellite imagery, or government datasets. The Anantha AI project for SIH 2025 exemplifies this, utilizing a hybrid vector-based semantic retrieval system to convert natural language queries into SQL database responses for marine climate analysis.

### Guardrails Against "Dark Code"
A critical challenge introduced by AI coding assistants is the generation of inconsistent, hallucinated, or deprecated code that breaks existing architectures. Successful teams mitigate this by enforcing strict context boundaries. Before querying an AI assistant for code generation, developers establish foundational Markdown documents that define the exact variable names, component structures, and database schemas required. By feeding this explicit context into the AI, the generated output remains aligned with the broader repository, preventing the accumulation of "dark code"—unmanaged, AI-generated technical debt that becomes impossible to debug during the final hours.

## Actionable Playbook for SIH Software Teams
Synthesizing historical patterns yields a highly practical playbook for navigating the SIH lifecycle.

### Phase 1: Pre-Hackathon Preparation Checklist
*   [ ] **Skill Mapping:** Ensure the team consists of specialized roles: at least one dedicated frontend engineer, one backend/database specialist, one presentation/business lead, and one domain expert (e.g., ML or Cloud).
*   [ ] **Domain Research:** Interview actual end-users or industry professionals related to the selected problem statement to understand non-technical bottlenecks and operational workflows.
*   [ ] **Architecture Finalization:** Select a familiar, stable tech stack (e.g., MERN or Next.js/Python). Do not attempt to learn a new framework or language during the event.
*   [ ] **Template Mastery:** Download and strictly adhere to the official SIH PPT template for the initial submission to avoid structural penalties.

### Phase 2: Internal Demo & Codebase Initialization Checklist
*   [ ] **Repository Setup:** Initialize the GitHub repository with a main branch, establish feature/ branching rules, and enforce Pull Request (PR) reviews.
*   [ ] **API Contracting:** Draft a strict JSON schema defining exactly how the frontend and backend will communicate.
*   [ ] **Mock Data Generation:** Create extensive mock datasets so frontend development is never blocked by backend delays.

### Phase 3: The 36-Hour Grand Finale Execution Checklist
*   [ ] **Triage Mentor Feedback:** Record every piece of advice from judges during mentoring rounds. Immediately prioritize implementing at least one highly visible suggestion to prove adaptability.
*   [ ] **Continuous Integration:** Merge feature branches into the main branch every 4 to 6 hours. Never leave API integration for the final 6 hours.
*   [ ] **Shift Sleeping:** Enforce a strict sleep rotation. The presentation lead must sleep in the hours leading up to the final pitch to ensure a sharp, articulate delivery.

### Phase 4: Final Submission & Demo Checklist
*   [ ] **Fail-Safe Video:** Record a seamless 3-minute video of the working prototype as an absolute backup for live demo failures.
*   [ ] **Business Metrics Preparation:** Prepare clear, concise answers regarding market size, deployment cost, commercial viability, and user acquisition strategies.
*   [ ] **UI/UX Polish:** Ensure basic security (authentication) and visual alignment are pristine; judges heavily weight first impressions and ease of use.

## Analytical Review and Evidence Structuring

### 1. Most Trustworthy Sources
The most reliable data stems from first-hand, detailed retrospective accounts of actual winners and the public GitHub repositories of finalists.
*   **GitHub Repositories (HexxCode, Team Accelerate, Anantha AI, SIH-Hub):** Provide empirical, undeniable proof of the actual tech stacks, database choices, folder structures, branching strategies, and AI integrations utilized by modern finalists.
*   **Tanmay Bhatnagar (SIH 2019 Winner):** Provides unparalleled detail into the psychological pressure, the strategic pivot to ML for bulk scanning, and the reality of interactions with corporate judges (HUL).
*   **Sarika Purohit (SIH 2022 Winner):** Offers deep clarity on team composition, the danger of integration failures, and the critical importance of handling judge critiques professionally.

### 2. Conflicting Advice Found Across Sources
*   **Live Demo vs. Recorded Video:** Some sources advocate for the bravery, impact, and authenticity of a flawless live demo. Conversely, others strongly caution that relying solely on a live demo is a recipe for disaster due to unpredictable internet and hardware failures at the nodal centers. The consensus resolves on a hybrid approach: a live demo backed by a pre-recorded video fail-safe.
*   **UI/UX Importance vs. Core Logic:** Technical developers often argue that solving the core algorithm is paramount. However, historical outcomes (such as Jhanvi's retrospective) prove that judges aggressively penalize brilliant backend logic if the frontend is poorly designed, lacks basic authentication, or fails to provide an intuitive user experience.

### 3. Confidence Level for Major Conclusions
*   **High Confidence:** The necessity of a balanced, specialized team; the catastrophic risk of late-stage API integration; the absolute requirement to strictly follow the SIH presentation template; and the heavy scoring weight placed on commercial viability and impact.
*   **Moderate Confidence:** The exact division of time during the 36 hours. While distinct patterns exist (build, iterate, polish), the dynamic nature of mentor feedback often forces teams to deviate from rigid schedules.
*   **Low Confidence:** Specific hosting providers or micro-dependencies. While AWS, Firebase, and Vercel are frequently cited in repositories, teams ultimately utilize whatever cloud credits or operational familiarity they possess at the time of the event.

### 4. Gaps in Evidence
*   **Exact API Security Implementations:** Public repositories often omit environment variables (`.env`) and deep security configurations (CORS policies, exact JWT encryption standards) for obvious security reasons. This makes it difficult to definitively analyze how robust the hackathon backends truly are against penetration testing.
*   **Mentorship Dynamics:** While it is known that mentors assign tasks and suggest pivots during the hackathon, the exact criteria mentors use to guide teams remain opaque and highly subjective, depending heavily on the nodal center and the background of the specific judge.

### 5. Knowledge Base to Prepare Before Coding (For AI Assistants)
To prevent modern AI coding assistants (like Claude or Copilot) from hallucinating incorrect variables, utilizing deprecated libraries, or breaking architectural patterns, teams must front-load the AI's context window. Establishing the following Markdown files in the project root creates a rigid, reusable knowledge base:

| Document | Purpose & Content | When to Write | How it Controls AI Hallucinations |
| :--- | :--- | :--- | :--- |
| `requirements.md` | Details the exact problem statement, user personas, MVP scope, and out-of-scope features. | Day 1 (Research Phase) | Prevents the AI from suggesting features, complex workflows, or heavy libraries that exceed the project's narrow, agreed-upon scope. |
| `architecture.md` | Defines the system topology (e.g., MVC monolithic vs. Microservices), folder structure (`/client`, `/server`), and deployment strategy. | Pre-Hackathon | Stops the AI from generating microservices orchestration code when the team is building a monolith; enforces strict, predictable file placement. |
| `tech-stack.md` | A rigid list of all languages, frameworks, and specific version numbers (e.g., Next.js 15, Tailwind 3, Express 4). | Pre-Hackathon | Prevents the AI from importing incompatible, hallucinated, or deprecated third-party packages that will break the build. |
| `api-spec.md` | The ultimate source of truth for endpoints, defining HTTP methods, expected JSON request payloads, and exact response schemas. | Internal Round | Ensures the AI writes frontend fetch requests that perfectly match the backend routing, eliminating hours of integration bugs. |
| `database.md` | Outlines the exact schema, table relationships (SQL) or document structures (NoSQL), and data types. | Pre-Hackathon | Ensures the AI writes accurate database queries and ORM interactions without guessing column names or relationships. |
| `ui-guidelines.md` | Specifies primary color hex codes, typography, padding standards, and required component libraries (e.g., Shadcn/UI). | Internal Round | Forces the AI to generate CSS/Tailwind classes that perfectly match the application's aesthetic, preventing visual fragmentation. |
| `project-rules.md` | Establishes the Git branching strategy, commit message formats, and code review requirements. | Pre-Hackathon | Guides the AI in generating appropriate terminal commands and standardizing the team's operational workflow. |

## Works cited
*   How to Prepare for Hackathons: The Ultimate Guide for Engineering Students to Win in 2026, https://www.sirtbhopal.ac.in/blogs/how-to-prepare-for-hackathons-for-engineering-students
*   Computer Science & Engineering - Vemana Institute of Technology | Bangalore, https://www.vemanait.edu.in/computer-science-and-engineering.html
*   SIH 2025 Tips and Common Mistakes - Insights from a 2022 Winner - Anish Prashun, https://www.anishprashun.me/blog/sih-2025-tips-and-common-mistakes
*   My Smart India Hackathon (SIH2023) Journey as Team Lead & Winner - Medium, https://sarika-purohit.medium.com/my-smart-india-hackathon-sih2023-journey-as-team-lead-winner-63bc00b3cd34
*   The Smart India Hackathon Experience | by Tanmay Bhatnagar - Medium, https://medium.com/@tanmaybhatnagar84976/the-smart-india-hackathon-experience-9aacbd2f8aaf
*   Smart India Hackathon 2023— Winners' edition | by Palak - Medium, https://medium.com/@palaksv25/smart-india-hackathon-2023-winners-edition-bfbb04abf5d8
*   Inside the Smart India Hackathon- A Winner's Perspective | by Arushi Sthapak | Medium, https://medium.com/@arushi.sthapak2003/inside-the-smart-india-hackathon-a-winners-perspective-798915d68afe
*   SIH 2025 Pre-Qualifier Evaluation Criteria | PDF | Usability | Computing - Scribd, https://www.scribd.com/document/923519337/Rubrics-SIH-2025
*   sih · GitHub Topics, https://github.com/topics/sih?l=javascript&o=desc&s=updated
*   smartindiahackathon2025 · GitHub Topics, https://github.com/topics/smartindiahackathon2025
*   smart-india-hackathon · GitHub Topics, https://github.com/topics/smart-india-hackathon?o=desc&s=forks
*   https://github.com/pt3002/HexxCode-SIH-2023
*   PixelPiratess/SIH-PixelPirates-Medicare - GitHub, https://github.com/PixelPiratess/SIH-PixelPirates-Medicare
*   shantanu nimkar shantanu1905 - GitHub, https://github.com/shantanu1905
*   sujallchaudhary/sih-project - GitHub, https://github.com/sujallchaudhary/sih-project
*   https://github.com/Arjun-254/SIH1348_LichtDenCode
*   smart-india-hackathon · GitHub Topics, https://github.com/topics/smart-india-hackathon?l=dart
*   Source code for AICTE's work done by Team Extrapolate (winner@SIH'17) during 2017-2018 - GitHub, https://github.com/TeamExtrapolate/extrapolate
*   shrekshrek/full-stack-scaffolding-fastapi-nuxt4: 适用于claude code或cursor的全栈脚手架, https://github.com/shrekshrek/full-stack-scaffolding-fastapi-nuxt4
*   sih2020 · GitHub Topics, https://github.com/topics/sih2020
*   Akash Vishwakarma TechWithAkash - GitHub, https://github.com/TechWithAkash
*   AnishSarkar22/SIH-2024 - Mentor-Connect - GitHub, https://github.com/AnishSarkar22/SIH-2024
*   Activity · RushabhBhalgat/HireMe-Smart-India-Hackathon - GitHub, https://github.com/RushabhBhalgat/HireMe-Smart-India-Hackathon/activity
*   Build · Workflow runs · fadillzzz/tof-sih · GitHub, https://github.com/fadillzzz/tof-sih/actions/workflows/build.yml
*   Nodal Center Organizer Manual and Guidelines - Smart India Hackathon, https://www.sih.gov.in/letters/Nodal-Center-Organizer-Manual-and-Guidelines.pdf
*   A journey to look back. SIH also known as Smart India Hackathon… | by Vidya Jaggi | Medium, https://medium.com/@vidyajaggi05/sih25-a-journey-to-look-back-99ed087c47db
*   nerdynerd09/SIH: Smart India Hackathon Project - GitHub, https://github.com/nerdynerd09/SIH
*   SEMICON India Hackathon 2026 | AI Chip Design & Semiconductor Innovation Challenge, https://i4c.in/hackathon-2026/
*   SIH Hackathon Evaluation Criteria Guide | PDF - Scribd, https://www.scribd.com/document/806781314/Marking
*   https://medium.com/@jhanvim77/smart-india-hackathon-2023-experience-ff02b5992c65
*   Smart India Hackathon Guide : Tips, Strategy & What No One Tells You | SIH 2025, https://www.youtube.com/watch?v=_S97ArKlWYQ
*   smart-india-hackathon · GitHub Topics, https://github.com/topics/smart-india-hackathon?l=python
*   smart-india-hackathon · GitHub Topics, https://github.com/topics/smart-india-hackathon?l=typescript
*   SMART INDIA HACKATHON 2025, https://www.sih.gov.in/uploads/template/Anantha-SiriusSIH2504020250929191926.pdf
*   VineshRajkumar/TEAM_ACCELERATE_SIH_SCRAPER_2024: The Official SIH_SCRAPER of TEAM ACCELERATE for SIH Hackathon 2024 - GitHub, https://github.com/VineshRajkumar/TEAM_ACCELERATE_SIH_SCRAPER_2024
