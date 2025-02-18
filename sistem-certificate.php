<?php
/**
* Plugin Name: Generador de Certificados
* Description: Un plugin para generar certificados automáticamente.
* Version: 3.0
* Author: Oracle Perú S.A.C.
 */

if (!defined('ABSPATH')) {
    exit;
}



register_activation_hook(__FILE__, function(){
    error_log('Plugin activandose');
    correr_cada_cuatro_horas(wp_get_schedules());
    configurar_cronograma_certificados();
});
register_deactivation_hook(__FILE__, 'eliminar_cronograma_certificados');

//llamar a todo el plugin cada 4 horas
function correr_cada_cuatro_horas($schedules) {
    $schedules['cada_cuatro_horas'] = array(
        'interval' => 120,
        'display' => 'Cada 4 horas'
    );
    return $schedules;
}
add_filter('cron_schedules', 'correr_cada_cuatro_horas');

// configura el cronograma cron
function configurar_cronograma_certificados() {
    error_log('Configurando cronograma para generación de certificados--------');

    if (!wp_next_scheduled('auto_certificate_generation')) {
        $resultado = wp_schedule_event(time(), 'cada_cuatro_horas', 'auto_certificate_generation');
        error_log('Resultado de programacion: '. ($resultado ? 'exitoso' : 'fallido'));
    }
    debug_certificate_cron();
}

// // limpiar el cronogram al desactivar el plu
function eliminar_cronograma_certificados() {
    wp_clear_scheduled_hook('auto_certificate_generation');
}

// hook para la generacion automatica
add_action('auto_certificate_generation', 'process_certificate_generation');

// Agregar estos nuevos hooks
register_activation_hook(__FILE__, 'activate_certificate_plugin');
register_deactivation_hook(__FILE__, 'deactivate_certificate_plugin');

function activate_certificate_plugin() {
    // Ejecutar la generación de certificados inmediatamente al activar
    process_certificate_generation();
    error_log('[Certificate System] Plugin activated and certificates processed');
}

function deactivate_certificate_plugin() {
    error_log('[Certificate System] Plugin deactivated');
}

// Agregar hook para actualizaciones del plugin
add_action('upgrader_process_complete', 'on_plugin_update', 10, 2);

function on_plugin_update($upgrader_object, $options) {
    if ($options['type'] === 'plugin' && $options['action'] === 'update') {
        // Verificar si nuestro plugin está siendo actualizado
        $current_plugin = plugin_basename(__FILE__);
        if (in_array($current_plugin, $options['plugins'])) {
            process_certificate_generation();
            error_log('[Certificate System] Plugin updated and certificates processed');
        }
    }
}


//use Google_Service_Drive;
set_time_limit(450);
class CertificateConfig {
    private $config;

    public function __construct() {
        $this->config = [
            'root_folder_id' => '1hl1XZm3lrUGkPfXkoYowH0mj4-Of9Zuc',
            'min_passing_score' => 15,
            'template_path' => [
                'slide1' => __DIR__ . '/assets/templates/Diapositiva1.png',
                'slide2' => __DIR__ . '/assets/templates/Diapositiva2.png',
                'syllabus_prime_auxi' => __DIR__ . '/assets/templates/syllabus/primeros_auxilios.png',
                'syllabus_trabajos_altura' => __DIR__ . '/assets/templates/syllabus/trabajos_en_altura.png',
                'syllabus_espacios_confinados' => __DIR__ . '/assets/templates/syllabus/espacios_confinados.png',
                'syllabus_riesgo_electrico' => __DIR__ . '/assets/templates/syllabus/riesgo_electrico.png',
                'logo' => __DIR__ . '/assets/logo.png'
            ],
            'fonts' => [
                'nunito' => __DIR__ . '/assets/fonts/Nunito-Italic-VariableFont_wght.ttf',
                'arimo' => __DIR__ . '/assets/fonts/Arimo-Italic-VariableFont_wght.ttf',
                'dm_serif' => __DIR__ . '/assets/fonts/DMSerifText-Regular.ttf'
            ],
            'signature' => [
                'certificate' => __DIR__ . '/assets/digital-signature/public.crt',
                'key' => __DIR__ . '/assets/digital-signature/private.key',
                'password' => 'gutemberg192837465'
            ]
        ];
        // generate table if note exists
        if ($this->table_not_exists()) {
            $this->create_database_tables();
        }else{
            error_log("No se pude crarel la tbla");
        }
    }
    public function table_not_exists() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'certificados_generados';
        $sql = "SHOW TABLES LIKE '$table_name'";
        $results = $wpdb->get_results($sql);
        return count($results) === 0;
    }

    // create functino to create tablle in database
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


    public function get($key) {
        return $this->config[$key] ?? null;
    }


}

