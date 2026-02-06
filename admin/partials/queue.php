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

$total = $wpdb->get_var("SELECT COUNT(*) FROM $table");

// Fetch with Template Name
$items = $wpdb->get_results($wpdb->prepare(
    "SELECT q.*, t.name as template_name
     FROM $table q
     LEFT JOIN $table_tpl t ON q.template_id = t.id
     ORDER BY q.scheduled_at DESC
     LIMIT %d OFFSET %d",
    $per_page, $offset
), ARRAY_A);

$total_pages = ceil($total / $per_page);

?>
<div class="wrap">
    <h1 class="wp-heading-inline">File d'attente Warmup</h1>
    <a href="#" id="pw-process-queue-btn" class="page-title-action">Forcer l'envoi immédiat (Cron)</a>
    <hr class="wp-header-end">

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
                <th>Serveur Assigné</th>
                <th>Destinataire</th>
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
                    $template_name = !empty($item['template_name']) ? esc_html($item['template_name']) : '<em>Système</em>';

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
                <tr>
                    <td>#<?php echo $item['id']; ?></td>
                    <td><strong><?php echo $template_name; ?></strong></td>
                    <td><?php echo $server_name; ?></td>
                    <td><?php echo esc_html($item['to_email']); ?></td>
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
