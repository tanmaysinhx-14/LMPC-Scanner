<?php // Backend for Login
  // Check for success message from registration
  $successMessage = '';
  if (isset($_GET['success'])) {
    $successMessage = htmlspecialchars($_GET['success']);
  }

  // Initialize variables
  $error = '';
  $email = '';
  $role = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = sanitizeInput($_POST['password'] ?? '');
    $selectedRole = sanitizeInput($_POST['role'] ?? '');
    $rememberMe = isset($_POST['rememberMe']);

    // Validate inputs
    if (empty($email) || empty($password) || empty($selectedRole)) {
      $error = "Please fill in all required fields.";
    } elseif (!validateEmail($email)) {
      $error = "Please enter a valid email address.";
    } elseif (!validatePassword($password)) {
      $error = "Password must be at least 8 characters long, contain at least one number, and one symbol.";
    } else {
      // Check if user exists with this email
      $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
      $stmt->execute([$email]);
      $user = $stmt->fetch();

      if (!$user) {
        $error = "No account found with this email address.";
      } else {
        // Verify password (assuming password is hashed with password_hash)
        // For demo, we'll check plain text (in production, use password_verify)
        if ($password !== $user['password_hash'] && !password_verify($password, $user['password_hash'])) {
          $error = "Invalid password. Please try again.";
        } elseif ($user['role'] !== $selectedRole) {
          // Check if the selected role matches the user's actual role
          $error = "You are registered as a " . ucfirst($user['role']) . ". Please select the correct role.";
        } else {
          // Login successful - Set session variables
          $_SESSION['user_id'] = $user['id'];
          $_SESSION['user_name'] = $user['full_name'];
          $_SESSION['user_email'] = $user['email'];
          $_SESSION['user_role'] = $user['role'];
          $_SESSION['logged_in'] = true;

          // Set remember me cookie if checked
          if ($rememberMe) {
            setcookie('user_email', $email, time() + (86400 * 30), "/");
            setcookie('user_role', $user['role'], time() + (86400 * 30), "/");
          }

          // Redirect based on role
          switch ($user['role']) {
            case 'citizen':
              header("Location: ../citizen/dashboard.php");
              break;
            case 'authority':
              header("Location: ../authority/dashboard.php");
              break;
            case 'admin':
              header("Location: ../admin/dashboard.php");
              break;
            default:
              header("Location: ../citizen/dashboard.php");
          }
          exit();
        }
      }
    }
  }

  // Check for remember me cookie
  if (isset($_COOKIE['user_email']) && isset($_COOKIE['user_role'])) {
    $email = $_COOKIE['user_email'];
    $role = $_COOKIE['user_role'];
  }
?>