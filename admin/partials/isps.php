<?php
/**
 * Vue : Gestion des FAI (ISP)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use PostalWarmup\Admin\ISPManager;
use PostalWarmup\Models\Strategy;
use PostalWarmup\Models\Stats;
use PostalWarmup\Models\Database;

?>
<div class="wrap">
    <h1 class="wp-heading-inline">Gestion des Profils ISP</h1>
    <button class="page-title-action" id="pw-add-isp-btn">Ajouter un Profil</button>
    <hr class="wp-header-end">

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Nom du Profil</th>
                <th>Domaines Associés</th>
                <th>Stratégie</th>
                <th>Statut</th>
                <th>Détails par Serveur</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="pw-isp-list">
            <?php
            $isps = ISPManager::get_all();
            $servers = Database::get_servers(true); // Active servers

            if ( empty( $isps ) ): ?>
                <tr><td colspan="6">Aucun profil ISP configuré.</td></tr>
            <?php else: foreach ( $isps as $isp ):
                $domains_list = implode(', ', $isp['domains']);
                if (strlen($domains_list) > 50) $domains_list = substr($domains_list, 0, 50) . '...';
            ?>
                <tr data-id="<?php echo esc_attr($isp['id']); ?>" data-json="<?php echo esc_attr(json_encode($isp)); ?>">
                    <td><strong><?php echo esc_html($isp['isp_label']); ?></strong><br><small style="color:#888"><?php echo esc_html($isp['isp_key']); ?></small></td>
                    <td><?php echo esc_html($domains_list); ?></td>
                    <td>
                        <?php if(!empty($isp['strategy_name'])): ?>
                            <span class="pw-badge" style="background:#2271b1;"><?php echo esc_html($isp['strategy_name']); ?></span>
                        <?php else: ?>
                            <?php echo esc_html($isp['strategy']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $isp['active'] ? '<span class="pw-badge success">Actif</span>' : '<span class="pw-badge error">Inactif</span>'; ?></td>
                    <td>
                        <?php
                        // Show breakdown per server
                        if ($servers) {
                            echo '<div class="pw-server-stats-grid">';
                            foreach ($servers as $srv) {
                                $stats = Stats::get_server_isp_stats($srv['id'], $isp['isp_key']);
                                // Calculate Limit if Strategy is set
                                $limit_display = '-';
                                if (!empty($isp['strategy_id'])) {
                                    $strategy = Strategy::get($isp['strategy_id']);
                                    if ($strategy) {
                                        $limit = \PostalWarmup\Services\StrategyEngine::calculate_daily_limit($strategy, $stats->warmup_day, $isp['isp_key']);
                                        $limit_display = $limit;
                                    }
                                }

                                echo '<div class="pw-server-stat-item">';
                                echo '<strong>' . esc_html($srv['domain']) . ':</strong> ';
                                echo 'J' . $stats->warmup_day . ' ';
                                echo '<span class="pw-usage">(' . $stats->sent_today . '/' . $limit_display . ')</span>';
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </td>
                    <td>
                        <button class="button pw-edit-isp">Éditer</button>
                        <button class="button pw-delete-isp" style="color: #b32d2e; border-color: #b32d2e;">Supprimer</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<style>
.pw-server-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 5px; font-size: 11px; }
.pw-server-stat-item { background: #f0f0f1; padding: 3px 6px; border-radius: 3px; }
.pw-usage { color: #666; }
</style>

<!-- Modal -->
<div id="pw-isp-modal" class="pw-modal" style="display:none;">
    <div class="pw-modal-content pw-modal-lg">
        <div class="pw-modal-header">
            <h2 id="pw-isp-modal-title">Configurer un Profil ISP</h2>
            <button class="pw-modal-close">&times;</button>
        </div>
        <div class="pw-modal-body">
            <form id="pw-isp-form">
                <input type="hidden" name="id" id="pw-isp-id">

                <div class="pw-form-group">
                    <label>Nom du Profil (Label)</label>
                    <input type="text" name="isp_label" id="pw-isp-label" class="widefat" required placeholder="Ex: Gmail Corporate">
                </div>

                <div class="pw-form-group">
                    <label>Domaines associés (séparés par virgules)</label>
                    <textarea name="domains" id="pw-isp-domains" class="widefat" rows="3" placeholder="gmail.com, googlemail.com"></textarea>
                    <p class="description">Tous les emails se terminant par ces domaines utiliseront ce profil.</p>
                </div>

                <!-- Quota Fields Removed (Replaced by Strategy) -->
                <input type="hidden" name="max_daily" id="pw-isp-daily" value="0">
                <input type="hidden" name="max_hourly" id="pw-isp-hourly" value="0">

                <div class="pw-form-group">
                    <label>Stratégie de Warmup</label>
                    <select name="strategy_id" id="pw-isp-strategy-id" class="widefat">
                        <option value="">-- Aucune --</option>
                        <?php foreach ( \PostalWarmup\Models\Strategy::get_all() as $s ): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo esc_html($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">La stratégie détermine les volumes et règles de sécurité par serveur.</p>
                </div>

                <fieldset style="border:1px solid #ddd; padding:10px; margin-bottom:15px;">
                    <legend style="font-weight:600;">Surcharge de la Stratégie (Optionnel)</legend>
                    <div style="display:flex; gap:10px;">
                        <div class="pw-form-group" style="flex:1;">
                            <label>Start Volume</label>
                            <input type="number" name="override_start_volume" id="pw-isp-override-start" class="widefat" placeholder="Défaut">
                        </div>
                        <div class="pw-form-group" style="flex:1;">
                            <label>Max Volume</label>
                            <input type="number" name="override_max_volume" id="pw-isp-override-max" class="widefat" placeholder="Défaut">
                        </div>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <div class="pw-form-group" style="flex:1;">
                            <label>Growth Type</label>
                            <select name="override_growth_type" id="pw-isp-override-type" class="widefat">
                                <option value="">Défaut</option>
                                <option value="linear">Linear</option>
                                <option value="exponential">Exponential</option>
                                <option value="mixed">Mixed</option>
                            </select>
                        </div>
                        <div class="pw-form-group" style="flex:1;">
                            <label>Growth Value</label>
                            <input type="number" step="0.01" name="override_growth_value" id="pw-isp-override-value" class="widefat" placeholder="Défaut">
                        </div>
                    </div>
                </fieldset>

                <div class="pw-form-group">
                    <label>
                        <input type="checkbox" name="active" id="pw-isp-active" value="1" checked>
                        Activer ce profil
                    </label>
                </div>

            </form>
        </div>
        <div class="pw-modal-footer">
            <button class="button button-secondary pw-modal-close">Annuler</button>
            <button class="button button-primary" id="pw-save-isp">Enregistrer</button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Open Modal Add
    $('#pw-add-isp-btn').on('click', function() {
        $('#pw-isp-form')[0].reset();
        $('#pw-isp-id').val('');
        $('#pw-isp-modal-title').text('Ajouter un Profil ISP');
        $('#pw-isp-modal').show();
    });

    // Open Modal Edit
    $('.pw-edit-isp').on('click', function() {
        var tr = $(this).closest('tr');
        var data = tr.data('json');

        $('#pw-isp-id').val(data.id);
        $('#pw-isp-label').val(data.isp_label);
        $('#pw-isp-domains').val(data.domains ? data.domains.join(', ') : '');
        // Quota fields hidden but values kept just in case
        $('#pw-isp-daily').val(data.max_daily);
        $('#pw-isp-hourly').val(data.max_hourly);
        $('#pw-isp-strategy-id').val(data.strategy_id);

        $('#pw-isp-override-start').val(data.override_start_volume);
        $('#pw-isp-override-max').val(data.override_max_volume);
        $('#pw-isp-override-type').val(data.override_growth_type);
        $('#pw-isp-override-value').val(data.override_growth_value);

        $('#pw-isp-active').prop('checked', data.active == 1);

        $('#pw-isp-modal-title').text('Modifier Profil : ' + data.isp_label);
        $('#pw-isp-modal').show();
    });

    // Close Modal
    $('.pw-modal-close').on('click', function() {
        $('#pw-isp-modal').hide();
    });

    // Save
    $('#pw-save-isp').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true);

        var formData = $('#pw-isp-form').serialize();
        formData += '&action=pw_save_isp&nonce=' + pwAdmin.nonce;

        $.post(pwAdmin.ajaxurl, formData, function(res) {
            btn.prop('disabled', false);
            if(res.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (res.data.message || 'Inconnue'));
            }
        });
    });

    // Delete
    $('.pw-delete-isp').on('click', function() {
        if(!confirm('Supprimer ce profil ISP ?')) return;
        var id = $(this).closest('tr').data('id');
        $.post(pwAdmin.ajaxurl, {
            action: 'pw_delete_isp',
            nonce: pwAdmin.nonce,
            id: id
        }, function(res) {
            if(res.success) location.reload();
            else alert('Erreur');
        });
    });
});
</script>

<style>
.pw-modal {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 99999;
    display: flex; justify-content: center; align-items: center;
}
.pw-modal-content {
    background: #fff; padding: 20px; width: 600px; max-width: 90%;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2); border-radius: 5px;
}
.pw-modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
.pw-modal-footer { border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px; text-align: right; }
.pw-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; }
.pw-form-group { margin-bottom: 15px; }
.pw-form-group label { display: block; font-weight: 600; margin-bottom: 5px; }
.pw-badge { padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #fff; }
.pw-badge.success { background: #46b450; }
.pw-badge.error { background: #dc3232; }
</style>
