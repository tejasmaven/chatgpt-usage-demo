<main class="content">
    <h2>Usage Dashboard</h2>

    <div class="card">
        <p><strong>API key status:</strong> <?php echo !empty($setting['openai_api_key']) ? 'Configured' : 'Missing'; ?></p>
        <p><strong>Last updated:</strong> <?php echo isset($usageData['records'][0]['updated_at']) ? h($usageData['records'][0]['updated_at']) : 'Never'; ?></p>
        <p><strong>Data source:</strong> <?php echo h($usageData['status']); ?></p>

        <?php if (!empty($usageData['message'])): ?>
            <p class="error-text">Could not fetch latest data: <?php echo h($usageData['message']); ?></p>
        <?php endif; ?>
    </div>

    <?php
    $totals = [
        'requests' => 0,
        'text_input' => 0,
        'text_output' => 0,
        'cached' => 0,
        'audio_input' => 0,
        'audio_output' => 0,
        'images' => 0,
        'cost' => 0.0,
    ];

    foreach ($usageData['records'] as $row) {
        $totals['requests'] += (int) $row['total_requests'];
        $totals['text_input'] += (int) $row['total_text_input_tokens'];
        $totals['text_output'] += (int) $row['total_text_output_tokens'];
        $totals['cached'] += (int) $row['total_cached_tokens'];
        $totals['audio_input'] += (int) $row['total_audio_input_tokens'];
        $totals['audio_output'] += (int) $row['total_audio_output_tokens'];
        $totals['images'] += (int) $row['total_images'];
        $totals['cost'] += (float) $row['total_cost_usd'];
    }
    ?>

    <section class="cards-grid">
        <div class="metric-card"><h3>Total Requests</h3><p><?php echo format_number($totals['requests']); ?></p></div>
        <div class="metric-card"><h3>Input Tokens</h3><p><?php echo format_number($totals['text_input']); ?></p></div>
        <div class="metric-card"><h3>Output Tokens</h3><p><?php echo format_number($totals['text_output']); ?></p></div>
        <div class="metric-card"><h3>Cached Tokens</h3><p><?php echo format_number($totals['cached']); ?></p></div>
        <div class="metric-card"><h3>Audio Input Tokens</h3><p><?php echo format_number($totals['audio_input']); ?></p></div>
        <div class="metric-card"><h3>Audio Output Tokens</h3><p><?php echo format_number($totals['audio_output']); ?></p></div>
        <div class="metric-card"><h3>Total Images</h3><p><?php echo format_number($totals['images']); ?></p></div>
        <div class="metric-card"><h3>Total Cost</h3><p><?php echo format_currency($totals['cost']); ?></p></div>
    </section>

    <div class="card">
        <h3>Daily Usage (Last 30 Days)</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Requests</th>
                    <th>Input Tokens</th>
                    <th>Output Tokens</th>
                    <th>Cached Tokens</th>
                    <th>Audio In</th>
                    <th>Audio Out</th>
                    <th>Images</th>
                    <th>Cost (USD)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($usageData['records'])): ?>
                <?php foreach ($usageData['records'] as $row): ?>
                    <tr>
                        <td><?php echo h($row['usage_date']); ?></td>
                        <td><?php echo format_number($row['total_requests']); ?></td>
                        <td><?php echo format_number($row['total_text_input_tokens']); ?></td>
                        <td><?php echo format_number($row['total_text_output_tokens']); ?></td>
                        <td><?php echo format_number($row['total_cached_tokens']); ?></td>
                        <td><?php echo format_number($row['total_audio_input_tokens']); ?></td>
                        <td><?php echo format_number($row['total_audio_output_tokens']); ?></td>
                        <td><?php echo format_number($row['total_images']); ?></td>
                        <td><?php echo format_currency($row['total_cost_usd']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9">No usage records available yet.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
