jQuery(document).ready(function($) {
    let currentScenarioId = null;

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
