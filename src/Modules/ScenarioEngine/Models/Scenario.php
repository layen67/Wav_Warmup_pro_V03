<?php

namespace PostalWarmup\Modules\ScenarioEngine\Models;

class Scenario {
    public $id;
    public $name;
    public $description;
    public $status;
    public $steps = [];

    public function __construct( $data = [] ) {
        if ( ! empty( $data ) ) {
            $this->id = isset($data->id) ? (int)$data->id : null;
            $this->name = $data->name ?? '';
            $this->description = $data->description ?? '';
            $this->status = $data->status ?? 'active';
        }
    }

    public function add_step( ScenarioStep $step ) {
        $this->steps[$step->step_number] = $step;
        ksort($this->steps);
    }
}
