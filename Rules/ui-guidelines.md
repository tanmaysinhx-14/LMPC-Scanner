# ui-guidelines.md

## The Minimalist Prototyping Strategy
UI and UX are heavily weighted in Round 2 (Usability and Interface) and Round 3 (User Experience) of the SIH evaluation[cite: 1]. Attempting to build complex, intricately animated interfaces from scratch drains critical backend development time and results in fragile, buggy prototypes[cite: 1]. This design system prioritizes deployment speed, accessibility, and a minimalist aesthetic to ensure rapid prototyping that looks like enterprise-grade software[cite: 1].

## Component-Driven Architecture
We will use a utility-first CSS framework combined with an unstyled component library to achieve a high-fidelity deployment in 36 hours.

*   **Tech Stack:** Tailwind CSS + Shadcn UI[cite: 1].
*   **Why Shadcn UI:** Unlike opaque component libraries where we fight internal styling overrides, Shadcn provides accessible (Radix UI) components that we copy and paste directly into our source code[cite: 1]. This gives us absolute control while providing an instant, premium aesthetic[cite: 1].
*   **Mandatory Components:**
    *   *Navigation:* Menubar, Drawer, Breadcrumb, Tabs (reduces cognitive load without complex state management)[cite: 1].
    *   *Data:* Data Table, Card, Chart (handles high data density with built-in pagination)[cite: 1].
    *   *Input:* Combobox, Input OTP, Checkbox (accessible primitives with smooth keyboard navigation)[cite: 1].
    *   *Feedback:* Alert Dialog, Hover Card, Context Menu (keeps users in-context for a single-page app feel)[cite: 1].

## Minimalist Aesthetic Parameters
We must adhere to these mathematical constraints to prevent arbitrary styling and visual clutter during the exhaustion of the hackathon sprint[cite: 1].

### 1. The Color System (60-30-10 Rule)
*   **Backgrounds (60%):** Pure white (`#FFFFFF`) or ultra-light grays (`#F8FAFC`) for light mode; deep slate (`#0F172A`) for dark mode[cite: 1].
*   **Typography (30%):** Near-black (`#1E293B`) for primary text and muted gray (`#64748B`) for secondary text to ensure high contrast and accessibility scoring[cite: 1].
*   **Primary Accent (10%):** A single brand color linked to the problem statement theme (e.g., forest green for agriculture, cobalt blue for enterprise)[cite: 1].

### 2. Spacing and Geometry
*   **Grid:** Rely exclusively on Tailwind's 4-point grid system (`p-4`, `m-8`) to maintain consistent padding and margins[cite: 1].
*   **Radii:** Use subtle border radii (`rounded-md` or `rounded-lg`) with faint borders (`border-gray-200`)[cite: 1].
*   **Shadows:** Strictly forbid heavy drop shadows; use subtle, diffused elevation shadows only for modals, dropdowns, and floating action buttons[cite: 1].

### 3. Typographical Hierarchy
*   **Fonts:** Use clean, sans-serif fonts[cite: 1].
*   **Headers:** Heavy font weights and tight tracking (letter spacing) for an authoritative look[cite: 1].
*   **Body:** Regular weights with optimized line heights (1.5 or 1.6) for sustained reading[cite: 1].
*   **Microcopy:** Small, uppercase, heavily tracked text for badges and tags[cite: 1].

## Figma Translation Workflow
For visual iteration before coding, use pre-built design systems that align with our stack (e.g., Preline Figma design system or Shadcn community UI kits)[cite: 1]. Because these Figma components map perfectly to Tailwind/React, transitioning from visual design to the functional prototype is nearly instantaneous, preserving backend engineering hours[cite: 1].

## Reference Links for the Team
*   [Shadcn UI Foundation](https://ui.shadcn.com/)[cite: 1]
*   [Shadcn UI Components](https://ui.shadcn.com/docs/components)[cite: 1]
*   [Preline Figma Design System for Tailwind](https://preline.co/figma/)[cite: 1]