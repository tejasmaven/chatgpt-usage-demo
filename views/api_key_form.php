<main class="content">
    <h2>Configure OpenAI API Key</h2>
    <p>Enter your API key to enable usage tracking.</p>

    <div class="card">
        <p><strong>Current key:</strong> <?php echo h($maskedApiKey); ?></p>

        <form method="post" action="index.php?page=save-api-key" class="stack">
            <label for="openai_api_key">OpenAI API Key</label>
            <input
                type="password"
                id="openai_api_key"
                name="openai_api_key"
                placeholder="sk-..."
                required
            >
            <button type="submit">Save API Key</button>
        </form>
    </div>
</main>
