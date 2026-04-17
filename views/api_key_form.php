<main class="content">
    <h2>API Key Settings</h2>
    <p>Organization usage dashboard requires an Admin API key.</p>

    <div class="card">
        <p><strong>Standard API Key configured:</strong> <?php echo !empty($setting['standard_api_key']) ? 'Yes' : 'No'; ?></p>
        <p><strong>Admin API Key configured:</strong> <?php echo !empty($setting['admin_api_key']) ? 'Yes' : 'No'; ?></p>
        <p><strong>Saved Standard API Key:</strong> <?php echo h($maskedStandardApiKey); ?></p>
        <p><strong>Saved Admin API Key:</strong> <?php echo h($maskedAdminApiKey); ?></p>

        <form method="post" action="index.php?page=save-api-key" class="stack">
            <label for="standard_api_key">Standard API Key (optional)</label>
            <input
                type="password"
                id="standard_api_key"
                name="standard_api_key"
                placeholder="sk-..."
            >

            <label for="admin_api_key">Admin API Key (required for usage dashboard)</label>
            <input
                type="password"
                id="admin_api_key"
                name="admin_api_key"
                placeholder="sk-admin-..."
            >

            <small class="help-text">Organization usage endpoints require an Admin API key. Normal/project API keys may return HTTP 401/403.</small>
            <button type="submit">Save API Keys</button>
        </form>
    </div>
</main>