class CertificateLogger {
    public function log($message, $level = 'info') {
        error_log("[Certificate System][$level] $message");
    }
}

class CertificateQRGenerator {
    private $config;
    private $logger;

    public function __construct(CertificateConfig $config, CertificateLogger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function generateQRWithLogo($code) {
        $qr_dir = plugin_dir_path(__FILE__) . 'assets/qr-codes/';
        wp_mkdir_p($qr_dir);

        $qr_filename = 'certificate_' . md5($code . time()) . '.png';
        $qr_file = $qr_dir . $qr_filename;
        $url_page_validate = home_url('/validar-certificados/');
        $current_url = add_query_arg('code', urlencode($code), $url_page_validate);
        QRcode::png($current_url, $qr_file, QR_ECLEVEL_H, 15, 2, false);
        
        $QR = imagecreatefrompng($qr_file);
        $QR = imagescale($QR, 200, 200);
        $logo = imagecreatefrompng($this->config->get('template_path')['logo']);
        
        $this->overlayLogoOnQR($QR, $logo);
        
        imagepng($QR, $qr_file);
        imagedestroy($logo);
        imagedestroy($QR);
        
        return $QR;
    }

    private function overlayLogoOnQR(&$QR, $logo) {
        $QR_width = imagesx($QR);
        $QR_height = imagesy($QR);
        $logo_width = imagesx($logo);
        $logo_height = imagesy($logo);
        
        $logo_qr_width = $QR_width/3;
        $scale = $logo_width/$logo_qr_width;
        $logo_qr_height = $logo_height/$scale;
        
        $from_width = ($QR_width - $logo_qr_width)/2;
        $from_height = ($QR_height - $logo_qr_height)/2;
        
        imagecopyresampled($QR, $logo,
            $from_width, $from_height, 0, 0,
            $logo_qr_width, $logo_qr_height, 
            $logo_width, $logo_height
        );
    }
}

class CertificateImageGenerator {
    private $config;
    private $logger;
    private $qrGenerator;

    public function __construct(CertificateConfig $config, CertificateLogger $logger, CertificateQRGenerator $qrGenerator) {
        $this->config = $config;
        $this->logger = $logger;
        $this->qrGenerator = $qrGenerator;
    }

