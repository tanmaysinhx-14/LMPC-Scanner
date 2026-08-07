
**Hriddho**
-
1. Login Page validation is working.
2. In case any validation fails, `die()` function is being called which terminates the execution of the script immediately (highly inconvenient).
3. Instead of this `die()` function, there must exist a function named "setToast()" which takes 3 parameters with it; namely 'message', 'duration', and 'type'.
4. On function `setToast()`, being called, it triggers two things: A new `<div>` element or something similar gets created and appended to the DOM. Another is the JS block, which handles for how long this new appended element will be visible on the screen.
5. If possible, the function `setToast()` must use SESSION storage; provided by $_SESSION global variable in PHP.
6. 'type' attribute of the `setToast()` function determines the color and style of the toast message. Say 'success' or 'danger', it will make the toast appear 'green' and 'red' respectively.
7. Adjust the `<a> <link>` and `<script>` tags; whatsoever uses 'href' attribute according to the new directory structure.
8. Three New Webpages Request: 
- Main Dashboard; where citizen can see the progress of their own reported work.
- Profile Page 
- Reddit Home Feed based page where citizens of a city can see the overall statistics and progress of report works for their entire city. For a highly reported issue, upvote counter shall be present, showing how many times the problem has been reported.
- Photo Clicker Page with image upload and sharing capabilities. 
- Map UI to show heatmap data. Red zones indicate areas with higher issues. Yellow with fewer and green with the fewest.

**Srinjan**
Suggested by Claude:
2.1 — Automated AI Categorization and Severity Detection

The innovation: Replace the dropdown form with a zero-input AI inference pipeline. The citizen uploads a photo — nothing else is mandatory. The system returns: category, severity (1–5), department assignment, confidence score, and manipulation_flag.

Why this eliminates manual data entry: The category dropdown causes misclassification errors (citizens selecting "water leakage" for a drainage issue that belongs to a different department) which cause tickets to be routed wrong and stagnate. AI inference removes this human error entirely.

Severity heuristics beyond simple classification:

severity_score = base_score(class) 
                 × location_multiplier(highway=2.0, arterial=1.5, residential=1.0)
                 × bbox_ratio_factor(bbox_area / image_area → larger object = more severe)
                 × time_decay_factor(hours_unresolved → severity escalates over time)

A pothole occupying 40% of the image frame on a highway = severity 5. The same pothole at 5% frame occupancy on a lane = severity 2. This is something no existing system does.


Introductory Boilerplate available in ai-service/ directory.