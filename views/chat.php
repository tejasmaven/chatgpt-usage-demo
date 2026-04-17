<main class="content">
    <h2>ChatGPT Test</h2>
    <p>Send a prompt using your saved standard API key.</p>

    <div class="card">
        <form method="post" action="index.php?page=chat-submit" class="stack">
            <label for="prompt">Prompt</label>
            <textarea
                id="prompt"
                name="prompt"
                rows="6"
                placeholder="Type your prompt here..."
            ><?php echo h($prompt ?? ''); ?></textarea>

            <button type="submit">Send Prompt</button>
        </form>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="card">
            <p class="error-text"><strong>Error:</strong> <?php echo h((string) $errorMessage); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($aiResponse)): ?>
        <div class="card">
            <h3>Prompt</h3>
            <p><?php echo nl2br(h((string) $prompt)); ?></p>

            <h3>Response</h3>
            <div class="response-box"><?php echo nl2br(h((string) $aiResponse)); ?></div>

            <?php if ($totalTokens !== null): ?>
                <p><strong>Total tokens:</strong> <?php echo h((string) $totalTokens); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
