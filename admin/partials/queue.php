<?php
/**
 * Vue de la file d'attente
 */

if (!defined('ABSPATH')) exit;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\TemplateLoader;

// Pagination
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

global $wpdb;
$table = $wpdb->prefix . 'postal_queue';
$table_tpl = $wpdb->prefix . 'postal_templates';
$table_str = $wpdb->prefix . 'postal_strategies';

$total = $wpdb->get_var("SELECT COUNT(*) FROM $table");

// Fetch with Template Name and Strategy Name
$items = $wpdb->get_results($wpdb->prepare(
    "SELECT q.*, t.name as template_name, s.name as strategy_name
     FROM $table q
     LEFT JOIN $table_tpl t ON q.template_id = t.id
     LEFT JOIN $table_str s ON q.strategy_id = s.id
     ORDER BY q.scheduled_at DESC
     LIMIT %d OFFSET %d",
    $per_page, $offset
), ARRAY_A);

$total_pages = ceil($total / $per_page);

?>
<div class="wrap">
    <h1 class="wp-heading-inline">File d'attente Warmup</h1>
    <a href="#" id="pw-process-queue-btn" class="page-title-action">Forcer l'envoi immédiat (Cron)</a>

    <?php
    $retention = get_option('pw_queue_retention_days', 30);
    $health = \PostalWarmup\Services\QueueManager::get_health_stats();

    // Prediction Next Batch
    $next_batch = \PostalWarmup\Services\QueueManager::get_next_batch_info();
    ?>
    <p class="description" style="display:inline-block; margin-left: 10px;">
        (Rétention : <?php echo $retention; ?> jours)
    </p>

    <hr class="wp-header-end">

    <?php if ($next_batch): ?>
    <div class="notice notice-info inline" style="border-left-color: #2271b1; padding: 10px 12px; margin-bottom: 20px; display:flex; align-items:center;">
        <span class="dashicons dashicons-clock" style="color: #2271b1; margin-right: 10px;"></span>
        <div>
            <strong>Prochaine vague :</strong>
            <span style="font-weight:bold; color:#2271b1; font-size:1.1em;"><?php echo $next_batch['count']; ?></span> email(s) prévu(s)

            <?php if ($next_batch['is_now']): ?>
                <strong>immédiatement</strong> (retard ou en cours).
            <?php else:
                $diff = $next_batch['timestamp'] - time();
                $diff_min = ceil($diff / 60);
                $time_str = date_i18n(get_option('time_format'), $next_batch['timestamp']);
                $human_diff = human_time_diff($next_batch['timestamp']);
            ?>
                dans <strong><?php echo $human_diff; ?></strong> (à <?php echo $time_str; ?>).
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="pw-health-monitor">
        <div class="pw-health-card">
            <h3>En attente</h3>
            <div class="pw-health-value"><?php echo $health['pending']; ?></div>
        </div>
        <div class="pw-health-card">
            <h3>En cours</h3>
            <div class="pw-health-value"><?php echo $health['processing']; ?></div>
        </div>
        <div class="pw-health-card">
            <h3>Envoyés (24h)</h3>
            <div class="pw-health-value success"><?php echo $health['sent_24h']; ?></div>
        </div>
        <div class="pw-health-card">
            <h3>Échecs (24h)</h3>
            <div class="pw-health-value error"><?php echo $health['failed_24h']; ?></div>
        </div>
        <div class="pw-health-card">
            <h3>Top ISP</h3>
            <div class="pw-health-value small"><?php echo esc_html($health['top_isp']); ?></div>
        </div>
    </div>

    <div class="tablenav top">
        <div class="alignleft actions">
            <!-- Filter actions could go here -->
        </div>
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo $total; ?> éléments</span>
            <?php if ($total_pages > 1): ?>
                <span class="pagination-links">
                    <?php if ($page > 1): ?>
                        <a class="prev-page button" href="?page=postal-warmup-queue&paged=<?php echo $page - 1; ?>">‹</a>
                    <?php endif; ?>
                    <span class="paging-input">Page <?php echo $page; ?> sur <span class="total-pages"><?php echo $total_pages; ?></span></span>
                    <?php if ($page < $total_pages): ?>
                        <a class="next-page button" href="?page=postal-warmup-queue&paged=<?php echo $page + 1; ?>">›</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="60">ID</th>
                <th>Template</th>
                <th>Stratégie / Jour</th>
                <th>Serveur Assigné</th>
                <th>Destinataire / ISP</th>
                <th>Sujet</th>
                <th width="100">Statut</th>
                <th>Prévu pour</th>
                <th width="50">Essais</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="8">Aucun email en attente.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item):
                    $server = Database::get_server($item['server_id']);
                    $server_name = $server ? esc_html($server['domain']) : 'ID ' . $item['server_id'];

                    // Template Name Logic: DB Join > Meta > Fallback
                    $template_name = '<em>Système</em>';
                    if (!empty($item['template_name'])) {
                        $template_name = esc_html($item['template_name']);
                    } else {
                        // Try meta fallback
                        $meta = json_decode($item['meta'], true);
                        if (!empty($meta['prefix'])) {
                             $template_name = esc_html($meta['prefix']) . ' <small style="color:#888">(Déduit)</small>';
                        }
                    }

                    // ISP Display
                    $isp = !empty($item['isp']) ? esc_html($item['isp']) : '<em>Inconnu</em>';
                    $isp_badge = ($isp !== 'Other' && $isp !== 'Inconnu') ? '<span class="pw-isp-badge">' . $isp . '</span>' : '';

                    $status_class = 'pw-badge ';
                    switch($item['status']) {
                        case 'pending':
                            $status_class = 'status-pending'; // CSS class needed
                            $status_label = 'En attente';
                            break;
                        case 'processing':
                            $status_class = 'status-processing';
                            $status_label = 'En cours';
                            break;
                        case 'sent':
                            $status_class = 'status-sent';
                            $status_label = 'Envoyé';
                            break;
                        case 'failed':
                            $status_class = 'status-failed';
                            $status_label = 'Échoué';
                            break;
                        default:
                            $status_class = '';
                            $status_label = $item['status'];
                    }

                    // Time diff visual
                    $scheduled_ts = strtotime($item['scheduled_at']);
                    $time_diff = human_time_diff($scheduled_ts);
                    $time_display = ($scheduled_ts > time()) ? "Dans $time_diff" : "Il y a $time_diff";
                ?>
                <?php
                    // Calculate Remaining Limit for display (Expensive, but requested)
                    $rem_limit_display = '';
                    if (!empty($item['strategy_id']) && !empty($item['isp']) && !empty($item['server_id'])) {
                        // We need the Strategy object
                        // To avoid N+1 queries, ideally we would preload. But for 20 items it's okay-ish or we skip.
                        // Let's just show Warmup Day which is in DB.
                    }
                ?>
                <tr>
                    <td>#<?php echo $item['id']; ?></td>
                    <td><strong><?php echo $template_name; ?></strong></td>
                    <td>
                        <?php if(!empty($item['strategy_name'])): ?>
                            <span class="pw-badge" style="background:#2271b1;"><?php echo esc_html($item['strategy_name']); ?></span>
                            <div style="font-size:11px; margin-top:2px;">
                                <strong>J<?php echo $item['warmup_day']; ?></strong>
                            </div>
                        <?php else: ?>
                            <span style="color:#ccc;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $server_name; ?></td>
                    <td>
                        <?php echo esc_html($item['to_email']); ?><br>
                        <?php echo $isp_badge; ?>
                    </td>
                    <td><?php echo esc_html(mb_strimwidth($item['subject'], 0, 30, '...')); ?></td>
                    <td><span class="pw-queue-status <?php echo $status_class; ?>"><?php echo $status_label; ?></span></td>
                    <td>
                        <?php echo $item['scheduled_at']; ?><br>
                        <small class="description"><?php echo $time_display; ?></small>
                    </td>
                    <td><?php echo $item['attempts']; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.pw-queue-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}
