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

    $('.pw-edit-scenario').on('click', function() {
        currentScenarioId = $(this).closest('.pw-scenario-card').data('id');
        $('#pw-scenario-modal').show();
        loadSteps(currentScenarioId);
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

    // Close Modal
    $('.pw-modal-close').on('click', function() {
        $('#pw-scenario-modal').hide();
    });
});
