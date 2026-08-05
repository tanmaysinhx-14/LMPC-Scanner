
**Hriddho**
-
1. Login Page validation is working.
2. In case any validation fails, `die()` function is being called which terminates the execution of the script immediately (highly inconvenient).
3. Instead of this `die()` function, there must exist a function named "setToast()" which takes 3 parameters with it; namely 'message', 'duration', and 'type'.
4. On function `setToast()`, being called, it triggers two things: A new `<div>` element or something similar gets created and appended to the DOM. Another is the JS block, which handles for how long this new appended element will be visible on the screen.
5. If possible, the function `setToast()` must use SESSION storage; provided by $_SESSION global variable in PHP.
6. 'type' attribute of the `setToast()` function determines the color and style of the toast message. Say 'success' or 'danger', it will make the toast appear 'green' and 'red' respectively.
7. Adjust the `<a> <link>` and `<script>` tags; whatsoever uses 'href' attribute according to the new directory structure.

**Srinjan**
1. The project will consist of 3 user roles. 'Citizen', 'Admin' and 'Worker'. Each user role will have distinct permissions and functionalities within the application.
2. For database, we need to store information related to each user role, including their unique identifiers, credentials, and specific attributes associated with their permissions and functionalities.
3. Create relationships between these three user roles.
- Credentials storing and handling of each user.
- Data entry by citizen; storing of this data for the admin and worker.
- Caching of this raw data into database/redis for faster data retrieval.
- Statistics generation and storing.