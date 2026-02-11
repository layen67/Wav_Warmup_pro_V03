<?php
if ( ! defined( 'ABSPATH' ) ) exit;
use PostalWarmup\Admin\ScenarioManager;
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Scénarios de Warmup (Reply-Driven)</h1>
    <button class="page-title-action" id="pw-add-scenario-btn">Créer un Scénario</button>
    <hr class="wp-header-end">

    <div class="pw-scenarios-grid">
        <?php
        $scenarios = ScenarioManager::get_all();
        if ( empty( $scenarios ) ): ?>
            <p>Aucun scénario configuré.</p>
        <?php else: foreach ( $scenarios as $s ): ?>
            <div class="pw-scenario-card" data-id="<?php echo esc_attr($s['id']); ?>">
                <h3><?php echo esc_html($s['name']); ?></h3>
                <p><?php echo esc_html($s['description']); ?></p>
                <span class="pw-badge <?php echo $s['status'] === 'active' ? 'success' : 'draft'; ?>">
                    <?php echo ucfirst($s['status']); ?>
                </span>
                <div class="actions">
                    <button class="button pw-edit-scenario">Configurer</button>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- Modal Édition Scénario -->
<div id="pw-scenario-modal" class="pw-modal" style="display:none;">
    <div class="pw-modal-content pw-modal-lg" style="width: 90%; max-width: 1000px;">
        <div class="pw-modal-header">
            <h2 id="pw-scenario-title">Éditer le Scénario</h2>
            <button class="pw-modal-close">&times;</button>
        </div>
        <div class="pw-modal-body" style="display:flex; height: 600px;">
            <!-- Liste des steps (Sidebar) -->
            <div class="pw-steps-sidebar" style="width: 250px; background: #f9f9f9; border-right: 1px solid #ddd; overflow-y: auto;">
                <ul id="pw-steps-list"></ul>
                <button class="button button-secondary" id="pw-add-step-btn" style="margin: 10px; width: 90%;">+ Ajouter une étape</button>
            </div>

            <!-- Détail Step (Main) -->
            <div class="pw-step-detail" style="flex:1; padding: 20px; overflow-y: auto;">
                <div id="pw-step-form-container" style="display:none;">
                    <h3>Étape #<span id="pw-step-num-display"></span></h3>
                    <form id="pw-step-form">
                        <input type="hidden" name="id" id="pw-step-id">
                        <input type="hidden" name="scenario_id" id="pw-step-scenario-id">

                        <div class="pw-form-group">
                            <label>Type d'étape</label>
                            <select name="step_type" id="pw-step-type" class="widefat">
                                <option value="SEND">Envoyer un email (SEND)</option>
                                <option value="WAIT">Attendre (WAIT)</option>
                            </select>
                        </div>

                        <div class="pw-form-group type-send">
                            <label>Template à envoyer</label>
                            <select name="template_id" id="pw-step-template" class="widefat">
                                <!-- Populated via JS -->
                            </select>
                        </div>

                        <div class="pw-form-group">
                            <label>Délai avant exécution (minutes)</label>
                            <input type="number" name="delay_minutes" id="pw-step-delay" class="widefat" value="0">
                        </div>

                        <hr>
                        <h4>Réponses attendues & Actions (Reply-Driven)</h4>
                        <div id="pw-step-options-list"></div>
                        <button type="button" class="button" id="pw-add-option-btn">+ Ajouter une condition de réponse</button>

                        <div style="margin-top: 20px; text-align: right;">
                            <button type="button" class="button button-primary" id="pw-save-step">Enregistrer l'étape</button>
                        </div>
                    </form>
                </div>
                <div id="pw-step-empty-state" style="text-align: center; color: #888; margin-top: 100px;">
                    Sélectionnez une étape à gauche ou créez-en une nouvelle.
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.pw-scenarios-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
.pw-scenario-card { background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
.pw-steps-sidebar li { padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; }
.pw-steps-sidebar li:hover, .pw-steps-sidebar li.active { background: #e6f7ff; }
.pw-option-row { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; background: #f0f0f1; padding: 10px; border-radius: 4px; }
</style>
