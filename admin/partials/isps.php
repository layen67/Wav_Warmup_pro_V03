<?php
/**
 * Vue : Gestion des FAI (ISP)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use PostalWarmup\Admin\ISPManager;

// Handle Form Submission (Simple Post for now or AJAX? Let's use AJAX for consistency with the plugin style, but a simple form is faster to implement. The plugin uses AJAX heavily. I'll stick to AJAX logic or simple PHP form if easier. Given I need to add AJAX handlers anyway, I'll use a mix or just simple PHP for stability if I don't want to touch JS too much. But the user asked for "Interface admin ... Add / Modify". I'll use a simple PHP POST handler here to avoid complex JS if possible, but the plugin seems to rely on JS. I'll use a simple table + modal pattern.)

?>
<div class="wrap">
    <h1 class="wp-heading-inline">Gestion des FAI (ISP)</h1>
    <button class="page-title-action" id="pw-add-isp-btn">Ajouter un ISP</button>
    <hr class="wp-header-end">

    <div id="pw-isp-response"></div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Regex</th>
                <th>Quota Jour</th>
                <th>Quota Heure</th>
                <th>Score Warmup</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="pw-isp-list">
            <!-- Populated via JS or PHP -->
            <?php
            $isps = ISPManager::get_all();
            if ( empty( $isps ) ): ?>
                <tr><td colspan="6">Aucun ISP personnalisé.</td></tr>
            <?php else: foreach ( $isps as $isp ): ?>
                <tr data-id="<?php echo esc_attr($isp['id']); ?>" data-json="<?php echo esc_attr(json_encode($isp)); ?>">
                    <td><strong><?php echo esc_html($isp['name']); ?></strong></td>
                    <td><code><?php echo esc_html($isp['regex']); ?></code></td>
                    <td><?php echo $isp['daily_limit'] > 0 ? $isp['daily_limit'] : '∞'; ?></td>
                    <td><?php echo $isp['hourly_limit'] > 0 ? $isp['hourly_limit'] : '∞'; ?></td>
                    <td><?php echo $isp['warmup_score']; ?></td>
                    <td>
                        <button class="button pw-edit-isp">Éditer</button>
                        <button class="button pw-delete-isp" style="color: #b32d2e; border-color: #b32d2e;">Supprimer</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div id="pw-isp-modal" class="pw-modal" style="display:none;">
    <div class="pw-modal-content">
        <div class="pw-modal-header">
            <h2 id="pw-isp-modal-title">Ajouter un ISP</h2>
            <button class="pw-modal-close">&times;</button>
        </div>
        <div class="pw-modal-body">
            <form id="pw-isp-form">
                <input type="hidden" name="id" id="pw-isp-id">
                <p>
                    <label>Nom de l'ISP</label>
                    <input type="text" name="name" id="pw-isp-name" class="widefat" required placeholder="Ex: Orange">
                </p>
                <p>
                    <label>Regex de détection</label>
                    <input type="text" name="regex" id="pw-isp-regex" class="widefat" required placeholder="Ex: @(orange|wanadoo)\.">
                    <span class="description">Expression régulière sans délimiteurs (sera entourée par /.../i)</span>
                </p>
                <div style="display:flex; gap:10px;">
                    <p style="flex:1;">
                        <label>Quota Journalier</label>
                        <input type="number" name="daily_limit" id="pw-isp-daily" class="widefat" value="0">
                    </p>
                    <p style="flex:1;">
                        <label>Quota Horaire</label>
                        <input type="number" name="hourly_limit" id="pw-isp-hourly" class="widefat" value="0">
                    </p>
                </div>
                <p>
                    <label>Score Warmup (Malus/Bonus)</label>
                    <input type="number" name="warmup_score" id="pw-isp-score" class="widefat" value="10">
                    <span class="description">Score ajouté au Load Balancer (défaut 10).</span>
                </p>
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
        $('#pw-isp-modal-title').text('Ajouter un ISP');
        $('#pw-isp-modal').show();
    });

    // Open Modal Edit
    $('.pw-edit-isp').on('click', function() {
        var tr = $(this).closest('tr');
        var data = tr.data('json');

        $('#pw-isp-id').val(data.id);
        $('#pw-isp-name').val(data.name);
        $('#pw-isp-regex').val(data.regex);
        $('#pw-isp-daily').val(data.daily_limit);
        $('#pw-isp-hourly').val(data.hourly_limit);
        $('#pw-isp-score').val(data.warmup_score);

        $('#pw-isp-modal-title').text('Modifier l\'ISP');
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

        var data = {
            action: 'pw_save_isp',
            nonce: pwAdmin.nonce,
            id: $('#pw-isp-id').val(),
            name: $('#pw-isp-name').val(),
            regex: $('#pw-isp-regex').val(),
            daily_limit: $('#pw-isp-daily').val(),
            hourly_limit: $('#pw-isp-hourly').val(),
            warmup_score: $('#pw-isp-score').val()
        };

        $.post(pwAdmin.ajaxurl, data, function(res) {
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
        if(!confirm('Supprimer cet ISP ?')) return;
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
    background: #fff; padding: 20px; width: 500px; max-width: 90%;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2); border-radius: 5px;
}
.pw-modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
.pw-modal-footer { border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px; text-align: right; }
.pw-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; }
</style>
