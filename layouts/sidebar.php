<?php
$currentPage = $currentPage ?? 'dashboard';
?>
<aside class="sidebar">
    <nav>
        <ul>
            <li><a href="index.php?page=dashboard" class="<?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">Dashboard</a></li>
            <li><a href="index.php?page=api-key" class="<?php echo $currentPage === 'api-key' ? 'active' : ''; ?>">API Key Settings</a></li>
        </ul>
    </nav>
</aside>
