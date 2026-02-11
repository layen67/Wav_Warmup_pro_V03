<?php

namespace PostalWarmup\Modules\ScenarioEngine\Models;

class ScenarioStep {
    public $id;
    public $scenario_id;
    public $step_number;
    public $template_id;
    public $step_type;
    public $delay_minutes;
    public $options = []; // Array of ScenarioStepOption objects or arrays

    public function __construct( $data = [] ) {
        if ( ! empty( $data ) ) {
            $this->id = isset($data->id) ? (int)$data->id : null;
            $this->scenario_id = isset($data->scenario_id) ? (int)$data->scenario_id : null;
            $this->step_number = isset($data->step_number) ? (int)$data->step_number : 0;
            $this->template_id = isset($data->template_id) ? (int)$data->template_id : null;
            $this->step_type = $data->step_type ?? 'SEND';
            $this->delay_minutes = isset($data->delay_minutes) ? (int)$data->delay_minutes : 0;
        }
    }
}
