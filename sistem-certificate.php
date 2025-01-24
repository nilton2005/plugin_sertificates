<?php
/**
 * Plugin Name: Sistema de Certificados
 * Description: Sistema para generar y gestionar certificados automáticos
 * Version: 2.0
 * Author: Oracle S.A.C
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once(__DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php');
require_once('vendor/autoload.php');

use Google_Service_Drive;

class CertificateSystem {
    private static $instance = null;
    private $google_client;
    private $logger;
    private $config;

    const CRON_HOOK = 'certificate_generation_cron';
    const CRON_INTERVAL = '4hours';

    private function __construct() {
        set_time_limit(350);
        $this->init_config();
        $this->init_logger();
        $this->init_hooks();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_config() {
        $this->config = [
            'root_folder_id' => '1hl1XZm3lrUGkPfXkoYowH0mj4-Of9Zuc',
            'min_passing_score' => 15,
            'template_path' => [
                'slide1' => __DIR__ . '/assets/templates/Diapositiva1.png',
                'slide2' => __DIR__ . '/assets/templates/Diapositiva2.png',
                'syllabus' => __DIR__ . '/assets/templates/tem_first_auxi.png'
            ],
            'fonts' => [
                'nunito' => __DIR__ . '/assets/fonts/Nunito-Italic-VariableFont_wght.ttf',
                'arimo' => __DIR__ . '/assets/fonts/Arimo-Italic-VariableFont_wght.ttf',
                'dm_serif' => __DIR__ . '/assets/fonts/DMSerifText-Regular.ttf'
            ],
            'signature' => [
                'certificate' => __DIR__ . '/assets/digital-signature/public.crt',
                'key' => __DIR__ . '/assets/digital-signature/private.key',
                'password' => 'gutemberg192837465',
                'info' => [
                    'Name' => 'Oracle Perú S.A.C',
                    'Location' => 'Arequipa',
                    'Reason' => 'Certificado de aprobación',
                    'ContactInfo' => 'https://www.consultoriaoracleperusac.org.pe/'
                ]
            ]
        ];
    }

    private function init_logger() {
        $this->logger = new class {
            public function log($message, $level = 'info') {
                error_log("[Certificate System][$level] $message");
            }
        };
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action(self::CRON_HOOK, [$this, 'process_pending_certificates']);
    }

    public function activate() {
        $this->create_database_tables();
        $this->create_required_directories();
        $this->schedule_cron();
        //$this->process_pending_certificates();
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function add_cron_interval($schedules) {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 4 * HOUR_IN_SECONDS,
            'display' => 'Every 4 Hours'
        ];
        return $schedules;
    }

    private function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::CRON_INTERVAL, self::CRON_HOOK);
        }
    }

    private function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'certificados_generados';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dni VARCHAR(20) NULL,
            codigo_unico VARCHAR(100) NOT NULL,
            student_id BIGINT(20) NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            curso VARCHAR(100) NOT NULL,
            course_id BIGINT(20) NOT NULL,
            nota FLOAT,
            fecha_emision DATE,
            emisor VARCHAR(100),
            enlace_drive TEXT,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            error_message TEXT,
            attempts INT DEFAULT 0,
            last_attempt DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY codigo_unico (codigo_unico),
            INDEX idx_status (status),
            INDEX idx_student_course (student_id, course_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function create_required_directories() {
        $dirs = [
            __DIR__ . '/certificates/png',
            __DIR__ . '/certificates/pdf',
            __DIR__ . '/logs'
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function process_pending_certificates() {
        $this->logger->log('Starting certificate processing job');
        
        try {
            $pending_certificates = $this->get_pending_certificates();
            
            foreach ($pending_certificates as $certificate) {
                $this->process_single_certificate($certificate);
            }
            
        } catch (Exception $e) {
            $this->logger->log("Error processing certificates: " . $e->getMessage(), 'error');
        }
    }

    public function get_pending_certificates() {
        global $wpdb;
        $sql = "

        SELECT 
            u.ID AS student_id,
            u.display_name AS nombre_completo,
            um.meta_value AS dni,
            p.post_title AS course_name,
            q.attempt_started_at AS ultima_fecha,
            q.earned_marks AS nota,
            q.course_id
        FROM 
            {$wpdb->prefix}tutor_quiz_attempts q
        JOIN 
            {$wpdb->prefix}users u ON q.user_id = u.ID
        LEFT JOIN 
            {$wpdb->prefix}usermeta um ON u.ID = um.user_id AND um.meta_key = 'dni'
        JOIN 
            {$wpdb->prefix}posts p ON q.course_id = p.ID
        WHERE 
            q.earned_marks >= 15
            AND q.attempt_started_at = (
                SELECT MAX( inner_q.attempt_started_at )
                FROM {$wpdb->prefix}tutor_quiz_attempts inner_q
                WHERE inner_q.user_id = q.user_id
                    AND inner_q.course_id = q.course_id
                    AND inner_q.earned_marks >= 15
                    AND inner_q.earned_marks = (
                        SELECT MAX(inner_inner_q.earned_marks)
                        FROM {$wpdb->prefix}tutor_quiz_attempts inner_inner_q
                        WHERE inner_inner_q.user_id = q.user_id
                            AND inner_inner_q.course_id = q.course_id
                            AND inner_inner_q.earned_marks >= 15
                    )

            )
            AND NOT EXISTS (
                SELECT 1 
                FROM {$wpdb->prefix}certificados_generados c
                WHERE c.student_id = u.ID AND c.course_id = q.course_id
            )
        LIMIT 50;

        ";
        return $wpdb->get_results($wpdb->prepare($sql, $this->config['min_passing_score']));
    }

    private function process_single_certificate($data) {
        try {
            // Generate unique code
            $certificate_code = wp_generate_uuid4();
            
            // Create certificate images
            $this->generate_certificate_images($data, $certificate_code);
            
            // Convert to PDF
            $pdf_path = $this->generate_signed_pdf();
            
            // Upload to Drive
            $drive_url = $this->upload_to_drive($pdf_path, $data);
            
            // Save to database
            $this->save_certificate_data($data, $certificate_code, $drive_url);
            
            // Cleanup temporary files
            $this->cleanup_temporary_files();
            
        } catch (Exception $e) {
            $this->logger->log("Error processing certificate for student {$data->student_id}: " . $e->getMessage(), 'error');
            $this->update_certificate_status($data->student_id, $data->course_id, 'failed', $e->getMessage());
        }
    }

    private function generate_certificate_images($data, $code) {
        try {
            // Create base images
            $image1 = imagecreatefrompng($this->config['template_path']['slide1']);
            $image2 = imagecreatefrompng($this->config['template_path']['slide2']);
            $syllabus = imagecreatefrompng($this->config['template_path']['syllabus']);

            if (!$image1 || !$image2 || !$syllabus) {
                throw new Exception('Error loading template images');
            }

            // Define colors
            $colors = [
                'name' => imagecolorallocate($image1, 0, 32, 96),
                'dni' => imagecolorallocate($image1, 53, 55, 68),
                'course' => imagecolorallocate($image1, 7, 55, 99),
                'code' => imagecolorallocate($image1, 66, 66, 66),
                'date' => imagecolorallocate($image1, 53, 55, 68),
                'course2' => imagecolorallocate($image2, 35, 58, 68)
            ];

            // First template text placement
            imagettftext(
                $image1, 30, 0, 350, 325, 
                $colors['name'], 
                $this->config['fonts']['nunito'], 
                $data->nombre_completo
            );

            imagettftext(
                $image1, 14, 0, 675, 382, 
                $colors['dni'], 
                $this->config['fonts']['arimo'], 
                $data->dni
            );

            imagettftext(
                $image1, 25, 0, 430, 438, 
                $colors['course'], 
                $this->config['fonts']['dm_serif'], 
                $data->course_name
            );

            imagettftext(
                $image1, 14, 0, 750, 565, 
                $colors['date'], 
                $this->config['fonts']['arimo'], 
                date('d/m/Y', strtotime($data->ultima_fecha))
            );

            imagettftext(
                $image1, 14, 0, 275, 565, 
                $colors['code'], 
                $this->config['fonts']['dm_serif'], 
                $code
            );

            // Second template text placement
            imagettftext(
                $image2, 39, 0, 360, 100, 
                $colors['course2'], 
                $this->config['fonts']['dm_serif'], 
                $data->course_name
            );

            imagettftext(
                $image2, 36, 0, 220, 200, 
                $colors['course2'], 
                $this->config['fonts']['dm_serif'], 
                'Aprobado'
            );

            imagettftext(
                $image2, 36, 0, 720, 200, 
                $colors['course2'], 
                $this->config['fonts']['nunito'], 
                number_format($data->nota, 1)
            );

            // Add syllabus to second template
            imagecopy($image2, $syllabus, 170, 335, 0, 0, 800, 300);

            // Save images
            $output_path1 = __DIR__ . '/certificates/png/certificado1_edited.png';
            $output_path2 = __DIR__ . '/certificates/png/certificado2_edited.png';

            imagepng($image1, $output_path1);
            imagepng($image2, $output_path2);

            // Cleanup
            imagedestroy($image1);
            imagedestroy($image2);
            imagedestroy($syllabus);

            $this->logger->log("Certificate images generated successfully for student {$data->student_id}");

            return [$output_path1, $output_path2];

        } catch (Exception $e) {
            $this->logger->log("Error generating certificate images: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function generate_signed_pdf() {
        try {
            // Initialize TCPDF
            $pdf = new TCPDF('L', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);

            // Add first page
            $pdf->AddPage();
            $pdf->Image(
                __DIR__ . '/certificates/png/certificado1_edited.png', 
                0, 0, 297, 210
            );

            // Add second page
            $pdf->AddPage();
            $pdf->Image(
                __DIR__ . '/certificates/png/certificado2_edited.png', 
                0, 0, 297, 210
            );

            $certificate =  $this->config['signature']['certificate'];
            $key = $this->config['signature']['key'];
            $password = $this->config['signature']['password'];

            if(!file_exists($certificate)){
                throw new Exception("El archivo del certifcao no existe");
            }
            if(!file_exists($key)){
                throw new Exception("el archivo de la clave privada no existe");
            }


            // Set document signing properties
            $pdf->setSignature(
                $this->config['signature']['certificate'],
                $this->config['signature']['key'],
                $this->config['signature']['password'],
                '',
                2,
                $this->config['signature']['info']
            );

            // Save PDF
            $output_path = __DIR__ . '/certificates/pdf/certificado.pdf';
            $pdf->Output($output_path, 'F');

            $this->logger->log("PDF generated and signed successfully");

            return $output_path;

        } catch (Exception $e) {
            $this->logger->log("Error generating PDF: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function upload_to_drive($pdf_path, $data) {
        try {
            // Initialize Google Client if not already done
            if (!$this->google_client) {
                $this->google_client = new Google_Client();
                $this->google_client->setAuthConfig(__DIR__ . "/credentials.json");
                $this->google_client->addScope(Google_Service_Drive::DRIVE);
            }

            $service = new Google_Service_Drive($this->google_client);

            // Create folder structure: year/course/student_name
            $folder_path = date('Y') . '/' . $data->course_name . '/' . $data->nombre_completo;
            $folder_id = $this->create_folder_structure($service, $folder_path);

            // Prepare file metadata
            $file_metadata = new Google_Service_Drive_DriveFile([
                'name' => "certificado_{$data->dni}.pdf",
                'parents' => [$folder_id]
            ]);

            // Upload file
            $content = file_get_contents($pdf_path);
            $file = $service->files->create($file_metadata, [
                'data' => $content,
                'mimeType' => 'application/pdf',
                'uploadType' => 'multipart',
                'fields' => 'id'
            ]);

            // Set file permissions (public read access)
            $permission = new Google_Service_Drive_Permission([
                'type' => 'anyone',
                'role' => 'reader',
            ]);
            $service->permissions->create($file->id, $permission);

            // Generate download URL
            $download_url = "https://drive.google.com/uc?export=download&id=" . $file->id;
            
            $this->logger->log("Certificate uploaded successfully to Google Drive for student {$data->student_id}");
            
            return $download_url;

        } catch (Exception $e) {
            $this->logger->log("Error uploading to Google Drive: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function create_folder_structure($service, $folder_path) {
        $current_parent = $this->config['root_folder_id'];
        $folders = explode('/', $folder_path);

        foreach ($folders as $folder_name) {
            // Search for existing folder
            $query = "mimeType='application/vnd.google-apps.folder' and name='$folder_name' and '$current_parent' in parents and trashed=false";
            $results = $service->files->listFiles([
                'q' => $query,
                'spaces' => 'drive',
                'fields' => 'files(id, name)'
            ]);

            // Create folder if it doesn't exist
            if (count($results->getFiles()) == 0) {
                $folder_metadata = new Google_Service_Drive_DriveFile([
                    'name' => $folder_name,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => [$current_parent]
                ]);
                $folder = $service->files->create($folder_metadata, ['fields' => 'id']);
                $current_parent = $folder->id;
            } else {
                $current_parent = $results->getFiles()[0]->getId();
            }
        }

        return $current_parent;
    }





    

    private function save_certificate_data($data, $code, $drive_url) {
        global $wpdb;
        $table = $wpdb->prefix . 'certificados_generados';
        
        $wpdb->insert(
            $table,
            [
                'dni' => $data->dni,
                'codigo_unico' => $code,
                'student_id' => $data->student_id,
                'nombre' => $data->nombre_completo,
                'curso' => $data->course_name,
                'course_id' => $data->course_id,
                'nota' => $data->nota,
                'fecha_emision' => current_time('mysql'),
                'emisor' => get_bloginfo('name'),
                'enlace_drive' => $drive_url,
                'status' => 'completed'
            ]
        );
    }

    private function update_certificate_status($student_id, $course_id, $status, $error_message = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'certificados_generados';
        
        $wpdb->update(
            $table,
            [
                'status' => $status,
                'error_message' => $error_message,
                'attempts' => $wpdb->get_var($wpdb->prepare("SELECT attempts + 1 FROM $table WHERE student_id = %d AND course_id = %d", $student_id, $course_id)),
                'last_attempt' => current_time('mysql')
            ],
            [
                'student_id' => $student_id,
                'course_id' => $course_id
            ]
        );
    }

    private function cleanup_temporary_files() {
        $temp_files = glob(__DIR__ . '/certificates/png/*_edited.png');
        foreach ($temp_files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}


// function initialize_certificate_system() {
//     return CertificateSystem::get_instance();
// }

// add_action('plugins_loaded', 'initialize_certificate_system');

// $make = CertificateSystem::get_instance();
// $make->activate();
