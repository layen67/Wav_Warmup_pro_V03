<?php
/**
 * Tests pour la gestion des templates
 */

class Test_Template_Management extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        update_option('pw_feature_flags', ['db_templates' => true, 'modern_ui' => true]);
        
        // S'assurer que les tables existent
        require_once PW_INCLUDES_DIR . 'class-pw-activator.php';
        PW_Activator::activate();
    }

    public function test_save_template_to_db() {
        $name = 'test_template';
        $data = ['subject' => ['Test Subject'], 'text' => ['Test Text'], 'html' => ['<p>Test HTML</p>'], 'from_name' => ['Test From']];
        $result = PW_Template_Loader::save_template($name, $data);
        $this->assertTrue($result);
        
        global $wpdb;
        $table = $wpdb->prefix . 'postal_templates';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE name = %s", $name));
        $this->assertNotNull($row);
        $this->assertEquals($name, $row->name);
        
        // Vérifier que le fichier JSON existe aussi
        $this->assertFileExists(PW_TEMPLATES_DIR . $name . '.json');
    }

    public function test_template_versioning() {
        $name = 'version_test';
        $data1 = ['subject' => ['v1'], 'text' => ['v1'], 'html' => ['v1'], 'from_name' => ['v1']];
        $data2 = ['subject' => ['v2'], 'text' => ['v2'], 'html' => ['v2'], 'from_name' => ['v2']];
        
        PW_Template_Loader::save_template($name, $data1);
        PW_Template_Loader::save_template($name, $data2);
        
        global $wpdb;
        $table_tpl = $wpdb->prefix . 'postal_templates';
        $table_ver = $wpdb->prefix . 'postal_template_versions';
        
        $tpl = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table_tpl WHERE name = %s", $name));
        $versions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_ver WHERE template_id = %d", $tpl->id));
        
        // On s'attend à 2 versions (une par sauvegarde)
        $this->assertCount(2, $versions);
    }
}