    public function generateCertificateImages($data, $code) {
        try {
            $image_templates = $this->loadTemplates($data);
            $colors = $this->defineColors($image_templates);
            
            $this->renderFirstTemplateText($image_templates['base1'], $data, $colors, $code);
            $this->renderSecondTemplateText($image_templates['base2'], $data, $colors);
            
            $qr_code = $this->qrGenerator->generateQRWithLogo($code);
            
            $this->addQRAndSyllabus($image_templates, $qr_code);
            
            $output_paths = $this->saveImages($image_templates);
            
            $this->cleanupResources($image_templates, $qr_code);
            
            return $output_paths;
        } catch (Exception $e) {
            $this->logger->log("Certificate image generation error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function filter_syllabus($curso){
        $templates_syllabus = [
            'Trabajos en Altura' => 'syllabus_trabajos_altura',
            'Espacios Confinados' => 'syllabus_espacios_confinados',
            'Riesgos Eléctricos' => 'syllabus_riesgo_electrico',
            'Primeros Auxilios' => 'syllabus_prime_auxi'

        ];
        return $templates_syllabus[$curso];

    }

    private function add_one_year_to_actual_date_emision($date){
        $date_object = DateTime::createFromFormat('Y-m-d', $date);
        if(!$date_object){
            return "formato de fecha invalida";
        }

        $date_object->modify('+1 year');
        return $date_object->format('d/m/Y');
    }

    private function loadTemplates($data) {
        $curso = $data->course_name;
        $real_syllabus = $this->filter_syllabus($curso);
        $template_paths = $this->config->get('template_path');
        return [
            'base1' => imagecreatefrompng($template_paths['slide1']),
            'base2' => imagecreatefrompng($template_paths['slide2']),
            'syllabus' => imagecreatefrompng($template_paths[$real_syllabus])
        ];
    }

    private function defineColors($images) {
        return [
            'name' => imagecolorallocate($images['base1'], 0, 32, 96),
            'dni' => imagecolorallocate($images['base1'], 53, 55, 68),
            'course' => imagecolorallocate($images['base1'], 7, 55, 99),
            'code' => imagecolorallocate($images['base1'], 66, 66, 66),
            'date' => imagecolorallocate($images['base1'], 53, 55, 68),
            'course2' => imagecolorallocate($images['base2'], 35, 58, 68)
        ];
    }

    private function renderFirstTemplateText($image, $data, $colors, $code) {
        $fonts = $this->config->get('fonts');
        $dni = 0;
        if(!$data->dni){
            $dni = 12345678;
        }else{
            $dni = $data->dni;
        }

        $text_configs = [
            ['text' => $data->nombre_completo, 'x' => 350, 'y' => 325, 'size' => 30, 'font' => $fonts['nunito'], 'color' => $colors['name']],
            ['text' => $dni, 'x' => 420, 'y' => 382, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['dni']],
            ['text' => $data->course_name, 'x' => 430, 'y' => 455, 'size' => 25, 'font' => $fonts['dm_serif'], 'color' => $colors['course']],
            ['text' => date('d/m/Y', strtotime($data->ultima_fecha)), 'x' => 750, 'y' => 570, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['date']],
            ['text' => $code, 'x' => 275, 'y' => 565, 'size' => 14, 'font' => $fonts['dm_serif'], 'color' => $colors['code']]
        ];

        foreach ($text_configs as $config) {
            imagettftext(
                $image, 
                $config['size'], 
                0, 
                $config['x'], 
                $config['y'], 
                $config['color'], 
                $config['font'], 
                $config['text']
            );
        }
    }

    private function renderSecondTemplateText($image, $data, $colors) {
        $fonts = $this->config->get('fonts');
        $current_date = date('Y-m-d', strtotime($data->ultima_fecha));
        $date_expiration = $this->add_one_year_to_actual_date_emision($current_date);
        $dni = 0;
        if(!$data->dni){
            $dni = 12345678;
        }else{
            $dni = $data->dni;
        }

        $text_configs = [
            ['text' => $data->course_name, 'x' => 360, 'y' => 100, 'size' => 39, 'font' => $fonts['dm_serif'], 'color' => $colors['course2']],
            ['text' => 'Aprobado', 'x' => 220, 'y' => 200, 'size' => 36, 'font' => $fonts['dm_serif'], 'color' => $colors['course2']],
            ['text' => number_format($data->nota, 1), 'x' => 720, 'y' => 200, 'size' => 36, 'font' => $fonts['nunito'], 'color' => $colors['course2']],
            ['text' => $data->nombre_completo, 'x' => 170, 'y' => 320, 'size' => 30, 'font' => $fonts['nunito'], 'color' => $colors['name']],
            ['text' => $dni, 'x' => 710, 'y' => 320, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['dni']],
            ['text' => $date_expiration, 'x' => 90, 'y' => 755, 'size' => 14, 'font' => $fonts['arimo'], 'color' => $colors['dni']]
        ];

        foreach ($text_configs as $config) {
            imagettftext(
                $image, 
                $config['size'], 
                0, 
                $config['x'], 
                $config['y'], 
                $config['color'], 
                $config['font'], 
                $config['text']
            );
        }
    }

    private function addQRAndSyllabus($templates, $qr_code) {
        imagecopy($templates['base1'], $qr_code, 440, 585, 0, 0, 200, 200);
        imagecopy($templates['base2'], $templates['syllabus'], 170, 335, 0, 0, 800, 300);
    }

    private function saveImages($templates) {
        $output_paths = [
            __DIR__ . '/certificates/png/certificado1_edited.png',
            __DIR__ . '/certificates/png/certificado2_edited.png'
        ];
        
        imagepng($templates['base1'], $output_paths[0]);
        imagepng($templates['base2'], $output_paths[1]);

        return $output_paths;
    }

    private function cleanupResources($templates, $qr_code) {
        foreach ($templates as $image) {
            imagedestroy($image);
        }
        imagedestroy($qr_code);
    }
}

class CertificateDriveUploader {
    private $config;
    private $logger;
    private $google_client;

    public function __construct(CertificateConfig $config, CertificateLogger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function uploadToDrive($file_path, $certificateData) {
        // TODO: Implement Google Drive upload logic
        try {
            // Initialize Google Client if not already done
            if (!$this->google_client) {
                $this->google_client = new Google_Client();
                $this->google_client->setAuthConfig(__DIR__ . "/credentials.json");
                $this->google_client->addScope(Google_Service_Drive::DRIVE);
            }

            $service = new Google_Service_Drive($this->google_client);

            // Create folder structure: year/course/student_name
            $folder_path = date('Y') . '/' . $certificateData->course_name . '/' . $certificateData->nombre_completo;
            $folder_id = $this->create_folder_structure($service, $folder_path);

            // Prepare file metadata
            $file_metadata = new Google_Service_Drive_DriveFile([
                'name' => "certificado_{$certificateData->dni}.pdf",
                'parents' => [$folder_id]
            ]);

            // Upload file
            $content = file_get_contents($file_path);
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
            
            $this->logger->log("Certificate uploaded successfully to Google Drive for student {$certificateData->student_id}");
            
            return $download_url;

        } catch (Exception $e) {
            $this->logger->log("Error uploading to Google Drive: " . $e->getMessage(), 'error');
            throw $e;
        }
}


    private function create_folder_structure($service, $folder_path) {
    

        $current_parent = $this->config->get('root_folder_id');
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



}
class CertificatePDFGenerator {
    private $config;
    private $logger;

    public function __construct(CertificateConfig $config, CertificateLogger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function generateSignedPDF($certificate_images) {
        try {
            $pdf = new TCPDF('L', 'mm', 'A4');
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);

            // Add first page
            $pdf->AddPage();
            $pdf->Image($certificate_images[0], 0, 0, 297, 210);

            // Add second page
            $pdf->AddPage();
            $pdf->Image($certificate_images[1], 0, 0, 297, 210);

            // Set document signing properties
            $pdf->setSignature(
                $this->config->get('signature')['certificate'],
                $this->config->get('signature')['key'],
                $this->config->get('signature')['password']
            );

            $output_path = __DIR__ . '/certificates/pdf/certificado.pdf';
            $pdf->Output($output_path, 'F');

            $this->logger->log("PDF generated and signed successfully");
            return $output_path;

        } catch (Exception $e) {
            $this->logger->log("PDF generation error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
}

class CertificateManager {
    private $config;
    private $logger;
    private $imageGenerator;
    private $pdfGenerator;
    private $driveUploader;

    public function __construct() {
        $this->config = new CertificateConfig();
        $this->logger = new CertificateLogger();
        $qrGenerator = new CertificateQRGenerator($this->config, $this->logger);
        $this->imageGenerator = new CertificateImageGenerator($this->config, $this->logger, $qrGenerator);
        $this->pdfGenerator = new CertificatePDFGenerator($this->config, $this->logger);
        $this->driveUploader = new CertificateDriveUploader($this->config, $this->logger);
    }

    public function processCertificate($certificateData) {
        try {
            $code = wp_generate_uuid4();
            $certificate_images = $this->imageGenerator->generateCertificateImages($certificateData, $code);
            $pdf_path = $this->pdfGenerator->generateSignedPDF($certificate_images);
            $drive_url = $this->driveUploader->uploadToDrive($pdf_path, $certificateData);
            
            $this->saveCertificateToDatabase($certificateData, $code, $drive_url);
            
            $this->cleanupTemporaryFiles($certificate_images, $pdf_path);
        } catch (Exception $e) {
            $this->logger->log("Certificate processing error: " . $e->getMessage(), 'error');
        }
    }

    private function saveCertificateToDatabase($data, $code, $drive_url) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'certificados_generados',
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

    private function cleanupTemporaryFiles($certificate_images, $pdf_path) {
        foreach ($certificate_images as $image) {
            if (file_exists($image)) {
                unlink($image);
            }
        }
        if (file_exists($pdf_path)) {
            unlink($pdf_path);
        }
    }
}

// Function to get pending certificates from database
function get_pending_certificates() {
    global $wpdb;
    return $wpdb->get_results(
        "
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
        "
    );
}

// Integration hook
function process_certificate_generation() {
    $certificate_manager = new CertificateManager();
    $certificate_data = get_pending_certificates();

    if(empty($certificate_data)){
        error_log('[Certificate System] no hay certififacdos pendientes por hacer');
        return;
    }
    error_log('[Certificate System] procesando certificados pendientes' . count($certificate_data) . 'certificados');
    
    foreach ($certificate_data as $certificate) {
        try{
            $certificate_manager->processCertificate($certificate);
        } catch (Exception $e) {
            error_log('[Certificate System] error al procesar certificado: ' . $e->getMessage());
        }
    }
 }






// add_action('certificate_generation_hook', 'process_certificate_generation');

// if (defined('WP_DEBUG') && WP_DEBUG) {
  
//     add_action('admin_notices', function() {
//         if (isset($_GET['certificates_generated'])) {
//            
 //             <div class="notice notice-success">
//                 <p>Certificates generation process completed!</p>
//             </div>
//             
//         }
//     });

  
//     add_action('admin_init', function() {
//         if (current_user_can('manage_options')) {
//             do_action('certificate_generation_hook');
//             wp_redirect(add_query_arg('certificates_generated', '1'));
//             exit;
//         }
//     });
// }
$prueba_data = get_pending_certificates();
echo '<pre>';
print_r($prueba_data);
echo '</pre>';

//Función de utilidad para verificar el próximo evento programado
function check_next_certificate_generation() {
    $next_scheduled = wp_next_scheduled('auto_certificate_generation');
    if ($next_scheduled) {
        return 'Próxima generación de certificados programada para: ' . date('Y-m-d H:i:s', $next_scheduled);
    }
    return 'No hay generación de certificados programada.';
}

// // Para mostrar el estado
add_action('admin_notices', function() {
    echo '<div class="notice notice-info"><p>' . check_next_certificate_generation() . '</p></div>';
});


// // función de diagnóstico
function debug_certificate_cron() {
    // 1. Verificar si el cron está registrado
    $crons = _get_cron_array();
    error_log('Crons programados: ' . print_r($crons, true));

    // 2. Verificar si nuestro intervalo está registrado
    $schedules = wp_get_schedules();
    error_log('Intervalos disponibles: ' . print_r($schedules, true));

    // 3. Verificar próxima ejecución
    $next = wp_next_scheduled('auto_certificate_generation');
    error_log('Próxima ejecución programada: ' . ($next ? date('Y-m-d H:i:s', $next) : 'No programada'));

    // 4. Verificar hooks registrados
    global $wp_filter;
    error_log('Hooks registrados para auto_certificate_generation: ' . 
        (isset($wp_filter['auto_certificate_generation']) ? 'Sí' : 'No'));
}

