<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? APP_NAME ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="assets/js/subtask-manager.js" defer></script>
</head>
<body>
    <?php if (isset($show_nav) && $show_nav && isLoggedIn()): ?>
    <nav class="navbar">
        <div class="nav-brand">
            <i class="fas fa-project-diagram"></i>
            <span><?= APP_NAME ?></span>
        </div>
        <div class="nav-menu">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="projects.php" class="nav-link">
                <i class="fas fa-folder-open"></i> Projects
            </a>
            <a href="tasks.php" class="nav-link">
                <i class="fas fa-tasks"></i> Tasks
            </a>
            <a href="contacts.php" class="nav-link">
                <i class="fas fa-address-book"></i> Contacts
            </a>
            <div class="nav-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-user-circle"></i>
                    <?= e(getCurrentUser()['name'] ?? 'User') ?>
                    <i class="fas fa-chevron-down"></i>
                </a>
                <div class="dropdown-menu">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-edit"></i> Profile
                    </a>
                    <a href="logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <?php endif; ?>
    
    <main class="main-content <?= (isset($show_nav) && $show_nav && isLoggedIn()) ? 'with-nav' : 'no-nav' ?>">
        <?= displayMessage() ?>