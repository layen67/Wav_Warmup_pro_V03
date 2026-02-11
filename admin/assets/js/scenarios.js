jQuery(document).ready(function($) {
    let currentScenarioId = null;

    // Create New Scenario
    $('#pw-add-scenario-btn').on('click', function(e) {
        e.preventDefault();

        // Simple prompt for now (could be a nice modal later)
        const name = prompt("Nom du nouveau scénario :");
        if (!name) return;

        const description = prompt("Description (optionnel) :") || '';

        $.post(pwAdmin.ajaxurl, {
            action: 'pw_save_scenario',
            nonce: pwAdmin.nonce,
            name: name,
            description: description,
            status: 'active'
        }, function(res) {
            if(res.success) {
                // Open editor directly
                currentScenarioId = res.data.id;
                $('#pw-scenario-modal').show();
                loadSteps(currentScenarioId);
                // Also could reload page to see it in list, but let's edit first
                // Actually reloading list is complex without reload page.
                // We will reload page when modal closes if changes were made?
                // For now, let's just reload to update the grid, OR manually add card.
                // Simple: reload page to ensure consistency.
                location.reload();
            } else {
                alert('Erreur: ' + (res.data ? res.data.message : 'Inconnue'));
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX Error:', status, error, xhr.responseText);
            alert('Erreur serveur lors de la création du scénario.');
        });
    });

    // Load Templates for Dropdown
    function loadTemplates() {
        $.post(pwAdmin.ajaxurl, {
            action: 'pw_get_all_templates',
            nonce: pwAdmin.nonce
        }, function(res) {
            if(res.success) {
                const $select = $('#pw-step-template');
                $select.empty().append('<option value="">-- Sélectionner --</option>');
                res.data.templates.forEach(t => {
                    if(t.name !== 'null') {
                        $select.append(`<option value="${t.id}">${t.name}</option>`);
                    }
                });
            }
        });
    }

    $('.pw-edit-scenario').on('click', function() {
        currentScenarioId = $(this).closest('.pw-scenario-card').data('id');
        $('#pw-scenario-modal').show();
        loadSteps(currentScenarioId);
        loadTemplates(); // Load templates when opening modal
    });

    function loadSteps(scenarioId) {
        // Mock load via AJAX usually, here we assume data loaded or separate call
        // For prototype, we'll just clear list
        $('#pw-steps-list').html('<li style="color:#888; padding:10px;">Chargement...</li>');

        $.post(pwAdmin.ajaxurl, {
            action: 'pw_get_scenario_details', // Would need to add to AjaxHandler
            nonce: pwAdmin.nonce,
            id: scenarioId
        }, function(res) {
            if(res.success) {
                renderStepsList(res.data.steps);
            }
        });
    }

    function renderStepsList(steps) {
        const $list = $('#pw-steps-list');
        $list.empty();
        steps.forEach(step => {
            $list.append(`<li data-id="${step.id}" data-json='${JSON.stringify(step)}'>Step ${step.step_number}: ${step.step_type}</li>`);
        });
    }

    $(document).on('click', '#pw-steps-list li', function() {
        const data = $(this).data('json');
        $('#pw-step-id').val(data.id);
        $('#pw-step-type').val(data.step_type);
        // ... populate form
        $('#pw-step-form-container').show();
        $('#pw-step-empty-state').hide();
    });

    // Add Step
    $('#pw-add-step-btn').on('click', function(e) {
        e.preventDefault();
        if (!currentScenarioId) return;

        const nextNum = $('#pw-steps-list li').length + 1;

        $.post(pwAdmin.ajaxurl, {
            action: 'pw_save_scenario_step',
            nonce: pwAdmin.nonce,
            scenario_id: currentScenarioId,
            step_number: nextNum,
            step_type: 'SEND', // Default
            delay_minutes: 0
        }, function(res) {
            if(res.success) {
                loadSteps(currentScenarioId);
            } else {
                alert('Erreur: ' + (res.data ? res.data.message : 'Inconnue'));
            }
        }).fail(function(xhr, status, error) {
            alert('Erreur serveur lors de la création de l\'étape.');
        });
    });

    // Add Option Row
    $('#pw-add-option-btn').on('click', function() {
        addOptionRow();
    });

    function addOptionRow(data = {}) {
        const keyword = data.reply_keyword || '';
        const action = data.action || 'CONTINUE';
        const nextStep = data.next_step_id || '';

        // Simple manual validation for now, better to assume next steps exist or use IDs
        // For UI simplicity, let's just use numeric input for "Next Step ID" or similar.
        // Ideally, this should be a dropdown of other steps in the scenario.

        const html = `
            <div class="pw-option-row">
                <input type="text" name="options[keyword][]" value="${keyword}" placeholder="Mot-clé (ex: OK)" style="width:150px;">
                <select name="options[action][]">
                    <option value="CONTINUE" ${action === 'CONTINUE' ? 'selected' : ''}>Continuer</option>
                    <option value="STOP_FLOW" ${action === 'STOP_FLOW' ? 'selected' : ''}>Stop</option>
                </select>
                <input type="number" name="options[next_step_id][]" value="${nextStep}" placeholder="ID Étape Suivante" style="width:100px;">
                <button type="button" class="button pw-remove-option" style="color:red;">&times;</button>
            </div>
        `;
        $('#pw-step-options-list').append(html);
    }

    $(document).on('click', '.pw-remove-option', function() {
        $(this).closest('.pw-option-row').remove();
    });

    // Populate form including options
    $(document).on('click', '#pw-steps-list li', function() {
        const data = $(this).data('json');
        $('#pw-step-id').val(data.id);
        $('#pw-step-scenario-id').val(currentScenarioId); // Ensure scenario ID is set
        $('#pw-step-num-display').text(data.step_number);
        $('#pw-step-type').val(data.step_type);
        $('#pw-step-template').val(data.template_id);
        $('#pw-step-delay').val(data.delay_minutes);

        // Clear and Load Options
        $('#pw-step-options-list').empty();
        if (data.options && Array.isArray(data.options)) {
            data.options.forEach(opt => addOptionRow(opt));
        }

        $('#pw-step-form-container').show();
        $('#pw-step-empty-state').hide();
    });

    // Save Step Details (Modified to include options)
    $('#pw-save-step').on('click', function(e) {
        e.preventDefault();
        const data = $('#pw-step-form').serialize();

        $.post(pwAdmin.ajaxurl, data + '&action=pw_save_scenario_step&nonce=' + pwAdmin.nonce, function(res) {
            if(res.success) {
                alert('Étape enregistrée !');
                loadSteps(currentScenarioId);
            } else {
                alert('Erreur: ' + (res.data ? res.data.message : 'Inconnue'));
            }
        });
    });

    // Close Modal
    $('.pw-modal-close').on('click', function() {
        $('#pw-scenario-modal').hide();
    });
});