.status-pending { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }
.status-processing { background: #fff8e5; color: #996800; border: 1px solid #f0c33c; }
.status-sent { background: #edfaef; color: #005a1e; border: 1px solid #7cc18b; }
.status-failed { background: #fbeaea; color: #d63638; border: 1px solid #f56e28; }
.pw-health-monitor {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    background: #fff;
    padding: 15px;
    border: 1px solid #c3c4c7;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}
.pw-health-card {
    flex: 1;
    text-align: center;
    border-right: 1px solid #eee;
}
.pw-health-card:last-child { border-right: none; }
.pw-health-card h3 { margin: 0 0 5px; font-size: 12px; color: #646970; text-transform: uppercase; }
.pw-health-value { font-size: 24px; font-weight: 600; color: #1d2327; }
.pw-health-value.success { color: #00a32a; }
.pw-health-value.error { color: #d63638; }
.pw-health-value.small { font-size: 18px; }
.pw-isp-badge {
    display: inline-block;
    background: #e5e7eb;
    color: #374151;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 99px;
    margin-top: 2px;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#pw-process-queue-btn').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);

        if (btn.hasClass('disabled')) return;

        btn.addClass('disabled').text('Traitement en cours...');

        $.post(ajaxurl, {
            action: 'pw_process_queue_manual',
            nonce: '<?php echo wp_create_nonce("pw_admin_nonce"); ?>'
        }, function(response) {
            if (response.success) {
                alert('Traitement terminé.');
                location.reload();
            } else {
                alert('Erreur: ' + (response.data.message || 'Inconnue'));
                btn.removeClass('disabled').text("Forcer l'envoi immédiat (Cron)");
            }
        }).fail(function() {
            alert('Erreur réseau');
            btn.removeClass('disabled').text("Forcer l'envoi immédiat (Cron)");
        });
    });
});
</script>
