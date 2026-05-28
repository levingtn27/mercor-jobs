<?php
/**
 * Plugin Name: Mercor Jobs Board
 * Description: Displays live Mercor job listings from your GitHub-synced jobs.json
 * Version:     1.0.0
 * Author:      Levon Gevorgyan / SME Careers
 */

defined('ABSPATH') || exit;

// ── Settings ──────────────────────────────────────────────────────────────────
// Update this to your raw GitHub URL after creating the repo:
// https://raw.githubusercontent.com/YOUR_USERNAME/YOUR_REPO/main/jobs.json
define('MERCOR_JOBS_JSON_URL', 'https://raw.githubusercontent.com/levingtn27/mercor-jobs/main/jobs.json');
define('MERCOR_JOBS_CACHE_KEY', 'mercor_jobs_data');
define('MERCOR_JOBS_CACHE_TTL', 30 * MINUTE_IN_SECONDS); // 30 min cache

// ── Fetch & cache jobs ────────────────────────────────────────────────────────
function mercor_get_jobs(): array {
    $cached = get_transient(MERCOR_JOBS_CACHE_KEY);
    if ($cached !== false) return $cached;

    $response = wp_remote_get(MERCOR_JOBS_JSON_URL, ['timeout' => 10]);
    if (is_wp_error($response)) return [];

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (empty($data['jobs'])) return [];

    set_transient(MERCOR_JOBS_CACHE_KEY, $data, MERCOR_JOBS_CACHE_TTL);
    return $data;
}

// ── Shortcode: [mercor_jobs] ──────────────────────────────────────────────────
// Usage:
//   [mercor_jobs]                     — all jobs
//   [mercor_jobs tab="project-based"] — filter by tab
//   [mercor_jobs domain="Medical"]    — filter by domain keyword
//   [mercor_jobs limit="12"]          — limit results
add_shortcode('mercor_jobs', function($atts) {
    $atts = shortcode_atts([
        'tab'    => '',
        'domain' => '',
        'limit'  => 0,
    ], $atts, 'mercor_jobs');

    $data = mercor_get_jobs();
    $jobs = $data['jobs'] ?? [];
    $updated_at = $data['updated_at'] ?? '';

    if (!$jobs) {
        return '<p class="mercor-error">Jobs are loading — check back shortly.</p>';
    }

    // Filter
    if ($atts['tab']) {
        $jobs = array_filter($jobs, fn($j) => $j['tab'] === $atts['tab']);
    }
    if ($atts['domain']) {
        $kw = strtolower($atts['domain']);
        $jobs = array_filter($jobs, fn($j) => str_contains(strtolower($j['domain'] ?? ''), $kw));
    }
    if ($atts['limit'] > 0) {
        $jobs = array_slice($jobs, 0, (int)$atts['limit']);
    }

    ob_start();
    ?>
    <div class="mercor-jobs-board" id="mercor-jobs">
        <div class="mercor-jobs-header">
            <span class="mercor-jobs-count"><?= count($jobs) ?> open roles</span>
            <?php if ($updated_at): ?>
                <span class="mercor-jobs-updated">Updated <?= esc_html(human_time_diff(strtotime($updated_at))) ?> ago</span>
            <?php endif; ?>
        </div>

        <div class="mercor-jobs-grid">
        <?php foreach ($jobs as $job): ?>
            <div class="mercor-job-card <?= $job['is_new'] ? 'is-new' : '' ?> <?= ($job['closing_soon'] ?? false) ? 'is-closing' : '' ?>">
                <?php if ($job['is_new']): ?>
                    <span class="mercor-badge mercor-badge-new">✦ New</span>
                <?php endif; ?>
                <?php if ($job['closing_soon'] ?? false): ?>
                    <span class="mercor-badge mercor-badge-closing">⏰ Closing soon</span>
                <?php endif; ?>

                <h3 class="mercor-job-title"><?= esc_html($job['title']) ?></h3>

                <div class="mercor-job-meta">
                    <span class="mercor-job-pay"><?= esc_html($job['pay']) ?></span>
                    <span class="mercor-job-location"><?= esc_html($job['location'] ?: 'Remote') ?></span>
                    <?php if ($job['hired_count']): ?>
                        <span class="mercor-job-hired"><?= esc_html($job['hired_count']) ?> hired this month</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($job['eligible'])): ?>
                <div class="mercor-job-eligible">
                    <?php foreach ((array)$job['eligible'] as $loc): ?>
                        <span class="mercor-location-tag"><?= esc_html($loc) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="mercor-job-footer">
                    <?php if ($job['referral_bonus']): ?>
                        <span class="mercor-referral-bonus">💰 $<?= esc_html($job['referral_bonus']) ?> referral bonus</span>
                    <?php endif; ?>
                    <a href="<?= esc_url($job['referral_link']) ?>"
                       class="mercor-apply-btn"
                       target="_blank"
                       rel="noopener"
                       data-job-id="<?= esc_attr($job['id']) ?>">
                        Apply Now →
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <style>
    .mercor-jobs-board {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        max-width: 1200px;
        margin: 0 auto;
    }
    .mercor-jobs-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        font-size: 14px;
        color: #666;
    }
    .mercor-jobs-count { font-weight: 600; color: #333; }
    .mercor-jobs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
    }
    .mercor-job-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 20px;
        position: relative;
        transition: box-shadow 0.2s, transform 0.2s;
    }
    .mercor-job-card:hover {
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        transform: translateY(-2px);
    }
    .mercor-job-card.is-new    { border-color: #7C3AED; }
    .mercor-job-card.is-closing { border-color: #F59E0B; }
    .mercor-badge {
        display: inline-block;
        font-size: 11px;
        font-weight: 600;
        padding: 3px 9px;
        border-radius: 20px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 10px;
        margin-right: 5px;
    }
    .mercor-badge-new {
        background: #EDE9FE;
        color: #6D28D9;
    }
    .mercor-badge-closing {
        background: #FEF3C7;
        color: #92400E;
    }
    .mercor-job-title {
        font-size: 16px;
        font-weight: 600;
        margin: 0 0 12px;
        color: #111;
        line-height: 1.3;
    }
    .mercor-job-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }
    .mercor-job-pay {
        font-size: 15px;
        font-weight: 700;
        color: #7C3AED;
    }
    .mercor-job-location,
    .mercor-job-hired {
        font-size: 13px;
        color: #666;
        display: flex;
        align-items: center;
        gap: 3px;
    }
    .mercor-job-eligible {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-bottom: 12px;
    }
    .mercor-location-tag {
        font-size: 12px;
        background: #f3f4f6;
        color: #374151;
        padding: 2px 8px;
        border-radius: 4px;
    }
    .mercor-job-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px solid #f3f4f6;
    }
    .mercor-referral-bonus {
        font-size: 12px;
        color: #059669;
        font-weight: 500;
    }
    .mercor-apply-btn {
        display: inline-block;
        background: #7C3AED;
        color: #fff !important;
        text-decoration: none !important;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        transition: background 0.15s;
        white-space: nowrap;
    }
    .mercor-apply-btn:hover { background: #6D28D9; }
    @media (max-width: 640px) {
        .mercor-jobs-grid { grid-template-columns: 1fr; }
    }
    </style>
    <?php
    return ob_get_clean();
});

// ── Admin: manual cache clear ─────────────────────────────────────────────────
add_action('admin_init', function() {
    if (isset($_GET['mercor_clear_cache']) && current_user_can('manage_options')) {
        delete_transient(MERCOR_JOBS_CACHE_KEY);
        wp_redirect(admin_url('options-general.php?page=mercor-jobs&cleared=1'));
        exit;
    }
});
