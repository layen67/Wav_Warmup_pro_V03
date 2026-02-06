<?php
/**
 * Vue de la file d'attente
 */

if (!defined('ABSPATH')) exit;

use PostalWarmup\Models\Database;

// Pagination
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

global $wpdb;
$table = $wpdb->prefix . 'postal_queue';
$total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
$items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY scheduled_at DESC LIMIT %d OFFSET %d", $per_page, $offset), ARRAY_A);
$total_pages = ceil($total / $per_page);

?>
<div class="wrap">
    <h1>File d'attente Warmup</h1>

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
                <th>ID</th>
                <th>Serveur</th>
                <th>Destinataire</th>
                <th>Sujet</th>
                <th>Statut</th>
                <th>Prévu pour</th>
                <th>Tentatives</th>
                <th>Créé le</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="8">Aucun email en attente.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item):
                    $server = Database::get_server($item['server_id']);
                    $server_name = $server ? esc_html($server['domain']) : 'ID ' . $item['server_id'];
                    $status_class = 'pw-badge ';
                    switch($item['status']) {
                        case 'pending': $status_class .= 'warning'; break;
                        case 'sent': $status_class .= 'success'; break;
                        case 'failed': $status_class .= 'error'; break;
                        default: $status_class .= 'neutral';
                    }
                ?>
                <tr>
                    <td>#<?php echo $item['id']; ?></td>
                    <td><?php echo $server_name; ?></td>
                    <td><?php echo esc_html($item['to_email']); ?></td>
                    <td><?php echo esc_html($item['subject']); ?></td>
                    <td><span class="<?php echo $status_class; ?>"><?php echo ucfirst($item['status']); ?></span></td>
                    <td><?php echo $item['scheduled_at']; ?></td>
                    <td><?php echo $item['attempts']; ?></td>
                    <td><?php echo $item['created_at']; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
